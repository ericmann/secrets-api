# HashiCorp Vault KV v2 provider

A `wp-content/secrets.php` drop-in that makes a Vault KV v2 secrets engine the system of record for
a site's credentials. WordPress becomes a consumer rather than a custodian, and `wp secret dropin`
reports `Encryption boundary: the provider (outside WordPress)`.

**No Composer, no Vault SDK.** One `wp_remote_request()` call per operation against Vault's HTTP
API, in a single file you can read end to end.

## Where the credentials go

**Not `.wp-env.json`** — that file is committed. Use `.wp-env.override.json`, which wp-env merges
on top and which this repo git-ignores:

```jsonc
// .wp-env.override.json  (repo root, git-ignored)
{
  "config": {
    "WP_SECRETS_VAULT_ADDR":      "http://host.docker.internal:8201",
    "WP_SECRETS_VAULT_TOKEN":     "dev-root",
    "WP_SECRETS_VAULT_MOUNT":     "secret",
    "WP_SECRETS_VAULT_NAMESPACE": ""
  }
}
```

Anything under `config` becomes a PHP constant in `wp-config.php`. `WP_SECRETS_VAULT_MOUNT`
defaults to `secret` and `WP_SECRETS_VAULT_NAMESPACE` is optional (Vault Enterprise / HCP only) —
both can be omitted from the override file entirely. From inside wp-env, the dev server started
per "Run the tests" below is reachable at `http://host.docker.internal:8201`, not `127.0.0.1:8200`
— that address is the *host's* view of the container, not the WordPress container's.

```sh
npx @wordpress/env start     # re-reads the config and rewrites wp-config.php
```

On a real site these are ordinary `wp-config.php` constants, backed by a token from a real auth
method — see "Known limits" below.

## Install the drop-in

```sh
CID=$(docker ps --format '{{.Names}}' | grep -- '-cli-1' | grep -v tests)
docker cp examples/vault-provider/secrets.php "$CID":/var/www/html/wp-content/secrets.php
docker exec "$CID" wp secret dropin
```

Expected once the constants are set:

```
Drop-in active: yes
Provider: Vault_KV2_Provider
Protected by: HashiCorp Vault (http://host.docker.internal:8201, mount secret)
Encryption boundary: the provider (outside WordPress)
Accepts writes: yes
```

To take it back out — **and do this before running the test suite**:

```sh
for c in $(docker ps --format '{{.Names}}' | grep -E 'cli-1|wordpress-1'); do
  docker exec "$c" rm -f /var/www/html/wp-content/secrets.php
done
```

**The gotcha:** wp-env's dev and tests environments see the same `wp-content`, so an installed
drop-in is in front of PHPUnit too. A drop-in that cannot reach Vault will fail most of the suite,
which looks alarming and is not a code problem. Remove it, re-run, and it is green again. Removing
it from a single container is not enough — the loop above covers all four.

## Vault policy

The smallest policy that runs everything below:

```hcl
path "secret/data/wp/*" {
  capabilities = ["create", "update", "read", "delete"]
}
path "secret/metadata/wp/*" {
  capabilities = ["create", "update", "read", "delete", "list"]
}
path "secret/destroy/wp/*" {
  capabilities = ["update"]
}
```

`is_writable()` returns `true` regardless of what the token can actually do — a token without
write policy is not detected in advance. It surfaces the first time `set()`, `delete()`, or
`retire_previous()` runs and Vault returns 403, which the provider maps to `WP_Error`, same as a
sealed Vault.

## Naming

| Scope | Vault path under the mount |
|---|---|
| Site | `wp/site/<blog_id>/<namespace>/<key>` |
| Network | `wp/network/<namespace>/<key>` |

Site scope includes the blog ID because the shipped provider's site scope is per site — the option
store writes through `get_option()`, which reads the current blog's table. `acme/stripe-key`
becomes `wp/site/1/acme/stripe-key` on a single site or blog 1, and `wp/site/<blog_id>/acme/...`
elsewhere on a network. WordPress names are `namespace/key`, one slash, both segments matching
`[a-z0-9_-]`, so they map to Vault paths unchanged.

## The four questions

### 1. What "previous" is

Strictly version N-1, never "the newest surviving version below N." Retiring destroys N-1 and
never promotes N-2 into its place — if it did, `wp_retire_secret_version()`, meant to make a
compromised credential unreachable, would instead bring back an even older one.
`test_previous_is_strictly_n_minus_1_even_when_older_versions_survive` proves it: `max_versions`
is raised to 10 through the test helper first, so pruning cannot be what's producing the result,
three versions are written, `retire_previous()` runs through the provider, `PREVIOUS` reads as
`null`, and version 1 — the older survivor — still reads `200` directly against Vault.

### 2. The versions the API cannot see

KV v2 keeps up to 10 versions by default; the interface exposes exactly two. This provider sets
`max_versions: 2` on every secret it creates, which makes Vault a two-slot store from its first
write. A secret created outside the provider — by `vault kv put` directly, or by an older policy —
keeps whatever `max_versions` it already had, so a pre-existing Vault secret can still hold
versions WordPress cannot see or retire. If that turns out to matter in practice it is a finding
about the version model, not a bug in this example, and goes on the Trac ticket. See
[ADR 0009](../../docs/decisions/0009-cap-a-many-version-backend-to-two-slots.md).

### 3. Where `needs_rotation` lives

In `custom_metadata.needs_rotation`, as the string `"1"` (set) or `"0"` (cleared) — never omitted,
because Vault replaces `custom_metadata` wholesale on every write. The flag write merges the
existing `custom_metadata` (read first, in the same request cycle) with the new flag value before
posting, so other keys a different tool set survive, and "no flag" and "flag cleared" still have
to be the same write rather than an omitted key. This needs Vault 1.9 or later. The value
write and the metadata write are two separate requests, not a transaction: if the value lands and
the flag write fails, `set()` returns `WP_Error` when the caller asked for the flag (the value is
stored, but the flag is not, and the interface says a provider must not report an unhonoured flag
as honoured), and silently logs and ignores a failed *clear*.

### 4. What `list_secrets()` costs

One `LIST` to enumerate namespaces under the scope, one `LIST` per namespace to enumerate secrets,
and one metadata `GET` per secret found — never a data read. Fingerprints come back blank, the
same choice the AWS example makes, because fingerprinting every entry would mean a value read per
secret; `wp secret get` reports the real fingerprint for one secret at a time.

## Known limits

- **Static token only.** Fine for a dev server; a production deployment should use AppRole or
  Kubernetes auth instead, with a short-lived token refreshed outside this file.
- **No `cas` (check-and-set) on writes.** The answer to two writers racing on the same secret, not
  implemented here.
- **KV v1 and the dynamic-secret engines are out of scope.** Dynamic database credentials do not
  fit a stored-secret API, and trying to make them fit is how an example turns into a product.
- **Caching is request-scoped only**, deliberately. Never put a plaintext in the persistent object
  cache: `WP_Secret` cannot round-trip one through `wp_cache_set()`, and caching the raw value
  beside it would quietly undo that.
- **Fingerprints still need this site's own root key**, even though the protection boundary is
  Vault. That is inherited from the AWS example rather than fixed here, and is written down as a
  question rather than a promise: a provider reporting `BOUNDARY_PROVIDER` still depends on local
  key material for one feature.
- **`wp_secret_changed` carries blank fingerprints** for the same reason listing does — this
  provider never fingerprints without a value already in hand.

## OpenBao

[OpenBao](https://openbao.org/) is the Linux Foundation fork of Vault, and implements the same
KV v2 HTTP API this provider speaks — nothing here is Vault-specific beyond the path shapes above.
Vault has been under the Business Source License since 1.15, so it is not itself open source; CI
tests Vault because it is the name hosts will search for, and one manual run against OpenBao is a
human check whose result is recorded in a commit message rather than run in CI.

## Run the tests

```sh
docker run -d --name secrets-api-vault -p 8201:8200 -e VAULT_DEV_ROOT_TOKEN_ID=dev-root --cap-add=IPC_LOCK hashicorp/vault@sha256:47f14a6acb98f48d798a07df7c83f23a6e636e1cf724c5f8ff165cb32667a1e2
```

Then, from the repository root, inside wp-env:

```sh
npx @wordpress/env run --env-cwd="wp-content/plugins/$(basename "$PWD")" tests-cli env VAULT_ADDR=http://host.docker.internal:8201 VAULT_TOKEN=dev-root vendor/bin/phpunit -c phpunit-examples.xml.dist
npx @wordpress/env run --env-cwd="wp-content/plugins/$(basename "$PWD")" tests-cli env WP_MULTISITE=1 VAULT_ADDR=http://host.docker.internal:8201 VAULT_TOKEN=dev-root vendor/bin/phpunit -c phpunit-examples.xml.dist
```

`VAULT_ADDR` and `VAULT_TOKEN` tell the test harness (`Vault_Test_Server`) which server to run the
conformance suite and the provider-specific tests against; both default to
`127.0.0.1:8200` / `dev-root` if unset, which only works when the test runner and Vault are on the
same host network. A host with its own PHPUnit setup outside wp-env can instead run
`make test-examples`, which runs the same suite against `VAULT_ADDR`/`VAULT_TOKEN` from its own
environment, single site then multisite.
