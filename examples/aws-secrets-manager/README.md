# AWS Secrets Manager provider

A `wp-content/secrets.php` drop-in that makes AWS Secrets Manager the system of record for a
site's credentials. WordPress becomes a consumer rather than a custodian, and
`wp secret dropin` reports `Encryption boundary: the provider (outside WordPress)`.

**No Composer, no AWS SDK.** One SigV4 signature and `wp_remote_post()`, in a single file you can
read end to end. A drop-in that drags in a 100 MB SDK is a drop-in nobody audits.

## Where the credentials go

**Not `.wp-env.json`** — that file is committed. Use `.wp-env.override.json`, which wp-env merges
on top and which this repo git-ignores:

```jsonc
// .wp-env.override.json  (repo root, git-ignored)
{
  "config": {
    "WP_SECRETS_AWS_REGION": "us-east-1",
    "WP_SECRETS_AWS_KEY":    "AKIAIOSFODNN7EXAMPLE",
    "WP_SECRETS_AWS_SECRET": "wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY"
  }
}
```

Anything under `config` becomes a PHP constant in `wp-config.php`. Then:

```sh
npx @wordpress/env start     # re-reads the config and rewrites wp-config.php
```

On a real site these are ordinary `wp-config.php` constants — or better, an IAM role, in which
case you would swap the signing block for instance-profile credentials.

## Install the drop-in

```sh
CID=$(docker ps --format '{{.Names}}' | grep -- '-cli-1' | grep -v tests)
docker cp examples/aws-secrets-manager/secrets.php "$CID":/var/www/html/wp-content/secrets.php
docker exec "$CID" wp secret dropin
```

Expected once the constants are set:

```
Drop-in active: yes
Provider: AWS_Secrets_Manager_Provider
Protected by: AWS Secrets Manager (us-east-1)
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
drop-in is in front of PHPUnit too. A drop-in that cannot reach AWS will fail most of the suite,
which looks alarming and is not a code problem. Remove it, re-run, and it is green again. Removing
it from a single container is not enough — the loop above covers all four.

## IAM permissions

The smallest policy that runs everything below:

```
secretsmanager:GetSecretValue
secretsmanager:PutSecretValue
secretsmanager:CreateSecret
secretsmanager:DeleteSecret
secretsmanager:ListSecrets
```

Scope the resource to `arn:aws:secretsmanager:<region>:<account>:secret:wp/*` and the site can
only touch its own secrets. Drop `Put`/`Create`/`Delete` and the provider still serves reads —
change `is_writable()` to `false` and WordPress will stop offering writes rather than failing
them.

## Naming

WordPress names map across unchanged, under a scope prefix: `acme/stripe-key` becomes
`wp/acme/stripe-key`, and network-scope secrets use `wp-network/`. Secrets Manager allows
alphanumerics plus `/_+=.@-`, so no escaping is needed.

## The part worth pointing at

Secrets Manager tracks versions with **staging labels**, two of which are `AWSCURRENT` and
`AWSPREVIOUS`. That is exactly `WP_Secret_Version::CURRENT` and `::PREVIOUS`.

So `wp secret get acme/key --slot=previous` becomes a `GetSecretValue` with
`VersionStage: AWSPREVIOUS`, and `PutSecretValue` rotates the labels server-side — the two-slot
model needs no emulation because it is the shape the problem already has. That is a reasonable
signal the API's rotation design is not a WordPress-ism.

## Known limits of this example

Stated because it is a demonstration, not a product:

- **`list_secrets()` returns empty fingerprints.** Fingerprinting each entry would mean a
  `GetSecretValue` call per secret. `wp secret get` reports the real fingerprint for one secret.
- **`retire_previous()` is a successful no-op.** AWS owns the staging labels; there is no separate
  previous slot for WordPress to clear.
- **Caching is request-scoped only**, deliberately. Never put a plaintext in the persistent object
  cache: `WP_Secret` cannot round-trip one through `wp_cache_set()`, and caching the raw value
  beside it would quietly undo that.
- **Static credentials.** Fine for a demo; use an IAM role in production.
- **No pagination** on `ListSecrets` beyond the first 100.

## Prove it conforms

```php
class Tests_AWS_Secrets_Manager_Provider extends WP_Secrets_Provider_Conformance {
    protected function provider() {
        return new AWS_Secrets_Manager_Provider( 'us-east-1', getenv( 'AWS_KEY' ), getenv( 'AWS_SECRET' ) );
    }
}
```

That checks the properties `implements WP_Secrets_Provider` cannot: absence reported as `null`
rather than an error, deleting something absent succeeding, fingerprints stable for the same
value, and listings never containing a plaintext. It makes real API calls, so point it at a
throwaway AWS account.
