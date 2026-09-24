# Spec: HashiCorp Vault provider example

Status: planned. Part of the pre-Trac work in
[ADR 0008](../../docs/decisions/0008-the-trac-ticket-replaces-thread-confirmation.md). Builds on
the examples test harness in [the KMS keyring spec](../aws-kms-keyring/SPEC.md#5-an-examples-test-harness),
so it comes second.

## Why this example

The AWS Secrets Manager example showed that the two-slot version model maps onto a backend
already built around two slots (`AWSCURRENT`/`AWSPREVIOUS`). That is agreement from a backend
that was going to agree. Vault's KV v2 engine numbers versions 1, 2, 3 and so on, keeps up to
`max_versions` of them, and can soft-delete or destroy any one of them. It is the first backend
where `WP_Secret_Version::CURRENT`/`PREVIOUS` is a translation rather than a match, which makes
it the test of the design most likely to be wrong in a way nobody has pointed out.

It is also the first provider whose backend is self-hostable, so CI can run it against the real
server rather than an emulator.

## The questions it has to answer

1. **What is "previous" when the backend keeps ten versions?** It has to be exactly version N-1,
   never "the newest surviving version below N". Otherwise retiring N-1 would promote N-2, and
   `wp_retire_secret_version()`, meant to make a compromised credential unreachable, would bring
   back an even older one.
2. **What happens to the versions the API cannot see?** KV v2 keeps up to 10 by default. The
   shipped provider throws away the old previous value on every write, but Vault would keep N-2
   and older, still readable by anyone with a Vault token. The answer this spec adopts is to set
   `max_versions: 2` on every secret the provider creates, which makes Vault a two-slot store. If
   that turns out to be wrong in practice, it is a finding about the version model and goes on the
   Trac ticket.
3. **Where does `needs_rotation` live** on a backend with no field for it?
4. **What does `list_secrets()` cost** on a remote backend, given the shape the interface requires?

## Deliverable 1: `examples/vault-provider/secrets.php`

A single-file drop-in: no Composer, no SDK. Vault's HTTP API needs only a token header, so this
is simpler than the AWS examples.

`final class Vault_KV2_Provider implements WP_Secrets_Provider`, configured by
`WP_SECRETS_VAULT_ADDR`, `WP_SECRETS_VAULT_TOKEN`, `WP_SECRETS_VAULT_MOUNT` (default `secret`),
and an optional `WP_SECRETS_VAULT_NAMESPACE`, which is sent as `X-Vault-Namespace` for Vault
Enterprise and HCP. It is installed only when the address and token are non-empty, for the same
reason as the other examples.

### Path mapping

| Scope | Vault path under the mount |
|---|---|
| Site | `wp/site/<blog_id>/<namespace>/<key>` |
| Network | `wp/network/<namespace>/<key>` |

Site scope includes the blog ID because the shipped provider's site scope is per site: the option
store writes through `get_option()`, which reads the current blog's table. See also deliverable 3.
Names are `namespace/key` with one slash, and both segments match `[a-z0-9_-]`, so they map to
Vault paths unchanged.

### Method mapping

| Method | Vault calls | Notes |
|---|---|---|
| `get( CURRENT )` | `GET data/<path>` | 404, or a current version that is soft-deleted or destroyed, is `null`. Value is `data.data.value`. |
| `get( PREVIOUS )` | `GET metadata/<path>`, then `GET data/<path>?version=N-1` | Strictly N-1. If N is 1, or N-1 is deleted or destroyed, the result is `null`, never an older version. |
| `set()` | on create: `POST metadata/<path>` with `max_versions: 2`; then `POST data/<path>` with `{ "data": { "value": … } }`; then `custom_metadata` if the flag changed | Created versus updated comes from whether metadata existed. Fires `wp_secret_changed` as the interface requires. |
| `delete()` | `DELETE metadata/<path>` | Removes every version for good. Vault answers 204 whether or not the secret existed, which already matches "absent is success". |
| `retire_previous()` | `GET metadata/<path>`, then `POST destroy/<path>` with `{ "versions": [N-1] }` | Destroy, not soft delete: a soft-deleted version can be undeleted, and retire means gone. No previous version is a successful no-op. |
| `list_secrets()` | `LIST metadata/wp/<scope>/` for namespaces, then `LIST` each one, then `GET metadata` per secret | `created` is `created_time`, `has_previous` applies the same N-1 rule as `get`, and `needs_rotation` comes from `custom_metadata`. The fingerprint is `''`, as in the AWS example. |
| `get_label()` | none | `HashiCorp Vault (<addr>, mount <mount>)` |
| `get_protection_boundary()` | none | `BOUNDARY_PROVIDER` |
| `is_writable()` | none | `true`. A token without write policy surfaces as a `WP_Error` on `set()`. It is not detected in advance, and the README says so. |

**`needs_rotation`** is stored as `custom_metadata.needs_rotation = "1"`, which needs Vault 1.9 or
later. The flag is per secret rather than per version, so every `set()` writes it, and a set without
the flag clears it. If the flag write fails after the value write succeeded, and the caller asked
for the flag, `set()` returns `WP_Error`, because the interface says a provider "must not report it
as honored". If the caller did not ask for the flag, a failed clear is logged and ignored. The data
write and the metadata write are two requests, not a transaction, and the file says so.

**Errors.** Vault's `errors[]` array goes into the `WP_Error` message, following the lesson in the
AWS example. A 403 and a sealed Vault (503) both become `WP_SECRETS_ERROR_STORE_UNAVAILABLE`, so
they read as unreachable, not absent.

**Caching** is request-scoped only, the same rule and the same reasoning as the AWS example.

**Fingerprints** still derive from the site master key, so a site whose values live entirely in
Vault still needs a working keyring and root key. That is inherited from the AWS example. It stays
as-is and is written down as a question: a provider reporting `BOUNDARY_PROVIDER` still depends on
local key material for one feature.

## Deliverable 2: tests

In `examples/vault-provider/tests/`, run by `make test-examples`:

- `WP_Secrets_Provider_Conformance` against a real Vault dev server.
- Provider-specific tests:
  - **Retiring does not resurrect.** Write v1, v2, v3, retire, then `PREVIOUS` is `null`. Write
    v4, and `PREVIOUS` is v3.
  - **Only two versions are kept.** After three writes, version 1 is gone from Vault itself, read
    directly rather than through the provider.
  - **`needs_rotation` round-trips,** set, cleared, and shown in `list_secrets()`.
  - **Site scope is isolated per blog** on multisite. Run this under the multisite config.
  - **A sealed or unreachable Vault reads as `WP_Error`,** never `null`.

The CI `examples` job gains a Vault service container in dev mode (`hashicorp/vault`, pinned by
digest, root token passed through `VAULT_DEV_ROOT_TOKEN_ID`), where KV v2 is mounted at `secret/`
by default. Vault has been under the BSL since 1.15. The README notes that OpenBao implements the
same KV v2 API. CI tests Vault, the name hosts will search for, and one manual OpenBao run is
recorded in the commit message.

## Deliverable 3: fix site-scope naming in the AWS Secrets Manager example

`AWS_Secrets_Manager_Provider::aws_name()` maps site scope to `wp/<name>` with no blog ID, so on
multisite every site reads and writes the same AWS secret for a given name. The shipped provider
keeps site scope per site, so the example is wrong, not the interface. Map site scope to
`wp/site/<blog_id>/<name>`, as Vault does. The README needs a note: existing single-site
deployments of the example move from `wp/<name>` to `wp/site/1/<name>`, so this is a rename on
AWS's side. Before 1.0 the example gets no compatibility read.

This found its way into this spec because working out Vault's paths is what turned it up. It is a
separate commit.

## Out of scope

- Vault auth methods other than a static token. AppRole and Kubernetes auth are named in the
  README as the production path.
- KV v1 and the dynamic-secret engines. Dynamic database credentials do not fit a stored-secret
  API, and trying to make them fit is how an example turns into a product.
- Check-and-set (`cas`) on writes. Worth a sentence in the README as the answer to concurrent
  writers, but not implemented.

## Done when

- Deliverables 1 to 3 are merged, and `make ci` and the `examples` CI job are green on single site
  and multisite.
- Each of the four questions above has a written answer in the example's README. Any answer that
  points at the interface rather than the example is added to the open questions and to the Trac
  ticket description.
- `proposal-questions.md` question 2, on whether two slots are adequate, records what Vault showed.
