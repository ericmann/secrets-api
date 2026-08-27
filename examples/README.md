# Platform bindings

Runnable examples of wiring this API to a cloud provider. **Nothing here is loaded by the plugin**
— these are reference implementations you copy into a `wp-content/secrets.php` drop-in, and they
are excluded from `make ci` so their SDK dependencies never become the plugin's.

Intended to become a submodule once there is more than one, which is why this sits at the top
level rather than under `docs/`.

## Pick the right seam first

This is the thing to get right before writing any code, because the obvious choice is usually the
wrong one. **A key-management service is not a secret store**, and the two map to different
extension points:

| Service | Holds | Implement | Effort |
|---|---|---|---|
| **AWS KMS** | keys | `WP_Secrets_Keyring` | 3 methods |
| **Google Cloud KMS** | keys | `WP_Secrets_Keyring` | 3 methods |
| **AWS Secrets Manager** | secrets | `WP_Secrets_Provider` | 8 methods |
| **Google Secret Manager** | secrets | `WP_Secrets_Provider` | 8 methods |
| **AWS Parameter Store** | secrets | `WP_Secrets_Provider` | 8 methods |

If you reach for KMS and start writing a `WP_Secrets_Provider`, you will end up making one KMS
call per secret read, hitting the 4,096-byte payload ceiling on anything larger than a token, and
paying per-operation for work WordPress already does locally. That is not what KMS is for. AWS
says so directly in its own `Encrypt` documentation: *"You don't need to use the `Encrypt`
operation to encrypt a data key."*

## Why a KMS keyring is the good first example

The root key is **32 bytes**, and it is the only wrapped value on the site. So a KMS keyring is
about as small as a meaningful integration gets:

- `wrap( $key_material )` → one `Encrypt` call. 32 bytes, nowhere near the 4,096-byte limit, so
  no envelope of your own is required — WordPress already did that part.
- `unwrap( $wrapped )` → one `Decrypt` call.
- `get_key_source()` → a string naming the key, for Site Health.

And the operational result is the one hosts actually want: **key custody moves to the KMS, and
nothing else changes.** Secrets stay in the options tables, the libsodium envelope is untouched,
rotating the site key still re-wraps exactly one value, and the KMS is called once per request at
most rather than once per secret.

That is the "secure default, stronger upgrade path" shape this API is built around, demonstrated
in three methods.

## When you do want a provider

Reach for `WP_Secrets_Provider` when the platform is the system of record for the *secret* — a
control panel an operator manages credentials in, Secrets Manager, Parameter Store. Then
WordPress is a consumer rather than a custodian, and the provider declares that with
`get_protection_boundary() === BOUNDARY_PROVIDER`.

Two things to get right there, both easy to get wrong:

- **Do not cache plaintexts in the persistent object cache.** `WP_Secret` deliberately cannot
  round-trip a plaintext through `wp_cache_set()`, and a provider caching the raw value alongside
  it would quietly undo that. Request-scoped memoisation only.
- **Absence is `null`, unreachability is `WP_Error`.** A network blip must not look like a deleted
  credential. This is the single property the whole three-state contract exists to protect.

## Prove it conforms

Whichever seam you pick, run the conformance suite against it before trusting it:

```php
class Tests_My_Platform_Provider extends WP_Secrets_Provider_Conformance {
    protected function provider() {
        return new My_Platform_Provider( /* ... */ );
    }
}
```

It checks the properties `implements WP_Secrets_Provider` cannot: absence reported as `null`,
deleting something absent succeeding, fingerprints stable for the same value, listings never
containing a plaintext, and a read-only declaration actually being honoured. See
[`../docs/extending.md`](../docs/extending.md).

## Dependencies

Each binding owns its own `composer.json`. The plugin's dependency tree stays clean, `make ci`
never installs an SDK, and `examples/*/vendor/` is git-ignored.
