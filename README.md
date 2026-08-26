# Secrets API

Feature plugin for the [Secrets API proposed for WordPress 7.2][proposal]. Encrypted, versioned
credential storage with pluggable storage and keyring back ends.

This exists so contributors can *run* the API instead of reading about it, and so sites on 7.0
and 7.1 get something usable before the core patch lands. Everything under `src/` is written to
be copied verbatim into `wordpress-develop` — same paths, same coding style, same `default` text
domain.

> **Status: in development.** The API surface is not yet complete. See
> [`docs/open-questions.md`](docs/open-questions.md) for what is deliberately unresolved.

[proposal]: https://make.wordpress.org/core/2026/08/25/proposal-a-secrets-api-for-wordpress-7-2/

## Clone to green

Two commands. The second brings up wp-env, installs the WordPress test suite inside it, and runs
exactly what CI runs.

```sh
composer install
bin/ci-local.sh
```

Add `--keep` to leave the environment running between iterations.

If you already have a WordPress test suite and a database, skip wp-env entirely:

```sh
make install    # composer install + bin/install-wp-tests.sh
make ci
```

`make ci` is the single source of truth. The CI workflow is a thin wrapper around the same
targets, so a green local run means a green pipeline. Run `make` with no arguments for the full
target list.

| Target | What it does |
|---|---|
| `make lint` / `make lint-fix` | phpcs / phpcbf |
| `make compat` | PHPCompatibilityWP at `testVersion 7.4-` |
| `make analyse` | phpstan |
| `make test` / `make test-ms` | phpunit, single site / multisite |
| `make coverage` | phpunit with an HTML coverage report |
| `make ci` | all of the above |

Runners without egress to wordpress.org can point the installer at a mirror with `WP_MIRROR_BASE`
or `WP_TESTS_ZIP_URL`. See [`docs/ci.md`](docs/ci.md).

## What this is

Three layers, each independently replaceable at exactly one seam:

```
site key  ──wraps──▶  root key  ──derives──▶  master key  ──wraps──▶  per-secret data key
 (keyring)             (one per install,       (per scope,             (per secret per slot,
                        the only wrapped        never stored)           wraps the value)
                        value on the site)
```

Rotating the site key re-wraps one value, on a single site or on a 500-site network.

Some things are load-bearing and will not change:

- **Encryption is unconditional.** There is no plaintext mode and no constant to disable it.
- **No filter on the retrieval path.** Nothing intercepts a credential between storage and the
  caller. A filter that can intercept a credential is a filter that can steal one.
- **Fail closed.** An unreachable store or keyring is a `WP_Error`, never a fallback to local
  storage or local key wrapping.
- **No export.** `WP_Secret::reveal()` is the only path to a stored plaintext. Migrations and
  staging pushes mean re-entry at the destination.
- **Three states, never collapsed.** `wp_get_secret()` returns a `WP_Secret`, `null` when the
  secret does not exist, or a `WP_Error` when it exists but could not be retrieved. Absent and
  broken are different things.

### What it is not

There is **no per-plugin isolation**. Namespacing (`plugin-slug/secret-name`) is for grouping and
for a future access check to have something to check against. Masking is hygiene against
shoulder-surfing and accidental logging — it is not a privilege boundary. Any plugin that can run
PHP can read any secret. See [`docs/open-questions.md`](docs/open-questions.md) #6.

There is **no admin settings screen**. The proposal defers it to 7.3. The hooks and accessors a
future screen needs are in scope; the screen is not.

## Requirements

- PHP 7.4+ (core's floor — `src/` contains no PHP 8 syntax)
- WordPress 6.6+
- libsodium, via the extension or core's bundled `sodium_compat`

## Contributing

Commits are small and logically scoped, and tests land in the same commit as the code they cover.
Before changing anything under `src/`, read the constraints above — several of them are enforced
by architectural tests that read the source, and those tests are never weakened to make a build
green.

## License

GPL-2.0-or-later
