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
| `WP_MIRROR_BASE` | Substituted for `https://wordpress.org` when downloading WordPress itself |
| `WP_TESTS_ZIP_URL` | Full URL to a WordPress archive, overriding version lookup entirely |
| `WP_API_BASE` | Substituted for `https://api.wordpress.org`, used only to resolve what "latest" means |
| `WP_DEVELOP_BASE` | Substituted for `https://github.com/WordPress/wordpress-develop`, where the test suite comes from |

Three separate hosts, deliberately: a mirror of wordpress.org downloads is not automatically a
mirror of the version-check API or of the wordpress-develop repository, so collapsing them into
one variable would quietly send two of the three somewhere that cannot serve them.

The test suite is fetched as a **tarball, not an svn checkout**. `svn` is not installed on
GitHub's ubuntu-24.04 runners and has not shipped with macOS since Xcode's command line tools
dropped it, so requiring it meant `make install` — the documented no-Docker path — could not run
in CI or on a stock Mac. curl-or-wget plus tar is a dependency both already have.

Composer's cache (`~/.composer/cache`) should be restored between runs on any runner without
reliable egress to Packagist.

## Where this runs

`make ci` is the source of truth and depends on none of the below. What follows is only about the
hosted pipeline.

**github.com, hosted runners.** Static analysis gates a PHP 7.4 / 8.0 / 8.3 × WordPress
latest / trunk matrix, plus a multisite job. `shivammathur/setup-php` provides the interpreter and
declares the `sodium` extension explicitly — this API is built entirely on libsodium, and
"whatever the runner image happens to ship" was never a good enough answer.

The workflow declares `permissions: contents: read`. Nothing in it writes to the repository,
publishes anything, or needs a token beyond reading the code under test.

`WP_MIRROR_BASE` and the air-gapped path above still work and are still supported by
`bin/install-wp-tests.sh` — they are just not load-bearing for CI as configured.

## Pinning

Every action is pinned by **full commit SHA**, not by tag. A tag is mutable; a SHA is not, and
this repository implements a credential store — an action that silently changes under a moved tag
is exactly the supply-chain shape worth refusing here.

Each SHA in the workflow was resolved from the GitHub API at the version noted beside it, not
copied out of documentation or a README — a pin nobody verified is a pin to whatever the last
person pasted.

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
