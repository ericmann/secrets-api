# Platform bindings

Working examples of connecting this API to a cloud provider. Nothing in this directory is loaded
by the plugin; you copy one into a `wp-content/secrets.php` drop-in. They're excluded from
`make ci` so their SDK dependencies stay out of the plugin's.

This will probably become a submodule once there's more than one, which is why it sits at the top
level instead of under `docs/`.

## Which interface do you need?

Worth getting right before you write anything. **A key-management service is not a secret store**,
and the two map to different interfaces:

| Service | Holds | Implement | Effort |
|---|---|---|---|
| **AWS KMS** | keys | `WP_Secrets_Keyring` | 3 methods |
| **Google Cloud KMS** | keys | `WP_Secrets_Keyring` | 3 methods |
| **AWS Secrets Manager** | secrets | `WP_Secrets_Provider` | 8 methods |
| **Google Secret Manager** | secrets | `WP_Secrets_Provider` | 8 methods |
| **AWS Parameter Store** | secrets | `WP_Secrets_Provider` | 8 methods |

The mistake to avoid is reaching for KMS and writing a `WP_Secrets_Provider`. You'll make one KMS
call per secret read, hit the 4,096-byte payload ceiling on anything bigger than a token, and pay
per operation for work WordPress already does locally. AWS says as much in its own `Encrypt`
documentation: *"You don't need to use the `Encrypt` operation to encrypt a data key."*

## Start with a KMS keyring

The root key is 32 bytes, and it's the only wrapped value on the site. That makes a KMS keyring
about as small as a useful integration gets:

- `wrap( $key_material )` — one `Encrypt` call. At 32 bytes you're nowhere near the size limit,
  so you don't need an envelope of your own. WordPress already did that part.
- `unwrap( $wrapped )` — one `Decrypt` call.
- `get_key_source()` — a string naming the key, for Site Health.

What you get is what most hosts are actually after: **key custody moves to the KMS and nothing
else changes.** Secrets stay in the options tables. The libsodium envelope is untouched. Rotating
the site key still re-wraps one value, and the KMS gets called once per request at most instead of
once per secret.

## When you need a provider instead

Use `WP_Secrets_Provider` when the platform owns the secret itself: a control panel where an
operator manages credentials, Secrets Manager, Parameter Store. WordPress becomes a consumer
rather than a custodian, and the provider says so through
`get_protection_boundary() === BOUNDARY_PROVIDER`.

Two things people get wrong:

- **Don't cache plaintexts in the persistent object cache.** `WP_Secret` can't round-trip a
  plaintext through `wp_cache_set()`, and a provider that caches the raw value alongside it undoes
  that. Keep any memoisation request-scoped.
- **Absence is `null`. Unreachability is `WP_Error`.** A network blip must not look like a deleted
  credential.

## Run the conformance suite

Whichever you implement, run it against the conformance suite before you trust it:

```php
class Tests_My_Platform_Provider extends WP_Secrets_Provider_Conformance {
    protected function provider() {
        return new My_Platform_Provider( /* ... */ );
    }
}
```

It covers what `implements WP_Secrets_Provider` can't: absence reported as `null`, deleting
something absent succeeding, fingerprints staying stable for the same value, listings never
containing a plaintext, and a read-only declaration actually being honoured. See
[`../docs/extending.md`](../docs/extending.md).

## Dependencies

Each binding has its own `composer.json`. The plugin's dependency tree stays clean, `make ci`
never installs an SDK, and `examples/*/vendor/` is git-ignored.
