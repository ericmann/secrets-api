# AWS KMS keyring

A `wp-content/secrets.php` drop-in that moves custody of the site's **root key** to an AWS KMS
customer master key. WordPress keeps its own envelope: this only changes what wraps the root key
everything else derives from. `wp secret dropin` still reports `Encryption boundary: WordPress`,
and `Protected by: WordPress (libsodium), key source: AWS KMS key <key id> in <region>`.

**No Composer, no AWS SDK.** One SigV4 signature and `wp_remote_post()`, in a single file you can
read end to end. A drop-in that drags in a 100 MB SDK is a drop-in nobody audits.

## Where the credentials go

**Not `.wp-env.json`** — that file is committed. Use `.wp-env.override.json`, which wp-env merges
on top and which this repo git-ignores:

```jsonc
// .wp-env.override.json  (repo root, git-ignored)
{
  "config": {
    "WP_SECRETS_KMS_KEY_ID":    "1234abcd-12ab-34cd-56ef-1234567890ab",
    "WP_SECRETS_AWS_REGION":    "us-east-1",
    "WP_SECRETS_AWS_KEY":       "AKIAIOSFODNN7EXAMPLE",
    "WP_SECRETS_AWS_SECRET":    "wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY",
    "WP_SECRETS_AWS_ENDPOINT":  ""
  }
}
```

`WP_SECRETS_AWS_ENDPOINT` is optional — leave it unset (or empty) to reach real AWS. It exists
for pointing the keyring at an emulator such as Moto during development; see "Run it against an
emulator" in `../aws-secrets-manager/README.md` for the general pattern.

Anything under `config` becomes a PHP constant in `wp-config.php`. Then:

```sh
npx @wordpress/env start     # re-reads the config and rewrites wp-config.php
```

On a real site these are ordinary `wp-config.php` constants — or better, an IAM role, in which
case you would swap the signing block for instance-profile credentials (see "Known limits of this
example").

## Install the drop-in

```sh
CID=$(docker ps --format '{{.Names}}' | grep -- '-cli-1' | grep -v tests)
docker cp examples/aws-kms-keyring/secrets.php "$CID":/var/www/html/wp-content/secrets.php
docker exec "$CID" wp secret dropin --verbose
```

Expected once the constants are set:

```
Drop-in active: yes
Provider: WP_Secrets_Libsodium_Provider
Protected by: WordPress (libsodium), key source: AWS KMS key 1234abcd-12ab-34cd-56ef-1234567890ab in us-east-1
Encryption boundary: WordPress
Accepts writes: yes
Keyring class: AWS_KMS_Keyring
Store class: WP_Secrets_Option_Store
```

To take it back out — **and do this before running the test suite**:

```sh
for c in $(docker ps --format '{{.Names}}' | grep -E 'cli-1|wordpress-1'); do
  docker exec "$c" rm -f /var/www/html/wp-content/secrets.php
done
```

**The gotcha:** wp-env's dev and tests environments see the same `wp-content`, so an installed
drop-in is in front of PHPUnit too. A drop-in that cannot reach KMS will fail most of the suite,
which looks alarming and is not a code problem. Remove it, re-run, and it is green again. Removing
it from a single container is not enough — the loop above covers all four.

## IAM permissions

The smallest policy that runs everything above:

```
kms:Encrypt
kms:Decrypt
```

Scope the resource to the one key's ARN. `kms:Decrypt` is the sensitive one of the two: any
principal that holds it can unwrap the root key, and from there derive every master key on the
site. `kms:Encrypt` alone is enough to wrap a new root key but useless without `kms:Decrypt` to
read one back, which is why the two are worth reasoning about separately rather than as one grant.

## Adopting an existing site

A site that already has a root key — wrapped by the config keyring, using `WP_SECRETS_KEY` — moves
onto this keyring in three steps:

1. **Install the drop-in** (above). From this point on, `unwrap()` is called with the existing
   config-keyring-wrapped root key, and it is not a value AWS KMS produced.
2. **`wp secret rotate --from=config`.** This unwraps the root key with the config keyring and
   re-wraps it under the now-active KMS keyring. No secret is re-encrypted — only what wraps the
   root key changes.
3. **`wp secret health`.** Confirms nothing is left undecryptable.

> **Between steps 1 and 2, every secret read fails closed.** `unwrap()` sees a value with no
> `kms1:` prefix and returns a `WP_Error` naming step 2 directly: *"The stored root key was not
> wrapped by AWS KMS (no kms1: prefix), so it was probably wrapped by the config keyring. Run `wp
> secret rotate --from=config` to move it onto this KMS key."* Do steps 1 and 2 in the same
> maintenance window — do not leave a site running with the drop-in installed but not yet rotated.

What the failure looks like in the meantime:

```
$ wp secret get acme/api-key --reveal
Error: The stored root key was not wrapped by AWS KMS (no kms1: prefix), so it was probably
wrapped by the config keyring. Run `wp secret rotate --from=config` to move it onto this KMS key.
```

## How often KMS is called

Once per request, at most. `WP_Secrets_Key_Manager` caches the unwrapped root key in memory for
the life of the request, keyed on the wrapped value it came from, so a re-wrap or rotation
replaces it rather than serving stale key material. See
[docs/decisions/0009-root-key-cached-for-the-request.md](../../docs/decisions/0009-root-key-cached-for-the-request.md).
Without that cache, every `wp_get_secret()` call in a request would be its own KMS round trip;
with it, ten reads make one `Decrypt` call, which `examples/aws-kms-keyring/tests/test-aws-kms-keyring.php`
proves directly.

## Design points

- **The encryption context is fixed, not per-site.** KMS authenticates it the way an AEAD cipher
  authenticates AAD. Binding it to something like `home_url()` would make a domain change
  unrecoverable, and there is exactly one root key per install, so there is nothing per-site to
  bind it to.
- **`KeyId` is pinned on `Decrypt`.** Without it, KMS decrypts with whichever key the ciphertext
  blob names, and a swapped blob under a key this IAM role can also use would otherwise succeed.
- **The `kms1:` prefix** turns the most likely adoption failure — a root key still wrapped by the
  config keyring — into the specific, actionable error above instead of an opaque
  `InvalidCiphertextException`.
- **Timeouts are short (3 s), the `AWS_KMS_Keyring::TIMEOUT` constant.** Every secret operation
  waits on this call. A KMS outage that does not answer within it turns every read into a
  `WP_Error` rather than hanging the request — fail closed, on purpose.
- **Install guard.** The drop-in installs only when all four constants are defined and non-empty
  after `trim()`. A freshly-copied override file with the keys present but blank falls back to
  WordPress's own keyring instead of installing one that fails every call.

## Known limits of this example

Stated because it is a demonstration, not a product:

- **Static credentials.** Fine for a demo; use an IAM role or instance-metadata credentials in
  production — this example does not implement either.
- **No KMS multi-region keys.** One key, one region.
- **No move between two different KMS keys.** Only adoption from the config keyring is covered.
  KMS's own automatic key rotation keeps the key ID stable and decrypts old ciphertext under it,
  so that case needs no re-wrap and no `rotate --from`.
- **A KMS outage is a `WP_Error` on every read**, by design (see "Design points" above) — this is
  not a bug to work around, but it does mean the keyring has no offline fallback.

## Prove it conforms

```php
class Tests_AWS_KMS_Keyring_Conformance extends WP_Secrets_Keyring_Conformance {
    protected function keyring() {
        return new AWS_KMS_Keyring( getenv( 'KMS_KEY_ID' ), 'us-east-1', getenv( 'AWS_KEY' ), getenv( 'AWS_SECRET' ) );
    }
}
```

That checks the properties `implements WP_Secrets_Keyring` cannot: `wrap()` is non-deterministic,
`unwrap()` round-trips exactly the bytes that went in, and garbage, truncated, or tampered input
fails closed as `WP_Error` rather than returning a plausible-looking wrong key. It makes real API
calls, so point it at a throwaway AWS account.

## Run it against an emulator

`examples/aws-kms-keyring/tests/test-aws-kms-keyring-conformance.php` runs the conformance suite
above against [Moto](https://github.com/getmoto/moto) instead of real AWS, so it can run without
credentials or cost. Start it (shared with `../aws-secrets-manager/README.md`'s emulator, since
Moto serves both KMS and Secrets Manager from the same container):

```sh
docker pull motoserver/moto:latest
docker run -d --name secrets-api-moto-kms -p 5051:5000 motoserver/moto:latest
curl -sf http://localhost:5051/moto-api/   # 200 once it is up
```

The fifth constructor argument, `$endpoint`, points the keyring at Moto instead of real AWS — this
is what `WP_SECRETS_AWS_ENDPOINT` sets when defined, and it is never set in production.
`phpunit-examples.xml.dist` already points `WP_SECRETS_TEST_AWS_ENDPOINT` at
`http://host.docker.internal:5051`, which is where the tests-cli container reaches a Moto
container published on the host. Then, run from the repository root so `$(basename "$PWD")`
resolves to the plugin's directory name:

```sh
npx @wordpress/env run --env-cwd="wp-content/plugins/$(basename "$PWD")" tests-cli vendor/bin/phpunit -c phpunit-examples.xml.dist
```

or, without wp-env, `make test-examples`. Not part of `make ci`: it needs Moto running, and the
separate examples CI job runs it against a pinned Moto service container.
