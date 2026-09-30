---
title: "Platform examples"
description: "The three worked secrets.php drop-ins, AWS KMS, AWS Secrets Manager, and HashiCorp Vault, what each one demonstrates, and which to start from."
---

# Platform examples

[`examples/`](../../examples/) holds three working `wp-content/secrets.php` drop-ins that connect
the API to real infrastructure. None of them is loaded by the plugin. You copy one, read it, and
adapt it. Each is a single PHP file with no Composer dependencies and no SDK: AWS requests are
signed with a hand-written SigV4, and Vault is called over plain HTTP with `wp_remote_request()`.
The point is that a host can read a drop-in end to end before trusting it with credentials.

All three pass the conformance suites in `make test-examples`. The AWS examples run against
[Moto](https://github.com/getmoto/moto), an AWS emulator. The Vault example runs against a real
Vault dev server, on both single site and multisite.

| Example | Implements | Who holds what | Site Health boundary |
|---|---|---|---|
| [AWS KMS keyring](../../examples/aws-kms-keyring/README.md) | `WP_Secrets_Keyring` | KMS holds the key; WordPress holds encrypted secrets | WordPress |
| [AWS Secrets Manager](../../examples/aws-secrets-manager/README.md) | `WP_Secrets_Provider` | AWS holds the secrets | The provider |
| [HashiCorp Vault KV v2](../../examples/vault-provider/README.md) | `WP_Secrets_Provider` | Vault holds the secrets | The provider |

## Keyring or provider?

Decide this before anything else. **A key-management service is not a secret store.**

- If the service holds **keys** (AWS KMS, Google Cloud KMS, an HSM, a host's own key service),
  write a keyring. It wraps one 32-byte root key and nothing else. Secrets stay in the options
  tables, encrypted by WordPress, and the service is called once per request.
- If the service holds **secrets** (Secrets Manager, Parameter Store, Google Secret Manager,
  Vault KV, a hosting panel's credential store), write a provider. WordPress hands the value to
  the service and reads it back, and becomes a consumer of the credential instead of its custodian.

The mistake to avoid is writing a provider on top of KMS. That makes one KMS call per secret read,
hits the 4,096-byte payload limit on anything larger than a token, and pays per operation for
encryption WordPress already does locally.

[host-managed-keys.md](host-managed-keys.md) applies the same choice to a hosting platform that
wants to own the site's key.

## AWS KMS keyring

[`examples/aws-kms-keyring/`](../../examples/aws-kms-keyring/README.md)

This moves custody of the root key to an AWS KMS key and leaves everything else alone. `wrap()` is
one `Encrypt` call and `unwrap()` is one `Decrypt` call. `wp secret dropin` then reports
`Protected by: WordPress (libsodium), key source: AWS KMS key <id> in <region>`.

What to take from it:

- **Adopting an existing site** is two steps in one maintenance window: install the drop-in, then
  run `wp secret rotate --from=config`. Wrapped values carry a `kms1:` prefix. Between the two
  steps, a read fails with an error naming the command to run, not an opaque KMS exception.
- **`KeyId` is pinned on `Decrypt`**, so a wrapped value swapped in from another key this IAM
  role can use does not decrypt.
- **The encryption context is fixed**, not tied to the site URL, so a domain change cannot make
  the root key unrecoverable.
- **Timeouts are 3 seconds and fail closed.** A KMS outage turns reads into `WP_Error`; it never
  hangs the request or returns an empty value.
- **KMS rotates the key with no action from WordPress.** Automatic key rotation keeps the key ID
  stable and still decrypts older ciphertext, so no re-wrap is needed.
- **IAM:** `kms:Encrypt` and `kms:Decrypt` on the one key. `kms:Decrypt` is the sensitive one:
  any principal holding it can unwrap the root key.

Limits: static credentials rather than an instance role, one region, and no move between two
different KMS keys.

## AWS Secrets Manager provider

[`examples/aws-secrets-manager/`](../../examples/aws-secrets-manager/README.md)

This makes Secrets Manager the system of record. Names map across under a scope prefix:
`acme/stripe-key` becomes `wp/site/<blog_id>/acme/stripe-key`, and network secrets become
`wp-network/acme/stripe-key`. One IAM resource pattern, `secret:wp/*`, therefore confines a site
to its own secrets.

What to take from it:

- **The version model maps directly.** Secrets Manager's `AWSCURRENT` and `AWSPREVIOUS` staging
  labels are `WP_Secret_Version::CURRENT` and `::PREVIOUS`, and `PutSecretValue` moves the labels
  on AWS's side. The API's two-slot model needed no emulation here.
- **Read-only is a supported shape.** Remove the write permissions from the IAM policy and make
  `is_writable()` return `false`. WordPress then stops offering writes instead of failing them.
- **Caching is request-scoped only.** A provider must never put a plaintext in the persistent
  object cache.

Limits: `list_secrets()` returns blank fingerprints, since each one would cost a `GetSecretValue`
call. `retire_previous()` does nothing and reports success, because AWS owns the staging labels.
There is no pagination past 100 secrets, and credentials are static.

## HashiCorp Vault KV v2 provider

[`examples/vault-provider/`](../../examples/vault-provider/README.md)

This makes a Vault KV v2 mount the system of record, at `wp/site/<blog_id>/<namespace>/<key>` and
`wp/network/<namespace>/<key>`. It also works against [OpenBao](https://openbao.org/), which
speaks the same HTTP API.

Vault was the first backend that did not share the API's two-slot shape, so this example settles
questions the other two never raised:

- **What `PREVIOUS` means.** Strictly version N-1. Retiring destroys N-1 and never promotes N-2
  into its place, because retiring a compromised credential must not bring back an older one.
  [ADR 0010](../decisions/0010-cap-a-many-version-backend-to-two-slots.md) records the decision.
- **The versions the API cannot see.** The provider sets `max_versions: 2` on every secret it
  creates. A secret created outside WordPress keeps its own setting, and can hold versions
  WordPress can neither see nor retire.
- **Where `needs_rotation` lives.** In `custom_metadata`, merged on every write because Vault
  replaces that field wholesale. This needs Vault 1.9 or later.
- **What listing costs.** Metadata reads only, never a data read, so fingerprints are blank the
  same way as in the AWS example.
- **Retiring is a `destroy`, not a soft delete.** A soft-deleted version can be undeleted, and
  retiring is meant to be permanent.

Limits: a static token (production should use AppRole or Kubernetes auth), no check-and-set on
writes, and no support for KV v1 or dynamic-secret engines.

## Writing your own

1. Start from [`drop-in-example.php`](drop-in-example.php) for the skeleton, and from whichever
   example above is closest to your backend.
2. Read the contract you are implementing in [extension-points.md](../spec/extension-points.md).
3. Extend `WP_Secrets_Keyring_Conformance` or `WP_Secrets_Provider_Conformance` and run it
   against a test instance of your backend. The suites check what an `implements` clause cannot:
   absence reported as `null`, an unreachable backend failing closed, non-deterministic wrapping,
   and tampered input rejected.
4. Remove your drop-in from every wp-env container before running the plugin's own test suite.
   Dev and tests share `wp-content`, so a drop-in that cannot reach its backend fails most of the
   suite. The example READMEs have a one-line loop that removes it.
