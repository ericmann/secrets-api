# Spec: WP-CLI smoke test

Status: built. Part of the pre-Trac work in
[ADR 0008](../../docs/decisions/0008-the-trac-ticket-replaces-thread-confirmation.md). See
[`docs/journal/2026-09-24-testing-the-cli-for-real.md`](../../docs/journal/2026-09-24-testing-the-cli-for-real.md)
for the journal entry.

## Why

Every WP-CLI test in `tests/phpunit/` constructs the command class and calls its methods
directly. That tests the method bodies and nothing about how WP-CLI reaches them: flag
reservations, synopsis parsing, and mapping method names to subcommand names. Three bugs lived
there behind a green suite (see `docs/journal/test-coverage-gaps.md`). The worst was
`--version=previous` silently returning the current value. This is the only gap in that file
marked 🟡, meaning it needs an answer before the core patch.

The same harness also closes two 🟢 gaps as a side effect: `set --stdin`, which PHPUnit cannot pipe
into safely, and drop-in file loading, which runs once per process before any test body.

## Shape

- **`tests/smoke/smoke.sh`.** Bash with no dependencies beyond `wp` and `php`. It uses small
  `ok`/`not_ok` helpers that print TAP-style lines, and exits non-zero if any case fails. No bats:
  one more tool to install is not worth it for about forty assertions.
- **`bin/smoke-install.sh`** provisions a throwaway install that the smoke test owns:
  - Downloads a pinned `wp-cli.phar` and checks it against a committed SHA-256, following the
    pin-everything rule in `ci.yml`.
  - Runs `wp core download` into `.smoke/wordpress/` (git-ignored) and `wp config create`
    against a separate `wordpress_smoke` database, using the same `DB_*` variables as
    `make install`. It must not be `wordpress_test`, because the PHPUnit suite drops and recreates
    that one.
  - Defines `WP_SECRETS_KEY` before the first secret is ever written, so the rotation case has a
    real site key to rotate from.
  - Symlinks the plugin into place and activates it.
- **`make smoke`** runs the install, then the single-site pass, converts the install with
  `wp core multisite-convert`, and runs the multisite pass.
- **`make ci` includes `smoke`.** The Makefile says `make ci` is the pipeline, and a CI job that
  `make ci` does not run would break that. `bin/ci-local.sh` gets the same target.
- **The CI job `smoke`** runs after `static`, on PHP 7.4 (the floor, and where 7.4-only CLI
  surprises would show up) and 8.3, with the same MySQL service as the test jobs.

It uses its own install rather than wp-env's, because wp-env's dev and test environments share
`wp-content`. A drop-in the smoke test writes would sit in front of PHPUnit, which is exactly how
the AWS example once took down 85 tests.

Each run namespaces its secrets as `smoke-<pid>/…`, and an `EXIT` trap removes any drop-in the
script wrote. The install is disposable, but a failed run should not leave a broken drop-in behind
for the next person debugging it.

## Cases

### A. Registration

This catches bugs 2 and 3 from the gaps file.

- For each of the 11 subcommands, under both `secret` and `network-secret`:
  `wp cli has-command "secret <sub>"` exits 0. That covers `import-option`, `migrate-legacy`, and
  `generate-key` by their hyphenated names.
- For each flag a subcommand declares, `wp help secret <sub>` shows it: `--slot`, `--reveal`,
  `--format`, `--field`, `--fields`, `--stdin`, `--porcelain`, `--yes`, `--namespace`,
  `--dry-run`, `--name`, `--map`, and `--verbose`. The expected table is written out at the top of
  the script, one row per subcommand, so a new flag without a row fails loudly rather than going
  untested.
- `wp secret get --version=previous` is not accepted as the slot selector. This pins bug 1's cause,
  not only its fix.

### B. Behaviour and the exit-code contract

`wp secret get` documents exit 0 when the secret is found, 1 when it is absent, and 2 on error.
Every case below checks the exit code as well as the output.

- `set` with a positional value exits 0, and `set --stdin` from a pipe exits 0. `set --porcelain`
  prints only what its docblock promises.
- `get` masks by default: the plaintext is not in stdout. `get --reveal` prints exactly the value.
- `set` A, then `set` B, then `get --slot=previous --reveal` prints A. This is bug 1, end to end.
- `get --format=json` parses as JSON with `php -r`. `list --format=json`, `--format=csv`,
  `--fields`, and `--namespace` all filter as documented.
- After `retire --yes`, `get --slot=previous` exits 1. After `delete --yes`, `get` exits 1.
- `get` of a name that was never set exits 1, and `set` with no value exits non-zero.
- `generate-key` prints 44 characters that decode to exactly 32 bytes.
- `health --format=json` parses as JSON. `dropin` reports no drop-in.
- `import-option` moves a seeded option into a secret, and `get --reveal` matches it.
- `migrate-legacy --dry-run` exits 0 on an install with no prototype rows.

### C. Rotation, end to end

- Set a secret. Move the value of `WP_SECRETS_KEY` to `WP_SECRETS_KEY_PREVIOUS` with
  `wp config set`, and set a new `WP_SECRETS_KEY` from `generate-key`. Then `rotate --yes` exits
  0, `get --reveal` still returns the value, and `health` reports no undecryptable secrets.
- `rotate` without `WP_SECRETS_KEY_PREVIOUS` exits non-zero with its explanatory message.
- Once the KMS spec's `--from` lands, add `rotate --from=config`, refused because the old and new
  keyrings are the same configuration.

### D. Drop-in loading

This closes the 🟢 gap.

Each case writes `wp-content/secrets.php`, runs the assertions, and removes the file.

| Drop-in | Expect |
|---|---|
| Syntax error | `get` exits **2**, not 1, and `dropin` reports it broken |
| Throws on load | `get` exits 2, and `dropin` reports it broken |
| `$GLOBALS['wp_secrets_provider'] = new stdClass()` | `get` exits 2. This is the 4 September fail-closed fix, run through the real loader. |
| Sets nothing | `get` behaves exactly as with no drop-in |

Exit 2 against exit 1 is ADR 0007's distinction between unreachable and absent. This is the first
test that checks it through the real `require` in `wp_secrets_api_load_dropin()` rather than by
setting globals. The known uncatchable case, a class that implements an interface but omits a
method, is recorded as a fatal: a non-zero exit with a PHP fatal in stderr. It is written down as
expected behaviour so a future PHP that makes it catchable shows up as a change.

### E. Multisite pass

- `network-secret set` and `get --reveal` round-trip.
- Site scope is per site: `set` with `--url=<site 2>` is invisible from site 1.
- `network-secret` refusing on a single site is checked in the single-site pass.

## Out of scope

- Output formatting beyond what is needed to parse it. This is a dispatch test, not a snapshot
  test. Byte-for-byte output assertions would break on every WP-CLI table change.
- Running the examples' providers through the CLI. Their own tests cover them.

## Done when

- `make smoke` passes locally through `bin/ci-local.sh`, and the CI `smoke` job passes on 7.4
  and 8.3.
- Reintroducing each of the three historical bugs makes the smoke test fail: restore the
  `--version` flag, remove a `--format` description line, and drop the `@subcommand` tag. Each
  one is checked by hand, and the result goes in the commit message.
- `docs/journal/test-coverage-gaps.md` drops the CLI dispatch entry and the `--stdin` entry, and
  narrows the drop-in loading entry to the uncatchable-fatal case alone.
