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

## Where this runs

`make ci` is the source of truth and depends on none of the below. What follows is only about the
hosted pipeline.

**Currently unresolved — see [`open-questions.md`](open-questions.md) #8.** The workflow in
`.github/workflows/ci.yml` is written for a self-hosted runner, which was the right assumption for
`github.a8c.com` and is the wrong one for github.com. Two facts settled since it was written:

- The GHES instance has **no runners configured**, and GHES provides no GitHub-hosted runners in
  any edition — every job would need a machine someone owns and patches, carrying three PHP
  versions and a reachable MySQL.
- "Allow local actions only" does **not** block `actions/checkout`: official GitHub actions are
  bundled into GHES instances with no GitHub Connect required. The workflow's defensive
  "only `actions/checkout`, everything else hand-rolled" shape was guarding against a constraint
  that turned out not to bind.

**If this moves to github.com** (the recommendation in #8, since this is a feature plugin for a
public proposal and `src/` is a `wordpress-develop` patch candidate), the workflow simplifies
considerably: `runs-on: ubuntu-latest`, and `shivammathur/setup-php` replaces the hand-rolled
`update-alternatives` PHP switching that only exists because Marketplace actions were assumed
unavailable.

**If it stays on GHES**, two changes are required before it will run at all: provision at least
one self-hosted runner, and replace the full-SHA action pin with a tag the instance's bundled
`actions` org actually contains — bundled actions are captured at a point in time, so a SHA from
github.com may simply not exist there. See "Pinning" below for why that is a real tradeoff and
not a free change.

## Pinning

Every action is pinned by **full commit SHA**, not by tag. A tag is mutable; a SHA is not, and
this repository implements a credential store — an action that silently changes under a moved tag
is exactly the supply-chain shape worth refusing here.

The one place that rule has to bend is a GHES instance whose bundled `actions` org does not
contain the pinned commit (see above). Loosening to a tag there is a real reduction in guarantee,
not a formatting change, and is one more argument for running this on github.com where SHA pins
always resolve.

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
