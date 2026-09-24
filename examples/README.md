# Platform bindings

Working examples of connecting this API to a cloud provider. Nothing in this directory is loaded
by the plugin; you copy one into a `wp-content/secrets.php` drop-in. They're excluded from
`make ci` so their SDK dependencies stay out of the plugin's.

This will probably become a submodule once there's more than one, which is why it sits at the top
level instead of under `docs/`.

## Examples in this directory

- [`aws-kms-keyring/`](aws-kms-keyring/README.md) — a `WP_Secrets_Keyring`. AWS KMS holds the
  root key; secrets stay in WordPress's own options tables.
- [`aws-secrets-manager/`](aws-secrets-manager/README.md) — a `WP_Secrets_Provider`. AWS Secrets
  Manager holds the secret itself; WordPress becomes a consumer rather than a custodian.

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
the site key still re-wraps one value. `WP_Secrets_Key_Manager` unwraps the root key once per
request and keeps it in memory for the rest of that request, so a KMS is called once per request,
not once per secret. That was not true before the caching change described in
[ADR 0009](../docs/decisions/0009-root-key-cached-for-the-request.md).

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
[`../docs/spec/extension-points.md`](../docs/spec/extension-points.md).

## Run the examples suite

Both examples' conformance suites run against [Moto](https://github.com/getmoto/moto), an AWS
emulator, so they run without real credentials or cost:

```sh
docker pull motoserver/moto:latest
docker run -d --name secrets-api-moto-kms -p 5051:5000 motoserver/moto:latest
curl -sf http://localhost:5051/moto-api/   # 200 once it is up
```

Then `make test-examples`, or, under wp-env, run from the repository root so `$(basename "$PWD")`
resolves to the plugin's directory name:
`npx @wordpress/env run --env-cwd="wp-content/plugins/$(basename "$PWD")" tests-cli vendor/bin/phpunit -c phpunit-examples.xml.dist`.
This is outside `make ci`: it needs Moto running, a service container the other CI environments
do not provide.

## Dependencies

The examples have no Composer dependencies — that is the point of hand-rolling SigV4 instead of
pulling in an SDK. `examples/*/vendor/` stays git-ignored for any example that ever adds one.
