# CI

`make ci` is the single source of truth. Any hosted pipeline is a thin wrapper around the same
targets, so a green local run means a green pipeline. If the two ever disagree, the Makefile is
right and the workflow is wrong.

```sh
make lint      # phpcs
make compat    # PHPCompatibilityWP, testVersion 7.4-
make analyse   # phpstan
make test      # phpunit, single site
make test-ms   # phpunit, multisite
make ci        # all of the above
```

## The always-works path

```sh
composer install
bin/ci-local.sh
```

`bin/ci-local.sh` brings up wp-env (Docker), installs the WordPress core PHPUnit suite inside the
tests container, runs everything, and tears down. `--keep` leaves the environment running between
iterations.

Nothing in this path depends on a hosted runner, a Marketplace Action, or network access beyond
the initial `composer install` and the first Docker image pull.

### Without Docker

If you already have a WordPress test suite and a MySQL database:

```sh
make install   # composer install + bin/install-wp-tests.sh
make ci
```

`bin/install-wp-tests.sh` takes `<db-name> <db-user> <db-pass> [db-host] [wp-version]` and honours
`WP_TESTS_DIR` and `WP_CORE_DIR`.

## Air-gapped and mirrored environments

`bin/install-wp-tests.sh` reads two environment variables so it never has to reach
wordpress.org:

| Variable | Effect |
|---|---|
| `WP_MIRROR_BASE` | Substituted for `https://wordpress.org` in every download |
| `WP_TESTS_ZIP_URL` | Full URL to a WordPress archive, overriding version lookup entirely |
| `WP_SVN_BASE` | Substituted for `https://develop.svn.wordpress.org` when fetching the test suite |

Composer's cache (`~/.composer/cache`) should be restored between runs on any runner without
reliable egress to Packagist.

## GitHub Enterprise (`github.a8c.com`)

GHES is not github.com, and the differences are load-bearing. **A maintainer must confirm the
following on the instance before the workflow will run.** Each is tracked in
[`open-questions.md`](open-questions.md) #8.

### 1. Are Marketplace Actions available?

GHES only has them if the instance enables GitHub Connect or action bundling. The workflow
therefore assumes **only `actions/checkout`** and uses plain `run:` steps for everything else —
no `shivammathur/setup-php`, no `ramsey/composer-install`. A container-based fallback job that
needs no actions beyond checkout is provided for the case where even that is unavailable.

Do not add a third-party action without confirming it resolves on the instance first.

### 2. What are the runner labels?

Instance-specific and not guessable. The workflow parameterises them:

```yaml
strategy:
  matrix:
    runner: [ self-hosted ]   # CONFIRM: replace with this instance's actual label
runs-on: ${{ matrix.runner }}
```

`self-hosted` is a placeholder, not a known-good value.

### 3. Do runners have egress?

If not, use the `container:` job with a MySQL service, cache `~/.composer/cache`, and point
`WP_MIRROR_BASE` at an internal mirror. See above.

### 4. Is there an internal WordPress tarball mirror?

If one exists, set `WP_MIRROR_BASE` as a repository variable and the installer needs no other
changes.

## Pinning

Every action is pinned by **full commit SHA**, not by tag. A tag is mutable; a SHA is not, and
this repository handles credentials.

## Matrix

| Job | PHP | WordPress | Notes |
|---|---|---|---|
| `static` | 8.3 | — | lint + compat + analyse. Gates everything else. |
| `test` | 7.4, 8.0, 8.3 | latest, trunk | Single site |
| `test-multisite` | 8.3 | latest | Multisite suite |

The 7.4 leg is not optional. Core's floor is 7.4 and `src/` must run there; PHPCompatibilityWP
catches syntax statically, but only a running 7.4 catches runtime behaviour differences.

Local wp-env is pinned to PHP 7.4 for the same reason — the floor is where bugs hide, so it is
the default you develop against rather than something CI discovers later. Change `phpVersion` in
`.wp-env.json` to reproduce a failure on a newer PHP.
