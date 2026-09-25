# WP-CLI smoke test build plan
Derived from docs/SPEC.md v1.0 on 2026-09-24. SPEC.md wins over this file.

`docs/SPEC.md` is the process wrapper; the design is `tests/smoke/SPEC.md` (called "the
detailed spec" below). Where the two disagree on design, the detailed spec wins; on process,
`docs/SPEC.md` wins. Every task below cites one or both.

## Decisions

- Shared examples harness (docs/SPEC.md §9) → not needed by this flight. Nothing here runs an
  example's provider through the CLI (detailed spec "Out of scope"), so no subset is built and
  there is nothing to reconcile at merge.
- A finding that would change an interface signature (docs/SPEC.md §9) → never change the
  signature. Record it under a new heading in `docs/journal/open-questions.md`, say so in the
  journal entry, and leave the decision to the Trac ticket. P7-01 and P7-03 carry this rule.
- `rotate --from=config` (detailed spec case C, third bullet) → deferred. It belongs to
  `build/kms-keyring`. P4-01 leaves a one-line comment naming the case; nothing else.
- Flag table check (case A, second bullet) → exact-set comparison. The set of `--flags` parsed
  from the SYNOPSIS section of `wp help secret <sub>` must equal the table row exactly, in both
  directions. That is the only reading under which "a new flag without a row fails loudly" is
  true. When `build/kms-keyring` merges and adds `--from` to `rotate`, the row gains `--from`;
  that is the accepted merge cost.
- Where the multisite conversion lives → inside `tests/smoke/smoke.sh`, after the single-site
  pass. `make smoke` is then `bin/smoke-install.sh` followed by `tests/smoke/smoke.sh`, and
  `bin/ci-local.sh` runs those same two scripts inside the wp-env `cli` container, which has no
  `make`. One code path for both routes.
- How `wp` is invoked → only ever through the array
  `WP=( php -d display_errors=stderr -d log_errors=0 "$SMOKE_DIR/wp-cli.phar" --path="$SMOKE_DIR/wordpress" --allow-root )`.
  The two `-d` flags make a PHP fatal land on stderr and nowhere else, which case D's
  uncatchable-fatal row relies on. `--allow-root` is harmless when not root.
- Database creation → a `php -r` snippet using `mysqli` (guaranteed wherever WordPress runs)
  that drops and recreates `$SMOKE_DB_NAME`. No `wp db` subcommand and no `mysql` client
  binary, so the scripts stay dependency-free beyond `wp` and `php`.
- WordPress version for the smoke install → `WP_VERSION`, default `latest`, the same variable
  and default as `make install`. Not pinned, matching the PHPUnit jobs.
- WP-CLI pin → the implementer resolves the newest stable `wp-cli/wp-cli` release at build
  time, verifies the download against the release's published checksum, computes its SHA-256,
  and pins both version and SHA-256 as constants in `bin/smoke-install.sh`. The commit message
  records the resolution.
- Smoke install URL and identities → `SMOKE_URL` default `http://smoke.test`; admin user
  `smoke` with a random, never-printed password; multisite site 2 slug `smoke2`. No DNS is
  needed: nothing serves the install over HTTP.
- "`dropin` reports it broken" (case D) → assert stdout contains
  `Provider: WP_Secrets_Broken_Provider`. That line is what the command prints when the loader
  set `wp_secrets_dropin_broken`.
- Case A registration for `network-secret` → `wp cli has-command "network-secret <sub>"` for
  all 11 subcommands on the single-site install (WP-CLI instantiates a command class only at
  invocation, so the constructor's multisite refusal does not fire). The help flag table is
  checked for `secret` only, exactly as the detailed spec words it.
- No ADR for this flight. The design decisions are already recorded in `tests/smoke/SPEC.md`
  and ADR 0008; an ADR is written only if a finding changes the API's design, which
  docs/SPEC.md §9 routes to `open-questions.md` instead.
- `docs/reference/ci.md` is hand-maintained (the generator writes only `functions.md`,
  `classes.md`, `hooks.md`, `wp-cli.md`), so P7-02 edits it directly, as docs/SPEC.md §8.7 asks.
- No ⚠️ ASSUMPTION exists in either spec. If a task finds it needs a timeout or limit, it names
  it as an upper-case `SMOKE_*` variable at the top of the script with a justifying comment and
  says so in the commit message. No tuning tasks are planned because there is nothing to tune.
- Regression proof (P6-01) → edits are made in the working tree only, reverted with
  `git checkout -- <file>`, and never committed. `git stash` is not used.
- Phase numbering follows docs/SPEC.md §8 (Phase 1 to Phase 7). Task IDs are `P<phase>-<nn>`.

## Conventions

Commit title `<ID>: <imperative title>`; body wrapped at 72 columns with the headings
`Goal:`, `Tests:`, `Interpretation:`, `Manual check:` (and `Measurement:` only for a tuning
task). See `CLAUDE.md` "Commit template".

Shared vocabulary every task uses; restated here so no task depends on another's text:

- `SMOKE_DIR`: `.smoke` at the repository root, git-ignored. Holds `wp-cli.phar`, `cache/`
  (`WP_CLI_CACHE_DIR`), and `wordpress/`.
- `WP` array: see Decisions. Both scripts define it identically and never call a bare `wp`.
- Environment read by `bin/smoke-install.sh` (defaults in parentheses): `SMOKE_DB_NAME`
  (`wordpress_smoke`), `DB_USER` (`root`), `DB_PASS` (empty), `DB_HOST` (`127.0.0.1`),
  `WP_VERSION` (`latest`), `SMOKE_URL` (`http://smoke.test`).
- Inside wp-env, the smoke scripts run in the `cli` container with
  `DB_HOST=mysql DB_USER=root DB_PASS=password` (wp-env's own development MySQL). The
  container's working directory is `/var/www/html/wp-content/plugins/cli-smoke`. The
  one-liner to run a script there is
  `npx @wordpress/env run --env-cwd=wp-content/plugins/cli-smoke cli env DB_HOST=mysql DB_USER=root DB_PASS=password bash <script>`.
- `tests/smoke/smoke.sh` layout, top to bottom, with these exact comment markers so later tasks
  know where to insert: `# --- configuration ---`, `# --- helpers ---`,
  `# --- case A: registration ---`, `# --- case B: behaviour and exit codes ---`,
  `# --- case C: rotation ---`, `# --- case D: drop-in loading ---`,
  `# --- multisite conversion ---`, `# --- case E: multisite ---`, `# --- main ---`. Each case
  is a function `case_a_registration`, `case_b_behaviour`, `case_c_rotation`,
  `case_d_dropin`, `convert_to_multisite`, `case_e_multisite`, called in that order from
  `main`.
- Secret names: `NS="smoke-$$"`; every secret the run writes is `${NS}/<key>`. A second
  namespace for filter tests is `${NS}-b`.
- Plaintext values are synthetic, at least 12 characters, and start with `smoke-value-`, so the
  default mask (`smok********`) never contains the full value.
- Bash portability: no bash-4-only syntax (`declare -A`, `mapfile`, `readarray`, `${x,,}`).
  macOS ships bash 3.2 and `make smoke` must run there.
- Helper contract (defined in P2-01, used by everything after): `ok "<desc>"`,
  `not_ok "<desc>" "<diag>"`, `diag "<text>"`, `run <cmd...>` (sets `OUT`, `ERR`, `STATUS`;
  never prints), `assert_status <n> "<desc>"`, `assert_out_eq "<expected>" "<desc>"`,
  `assert_out_contains "<needle>" "<desc>"`, `assert_out_not_contains "<needle>" "<desc>"`,
  `assert_err_contains "<needle>" "<desc>"`. A diagnostic may print the exit status, the
  description, and at most the first three lines of `ERR`. It never prints `OUT`, a value, a
  key, or the command line.
- Every task ends with `bin/ci-local.sh --keep` and `make reference-check` green (the
  `docs/foundry.json` verify commands). Tasks that touch the smoke scripts also run them inside
  wp-env as listed under Verification.
- Never: edit or commit `.wp-env.override.json`; run `wp-env destroy`; run `sf publish`; create
  or push a tag; touch `plugin/`; change a method signature on `WP_Secrets_Provider`,
  `WP_Secrets_Keyring`, or `WP_Secrets_Store`; do `build/kms-keyring`'s or
  `build/vault-provider`'s work.

## Phase 1 — Install harness

### P1-01: Provision the throwaway install with a pinned WP-CLI
**Goal:** `bin/smoke-install.sh` provisions a fresh single-site WordPress in `.smoke/wordpress/` with a SHA-256-verified `wp-cli.phar`, its own `wordpress_smoke` database, `WP_SECRETS_KEY` defined before any secret exists, and the plugin symlinked in and active, and `make smoke` runs it.
**Files touched:** `bin/smoke-install.sh` (new, `chmod +x`), `Makefile`, `.gitignore`, `phpcs.xml.dist`.
**Design constraints:** detailed spec "Shape" (the `bin/smoke-install.sh` bullets and "It uses its own install rather than wp-env's"); docs/SPEC.md §7 (database `wordpress_smoke`, never `wordpress_test`; same `DB_*` variables as `make install`; never edit `.wp-env.override.json`; never `wp-env destroy`); docs/SPEC.md §3 "Parallel flights" (Makefile edits additive: one new variable, one new target, nothing else); the pin-everything rule stated at the top of `.github/workflows/ci.yml`; docs/SPEC.md §3 "No plaintext in output" (the generated key is never echoed).
Implementation, in order:
1. `#!/usr/bin/env bash`, `set -euo pipefail`, `cd "$(dirname "$0")/.."`. Header comment explaining what the script owns and that everything it writes lives under `.smoke/`.
2. Pin constants at the top: `WP_CLI_VERSION`, `WP_CLI_SHA256`, and `WP_CLI_URL="https://github.com/wp-cli/wp-cli/releases/download/v${WP_CLI_VERSION}/wp-cli-${WP_CLI_VERSION}.phar"`. Resolve the newest stable release from `https://api.github.com/repos/wp-cli/wp-cli/releases/latest`, download the phar, check it against the checksum file published on that release, compute `shasum -a 256`, and pin both values. A comment beside the pin says the SHA was computed from a download verified against the release's own checksum, the same discipline `ci.yml` applies to action SHAs.
3. Variables with defaults: `SMOKE_DIR="$PWD/.smoke"`, `SMOKE_DB_NAME="${SMOKE_DB_NAME:-wordpress_smoke}"`, `DB_USER="${DB_USER:-root}"`, `DB_PASS="${DB_PASS:-}"`, `DB_HOST="${DB_HOST:-127.0.0.1}"`, `WP_VERSION="${WP_VERSION:-latest}"`, `SMOKE_URL="${SMOKE_URL:-http://smoke.test}"`. Refuse with exit 1 and a message when `SMOKE_DB_NAME` equals the PHPUnit suite's database name (`wordpress_test`), written as a `[ "$SMOKE_DB_NAME" = "..." ]` test, never as an assignment or default. Export `WP_CLI_CACHE_DIR="$SMOKE_DIR/cache"`.
4. `WP=( php -d display_errors=stderr -d log_errors=0 "$SMOKE_DIR/wp-cli.phar" --path="$SMOKE_DIR/wordpress" --allow-root )`. Every WP-CLI call is `"${WP[@]}" ...`.
5. `download()` helper: curl if present, else wget, else `php -r 'copy($argv[1], $argv[2]);'`. Fetch the phar only when it is missing or fails the checksum; verify with `sha256sum` when available, else `shasum -a 256`. On mismatch delete the file, print expected and actual digests, exit 1.
6. If `$SMOKE_DIR/wordpress/wp-load.php` is missing, `mkdir -p` and `"${WP[@]}" core download --version="$WP_VERSION"`. Otherwise reuse the files (re-provisioning must be cheap).
7. Remove `wp-config.php` and `wp-content/secrets.php` if present, so every run starts single-site with no drop-in. `"${WP[@]}" config create --dbname="$SMOKE_DB_NAME" --dbuser="$DB_USER" --dbpass="$DB_PASS" --dbhost="$DB_HOST" --skip-check --force`.
8. Drop and recreate the database with `php -r` and `mysqli`: split `DB_HOST` on `:` into host and either a numeric port or a socket path (mirror `install_db()` in `bin/install-wp-tests.sh`); connect with no database selected; run ``DROP DATABASE IF EXISTS `name` `` then ``CREATE DATABASE `name` ``; on any error write the `mysqli` error to stderr and exit 1. Pass host, user, password, and name as `$argv` arguments, never interpolated into the PHP source.
9. `"${WP[@]}" core install --url="$SMOKE_URL" --title="Secrets API smoke" --admin_user=smoke --admin_password="$(php -r 'echo bin2hex(random_bytes(16));')" --admin_email=smoke@example.com --skip-email`.
10. `ln -sfn ../../../.. "$SMOKE_DIR/wordpress/wp-content/plugins/secrets-api"`. Relative on purpose: the same link resolves on the host and inside the wp-env container. Then `"${WP[@]}" plugin activate secrets-api`.
11. `KEY="$("${WP[@]}" secret generate-key)"`, then `"${WP[@]}" config set WP_SECRETS_KEY "$KEY" --type=constant`. Never print `$KEY`.
12. Last line: `echo "Smoke install ready: $SMOKE_DIR/wordpress (database $SMOKE_DB_NAME)"`.
Makefile: add `SMOKE_DB_NAME ?= wordpress_smoke` beside the other `DB_*` variables; add `smoke` to `.PHONY`; add target `smoke: ## Provision the throwaway install and run the WP-CLI smoke test.` whose body is `SMOKE_DB_NAME=$(SMOKE_DB_NAME) DB_USER=$(DB_USER) DB_PASS="$(DB_PASS)" DB_HOST=$(DB_HOST) WP_VERSION=$(WP_VERSION) bin/smoke-install.sh`. Do not add `smoke` to `ci` in this task.
`.gitignore`: append `/.smoke/` under a comment (`# The WP-CLI smoke test's throwaway install; see bin/smoke-install.sh.`).
`phpcs.xml.dist`: add `<exclude-pattern>/.smoke/*</exclude-pattern>` with the reason as an XML comment on the same line (the install contains all of WordPress core and a symlink back to this repository; phpcs must never walk into it). Without this, `phpcs` with `<file>.</file>` would scan core and follow the symlink cycle.
**Acceptance tests:** there is no PHPUnit surface; the script's own run is the test. Inside wp-env (commands under Verification): the script exits 0; `core is-installed` exits 0; `plugin is-active secrets-api` exits 0; `config get WP_SECRETS_KEY` prints 44 characters; running the script a second time exits 0 and leaves a single-site install with no drop-in. Checksum path: change one hex digit of `WP_CLI_SHA256` in the working tree, delete `.smoke/wp-cli.phar`, run the script, confirm exit 1 with the mismatch message, restore the digit. Record both results in the commit message under `Tests:`.
**Out of scope:** `tests/smoke/smoke.sh`; adding `smoke` to `make ci`, `bin/ci-local.sh`, or `ci.yml`; any `wp db` subcommand; multisite; any edit under `docs/`.
**Verification:** `npx @wordpress/env start` if not already running; then `npx @wordpress/env run --env-cwd=wp-content/plugins/cli-smoke cli env DB_HOST=mysql DB_USER=root DB_PASS=password bash bin/smoke-install.sh`; then `npx @wordpress/env run --env-cwd=wp-content/plugins/cli-smoke cli php .smoke/wp-cli.phar --path=.smoke/wordpress core is-installed`; same shape for `plugin is-active secrets-api` and `config get WP_SECRETS_KEY`; re-run the install script and confirm exit 0; `git status` shows `.smoke/` untracked-and-ignored; `bin/ci-local.sh --keep`; `make reference-check`.
**Depends on:** none

### P1-02: Push phase 1 and record the manual check
**Goal:** Push the branch with the install harness and record what only a human can verify.
**Files touched:** `docs/PROGRESS.md` (log entry only).
**Design constraints:** docs/SPEC.md §8 ("Every phase ends by pushing the branch"); docs/SPEC.md §3 "Never publish" (push the branch only; no tag).
**Acceptance tests:** none new.
**Out of scope:** any code change.
**Verification:** `git push origin HEAD`; `git status` clean. Append to the progress log: `Manual check: NOT VERIFIED (human)` followed by the list: (1) `make smoke` on a host without Docker, with MySQL on `127.0.0.1` and `DB_PASS` set as needed, provisions the install; (2) the WP-CLI pin matches the release page by eye.
**Depends on:** P1-01

## Phase 2 — Registration cases (A) and the TAP helpers

### P2-01: Create smoke.sh with the TAP helpers and the has-command matrix
**Goal:** `tests/smoke/smoke.sh` exists with configuration, TAP helpers, the run/assert contract, a summary trap, and case A's registration matrix (11 subcommands under both `secret` and `network-secret`), and `make smoke` runs it after the install.
**Files touched:** `tests/smoke/smoke.sh` (new, `chmod +x`), `Makefile`.
**Design constraints:** detailed spec "Shape" first bullet (bash, `ok`/`not_ok` TAP lines, no bats, non-zero exit on any failure), "Each run namespaces its secrets as `smoke-<pid>/…`", case A first bullet; docs/SPEC.md §3 "No plaintext in output" (diagnostics never print stdout, a value, a key, or a command line); Conventions above (layout markers, helper contract, bash 3.2 portability, `WP` array).
Implementation:
1. `#!/usr/bin/env bash`, `set -euo pipefail`, `cd "$(dirname "$0")/../.."`. A header comment: what the script covers, that it needs `bin/smoke-install.sh` to have run, and that it never touches wp-env's own `wp-content`.
2. `# --- configuration ---`: `SMOKE_DIR="$PWD/.smoke"`, `SMOKE_URL="${SMOKE_URL:-http://smoke.test}"`, `export WP_CLI_CACHE_DIR="$SMOKE_DIR/cache" PAGER=cat WP_CLI_PAGER=cat`, the `WP` array exactly as in Conventions, `NS="smoke-$$"`, `DROPIN="$SMOKE_DIR/wordpress/wp-content/secrets.php"`, counters `N=0 PASS=0 FAIL=0`, `ERR_FILE="$(mktemp)"`. Abort with a clear message if `$SMOKE_DIR/wp-cli.phar` or `$SMOKE_DIR/wordpress/wp-config.php` is missing.
3. `# --- helpers ---`: `ok`, `not_ok`, `diag`, `run`, `assert_status`, `assert_out_eq`, `assert_out_contains`, `assert_out_not_contains`, `assert_err_contains` with the contract in Conventions. `run` executes `"$@"` with stdout captured into `OUT` (trailing newlines stripped), stderr into `$ERR_FILE` then `ERR`, and the exit code into `STATUS`, using `|| STATUS=$?` so `set -e` does not abort. `assert_out_eq`'s diagnostic reports only the two lengths. `not_ok` prints `not ok N - desc` and, when given, `# diag`.
4. A `finish` function installed with `trap finish EXIT`: removes `$ERR_FILE`, prints `1..N` and `# passed P, failed F`, exits 1 if `FAIL` is non-zero or the script exited early with a non-zero status. (P4-02 adds drop-in removal to this same trap.)
5. `# --- case A: registration ---`, `case_a_registration`: `SUBCOMMANDS="set get delete list retire import-option migrate-legacy rotate generate-key health dropin"`; for each, and for each of `secret` and `network-secret`, `run "${WP[@]}" cli has-command "<cmd> <sub>"` then `assert_status 0 "<cmd> <sub> is registered"`. 22 assertions.
6. Empty stubs for `case_b_behaviour`, `case_c_rotation`, `case_d_dropin`, `convert_to_multisite`, `case_e_multisite` under their markers, each a one-line function that does nothing, and `# --- main ---` calling all of them in order.
Makefile: the `smoke` target's recipe gains a second line, `tests/smoke/smoke.sh`.
Contingency: if `wp cli has-command` demonstrably cannot see plugin-registered commands (all 22 fail while `"${WP[@]}" help secret get` exits 0), use `run "${WP[@]}" help <cmd> <sub>` with `assert_status 0` instead, and write why in the commit message and the progress log. Do not silently switch.
**Acceptance tests:** running `tests/smoke/smoke.sh` inside wp-env after the install prints 22 `ok` lines, `1..22`, and exits 0. Sanity check of the harness itself: temporarily add `not_ok "probe"` in `main`, confirm the exit status is 1 and the summary counts one failure, remove it. Record in the commit message.
**Out of scope:** any assertion beyond has-command; the flag table; secrets of any kind; `make ci` wiring.
**Verification:** `npx @wordpress/env run --env-cwd=wp-content/plugins/cli-smoke cli env DB_HOST=mysql DB_USER=root DB_PASS=password bash bin/smoke-install.sh`; `npx @wordpress/env run --env-cwd=wp-content/plugins/cli-smoke cli bash tests/smoke/smoke.sh`; `bash -n tests/smoke/smoke.sh`; `bin/ci-local.sh --keep`; `make reference-check`.
**Depends on:** P1-01

### P2-02: Check the flag table exactly and pin the --version cause
**Goal:** Case A's second and third bullets: every subcommand's SYNOPSIS flag set equals a table written at the top of the script, and `--version=previous` is not a slot selector.
**Files touched:** `tests/smoke/smoke.sh`.
**Design constraints:** detailed spec case A bullets two and three; Decisions "Flag table check → exact-set"; docs/SPEC.md §3 "No plaintext in output"; Conventions (bash 3.2: the table is a newline-separated string of `sub:flags` rows, not an associative array).
Implementation:
1. Under `# --- configuration ---`, after `NS`, the table, with a comment saying a new flag without a row fails the run and so does a flag that vanishes from the synopsis:
   ```
   EXPECTED_FLAGS="
   set:--stdin --porcelain
   get:--slot --reveal --field --format
   delete:--yes
   list:--namespace --fields --field --format
   retire:--yes
   import-option:
   migrate-legacy:--dry-run --name --map --namespace --format
   rotate:--yes
   generate-key:
   health:--format
   dropin:--verbose
   "
   ```
2. Helper `synopsis_flags <sub>`: runs `"${WP[@]}" help secret <sub>`, keeps only the lines between the `SYNOPSIS` header and the next header line (a line starting with an upper-case letter in column 1; WP-CLI word-wraps long synopses onto indented continuation lines, so take every line in the section), extracts `--[a-z-]*` tokens with `grep -o`, and prints them sorted, unique, space-separated. Guard the `grep` with `|| true` so a subcommand with no flags yields an empty string under `pipefail`.
3. In `case_a_registration`, for each row: normalise the expected flags the same way (`tr ' ' '\n' | sort -u`), compare the two strings, `ok`/`not_ok "secret <sub> synopsis flags match the table"`; the diagnostic may print both flag lists (they are flag names, not values).
4. The pin: `run "${WP[@]}" secret set "${NS}/slotpin" "smoke-value-a-$$"`, then `set` again with `smoke-value-b-$$`, then `run "${WP[@]}" secret get "${NS}/slotpin" --version=previous --reveal --field=value`. Assert with `assert_out_not_contains "smoke-value-a-$$" "--version=previous does not select the previous slot"`. Also assert that `--version` is absent from `get`'s synopsis (already implied by the exact-set check; state it in a comment). Delete the secret with `--yes` afterwards.
**Acceptance tests:** inside wp-env the run prints `ok` for the 11 synopsis rows and the pin, and exits 0. Negative check: temporarily remove `--reveal` from the `get` row, run, confirm `not ok`, restore. Record in the commit message.
**Out of scope:** case B assertions; `network-secret` help output.
**Verification:** as P2-01, plus `bash -n tests/smoke/smoke.sh`.
**Depends on:** P2-01

### P2-03: Push phase 2 and record the manual check
**Goal:** Push the branch with case A and record what only a human can verify.
**Files touched:** `docs/PROGRESS.md` (log entry only).
**Design constraints:** docs/SPEC.md §8; §3 "Never publish".
**Acceptance tests:** none new.
**Out of scope:** any code change.
**Verification:** `git push origin HEAD`; `git status` clean. Log `Manual check: NOT VERIFIED (human)`: `make smoke` on a host without Docker runs case A green.
**Depends on:** P2-02

## Phase 3 — Behaviour and exit-code cases (B)

### P3-01: Cover set and get, masking, stdin, porcelain, slots, and JSON
**Goal:** Case B's first four bullets run end to end through the real binary with exit codes checked on every call.
**Files touched:** `tests/smoke/smoke.sh`.
**Design constraints:** detailed spec case B (every case checks the exit code and the output; exit 0 found, 1 absent, 2 error); docs/SPEC.md §3 "No plaintext in output"; Conventions (values start `smoke-value-`, at least 12 characters; diagnostics never print `OUT`).
Implementation in `case_b_behaviour`, using `V1="smoke-value-one-$$"`, `V2="smoke-value-two-$$"`, `S="${NS}/basic"`:
1. `run "${WP[@]}" secret set "$S" "$V1"` → `assert_status 0 "set with a positional value exits 0"`.
2. `printf '%s\n' "$V1" | run "${WP[@]}" secret set "${NS}/stdin" --stdin` does not work through a pipe into a function that captures stdout; instead write the value to a temp file and use `run` with input redirection: implement `run_stdin <file> <cmd...>` (same contract as `run`, stdin from the file) and assert status 0. Then `run "${WP[@]}" secret get "${NS}/stdin" --reveal --field=value` → `assert_status 0`, `assert_out_eq "$V1" "set --stdin stores the piped value with the trailing newline trimmed"`. Remove the temp file.
3. `run "${WP[@]}" secret set "${NS}/porcelain" "$V1" --porcelain` → status 0; `OUT` is exactly one line (no newline inside) and equals `OUT` of `run "${WP[@]}" secret get "${NS}/porcelain" --field=fingerprint`. Description: "set --porcelain prints only the fingerprint".
4. `run "${WP[@]}" secret get "$S"` → status 0, `assert_out_not_contains "$V1" "get masks the value by default"`. `run "${WP[@]}" secret get "$S" --reveal --field=value` → status 0, `assert_out_eq "$V1" "get --reveal --field=value prints exactly the value"`. `run "${WP[@]}" secret get "$S" --reveal` → `assert_out_contains "$V1" "get --reveal shows the value in the table"`.
5. `run "${WP[@]}" secret set "$S" "$V2"` → 0; `run "${WP[@]}" secret get "$S" --slot=previous --reveal --field=value` → 0 and `assert_out_eq "$V1" "get --slot=previous returns the demoted value (bug 1, end to end)"`; `get "$S" --reveal --field=value` → equals `$V2`.
6. `run "${WP[@]}" secret get "$S" --format=json` → 0; feed `OUT` to `php -r 'exit( null === json_decode( stream_get_contents( STDIN ) ) ? 1 : 0 );'` via a temp file and assert exit 0: "get --format=json is valid JSON". Also assert the decoded array has exactly one element whose `name` equals `$S` (a second `php -r` that prints the name; compare with `assert_out_eq`).
**Acceptance tests:** inside wp-env every new assertion prints `ok` and the run exits 0. Record the count of assertions in the commit message.
**Out of scope:** list, retire, delete, generate-key, health, dropin, import-option, migrate-legacy, network-secret refusal (P3-02); rotation; drop-ins.
**Verification:** as P2-01.
**Depends on:** P2-02

### P3-02: Cover list filters, retire, delete, absence, keys, health, dropin, import, migrate, and the single-site refusal
**Goal:** The remaining case B bullets plus the single-site `network-secret` refusal the detailed spec places in this pass.
**Files touched:** `tests/smoke/smoke.sh`.
**Design constraints:** detailed spec case B bullets five to ten and case E last bullet; `docs/spec/network.md` "Single site" (the CLI refuses with `WP_CLI::error`, which exits 1); docs/SPEC.md §3 "No plaintext in output".
Implementation, continuing `case_b_behaviour` after P3-01's block (reuse `S`, `V1`, `V2`):
1. List: `run "${WP[@]}" secret set "${NS}-b/other" "$V1"` (second namespace). `run "${WP[@]}" secret list --format=json` → 0 and valid JSON (same `php -r` check as P3-01). `run "${WP[@]}" secret list --format=csv` → 0 and the first line of `OUT` starts with `name,`. `run "${WP[@]}" secret list --fields=name,fingerprint --format=csv` → first line equals `name,fingerprint`. `run "${WP[@]}" secret list --namespace="$NS" --format=ids` → `assert_out_contains "$S"` and `assert_out_not_contains "${NS}-b/other" "list --namespace filters on the namespace prefix"`. Also assert no `list` output contains `$V1` or `$V2` ("list never shows a value").
2. Retire: `run "${WP[@]}" secret retire "$S" --yes` → 0; `run "${WP[@]}" secret get "$S" --slot=previous` → `assert_status 1 "get --slot=previous exits 1 after retire"`; `get "$S"` still 0.
3. Delete: `run "${WP[@]}" secret delete "$S" --yes` → 0; `run "${WP[@]}" secret get "$S"` → `assert_status 1 "get exits 1 after delete"`.
4. Absence and caller error: `run "${WP[@]}" secret get "${NS}/never-set"` → 1; `run "${WP[@]}" secret set "${NS}/no-value"` → status not 0 (assert `[ "$STATUS" -ne 0 ]`, description "set with no value exits non-zero").
5. `run "${WP[@]}" secret generate-key` → 0; `OUT` has length 44; `php -r 'echo strlen( (string) base64_decode( $argv[1], true ) );' "$OUT"` prints `32`. Never print `OUT` in a diagnostic.
6. `run "${WP[@]}" secret health --format=json` → 0 and valid JSON. `run "${WP[@]}" secret dropin` → 0 and `assert_out_contains "Drop-in active: no" "dropin reports no drop-in"`.
7. Import: `run "${WP[@]}" option add "smoke_${$}_opt" "$V2"` → 0; `run "${WP[@]}" secret import-option "smoke_${$}_opt" "${NS}/imported"` → 0; `run "${WP[@]}" secret get "${NS}/imported" --reveal --field=value` → `assert_out_eq "$V2"`. `run "${WP[@]}" option get "smoke_${$}_opt"` → 0 (the source option is left in place; `docs/spec/import.md` "Copy, not move"). Delete the option afterwards.
8. `run "${WP[@]}" secret migrate-legacy --dry-run` → `assert_status 0 "migrate-legacy --dry-run exits 0 with no prototype rows"`.
9. Single-site refusal: `run "${WP[@]}" network-secret get "${NS}/anything"` → status not 0 and `assert_err_contains "multisite" "network-secret refuses on a single-site install"`.
**Acceptance tests:** inside wp-env every new assertion prints `ok`; total run exits 0. Record the assertion count in the commit message.
**Out of scope:** rotation (C), drop-ins (D), multisite (E), CI wiring.
**Verification:** as P2-01.
**Depends on:** P3-01

### P3-03: Push phase 3 and record the manual check
**Goal:** Push the branch with case B and record what only a human can verify.
**Files touched:** `docs/PROGRESS.md` (log entry only).
**Design constraints:** docs/SPEC.md §8; §3 "Never publish".
**Acceptance tests:** none new.
**Out of scope:** any code change.
**Verification:** `git push origin HEAD`; `git status` clean. Log `Manual check: NOT VERIFIED (human)`: read the full TAP output once by eye for any line that shows a value or key.
**Depends on:** P3-02

## Phase 4 — Rotation (C) and drop-in loading (D)

### P4-01: Rotate the site key end to end
**Goal:** Case C: `rotate` refuses without `WP_SECRETS_KEY_PREVIOUS`, and after moving the key and generating a new one `rotate --yes` succeeds, the value is still readable, and health reports nothing undecryptable.
**Files touched:** `tests/smoke/smoke.sh`.
**Design constraints:** detailed spec case C (first two bullets; third bullet deferred, see Decisions); `docs/spec/rotation.md` "Rotating the site key" (the command requires `WP_SECRETS_KEY_PREVIOUS`, uses it as the old keyring, and re-wraps one value); `docs/spec/envelope-encryption.md` "Site key" (a canonical 44-character base64 value is used raw); docs/SPEC.md §3 "No plaintext in output" (key values are never printed); docs/SPEC.md §3 "Parallel flights" (do not add `--from`).
Implementation in `case_c_rotation`, with `R="${NS}/rotate"`, `VR="smoke-value-rotate-$$"`:
1. Order matters: the refusal runs first, while `WP_SECRETS_KEY_PREVIOUS` is still undefined (the install script recreates `wp-config.php` on every run, so it is). `run "${WP[@]}" secret rotate --yes` → status not 0 and `assert_err_contains "WP_SECRETS_KEY_PREVIOUS" "rotate without WP_SECRETS_KEY_PREVIOUS refuses with its explanatory message"`.
2. `run "${WP[@]}" secret set "$R" "$VR"` → 0. `OLD="$("${WP[@]}" config get WP_SECRETS_KEY)"`; `"${WP[@]}" config set WP_SECRETS_KEY_PREVIOUS "$OLD" --type=constant`; `NEW="$("${WP[@]}" secret generate-key)"`; `"${WP[@]}" config set WP_SECRETS_KEY "$NEW" --type=constant`. Assert the two config writes exit 0 via `run`. Never print `OLD` or `NEW`.
3. `run "${WP[@]}" secret rotate --yes` → `assert_status 0 "rotate --yes exits 0 with the previous key configured"`.
4. `run "${WP[@]}" secret get "$R" --reveal --field=value` → 0 and `assert_out_eq "$VR" "the value still decrypts after rotation"`.
5. `run "${WP[@]}" secret health --format=json` → 0; with `php -r`, decode `OUT`, find the row whose `check` contains `undecryptable`, print its `status`; `assert_out_eq "good" "health reports no undecryptable secrets after rotation"`.
6. A comment after the block: `# rotate --from=config (refused: same keyring on both sides) is added once build/kms-keyring lands --from.`
**Acceptance tests:** inside wp-env all case C assertions print `ok` and the run exits 0. Negative check: temporarily skip step 2's `config set WP_SECRETS_KEY "$NEW"` and confirm step 3 or 4 fails, then restore; record in the commit message.
**Out of scope:** `--from`; any change to `cli/` or `src/`; drop-ins.
**Verification:** as P2-01.
**Depends on:** P3-02

### P4-02: Load drop-ins through the real loader, with cleanup on exit
**Goal:** Case D: four drop-in shapes plus the recorded uncatchable fatal, each written to `wp-content/secrets.php`, asserted, and removed, with an `EXIT` trap that removes any drop-in the script wrote.
**Files touched:** `tests/smoke/smoke.sh`.
**Design constraints:** detailed spec case D (the table, the exit-2-versus-1 sentence, and the uncatchable-fatal paragraph); "an `EXIT` trap removes any drop-in the script wrote" under "Shape"; ADR 0007 (unreachable is exit 2, absent is exit 1; a provider global of the wrong type fails closed); `docs/spec/providers-and-keyrings.md` "Drop-in loading"; Decisions ("dropin reports it broken" means `Provider: WP_Secrets_Broken_Provider`; `-d display_errors=stderr` puts the fatal on stderr).
Implementation:
1. Helpers under `# --- helpers ---`: `write_dropin` reads the file body from stdin (use a quoted heredoc at each call site), writes `$DROPIN`, and sets `WROTE_DROPIN=1`; `remove_dropin` deletes `$DROPIN` if present and clears the flag. Extend the `finish` trap from P2-01 to call `remove_dropin` first, so an aborted run never leaves a drop-in behind.
2. In `case_d_dropin`, first `run "${WP[@]}" secret set "${NS}/dropin" "smoke-value-dropin-$$"` with no drop-in present, so the "sets nothing" row has something to read. Then, for each row, write, assert, `remove_dropin`:
   - Syntax error: body `<?php this is not php`. `run "${WP[@]}" secret get "${NS}/dropin"` → `assert_status 2 "a drop-in with a syntax error makes get exit 2, not 1"`. `run "${WP[@]}" secret dropin` → `assert_out_contains "Provider: WP_Secrets_Broken_Provider" "dropin reports a syntax-error drop-in as broken"`.
   - Throws on load: body `<?php throw new RuntimeException( 'smoke' );`. Same two assertions.
   - Wrong provider type: body `<?php $GLOBALS['wp_secrets_provider'] = new stdClass();`. `get` → 2 ("the 4 September fail-closed fix, through the real loader"); `dropin` → Broken_Provider.
   - Sets nothing: body `<?php // A drop-in that sets no global.`. `get "${NS}/dropin" --reveal --field=value` → 0 and equals the value; `get "${NS}/never-set"` → 1; `dropin` → `assert_out_contains "Drop-in active: yes"` and `assert_out_contains "Provider: WP_Secrets_Libsodium_Provider" "a drop-in that sets nothing leaves the shipped provider in place"`.
   - Uncatchable fatal: body `<?php final class Smoke_Incomplete_Keyring implements WP_Secrets_Keyring {}` (no methods). `run "${WP[@]}" secret get "${NS}/dropin"` → `[ "$STATUS" -ne 0 ]` and `assert_err_contains "Fatal error" "a class missing interface methods is an uncatchable fatal (recorded, not desired)"`. A comment above this row says it is written down as expected behaviour so a future PHP that makes it catchable shows up as a change.
3. After the loop, `run "${WP[@]}" secret dropin` → `assert_out_contains "Drop-in active: no" "no drop-in remains after case D"`.
**Acceptance tests:** inside wp-env all case D assertions print `ok`; `ls .smoke/wordpress/wp-content/secrets.php` fails afterwards. Trap check: insert a temporary `exit 3` right after the first `write_dropin`, run, confirm the file is gone and the exit status is non-zero, remove the `exit`. Record in the commit message.
**Out of scope:** any change to `secrets-api.php` or `src/`; multisite.
**Verification:** as P2-01, plus `test ! -e .smoke/wordpress/wp-content/secrets.php` inside the container after the run.
**Depends on:** P4-01

### P4-03: Push phase 4 and record the manual check
**Goal:** Push the branch with cases C and D and record what only a human can verify.
**Files touched:** `docs/PROGRESS.md` (log entry only).
**Design constraints:** docs/SPEC.md §8; §3 "Never publish".
**Acceptance tests:** none new.
**Out of scope:** any code change.
**Verification:** `git push origin HEAD`; `git status` clean. Log `Manual check: NOT VERIFIED (human)`: run the uncatchable-fatal row on a PHP newer than 8.3 by hand and confirm it is still a fatal.
**Depends on:** P4-02

## Phase 5 — Multisite pass (E) and pipeline wiring

### P5-01: Convert to multisite and run the network pass
**Goal:** After the single-site pass, `smoke.sh` converts the install with `wp core multisite-convert`, network-activates the plugin, creates a second site, and runs case E.
**Files touched:** `tests/smoke/smoke.sh`.
**Design constraints:** detailed spec "Shape" (`make smoke` converts with `wp core multisite-convert` and runs the multisite pass) and case E; `docs/spec/network.md` "Storage" and "Key derivation" (site scope is per blog; network scope is network-wide); Decisions (conversion lives in `smoke.sh`; site 2 slug `smoke2`).
Implementation:
1. `convert_to_multisite`: `run "${WP[@]}" core multisite-convert --title="Secrets API smoke network"` → `assert_status 0 "multisite-convert exits 0"`; `run "${WP[@]}" plugin activate secrets-api --network` → 0; `SITE2_ID="$("${WP[@]}" site create --slug=smoke2 --porcelain)"`; `SITE2_URL="$("${WP[@]}" site list --blog_id="$SITE2_ID" --field=url)"`; assert `SITE2_URL` is non-empty. Export both for case E.
2. `case_e_multisite`, with `VN="smoke-value-network-$$"`, `VS="smoke-value-site2-$$"`:
   - `run "${WP[@]}" network-secret set "${NS}/net" "$VN"` → 0; `run "${WP[@]}" network-secret get "${NS}/net" --reveal --field=value` → 0 and `assert_out_eq "$VN" "network-secret round-trips"`; the same `get` with `--url="$SITE2_URL"` → equals `$VN` ("network scope is visible from every site").
   - `run "${WP[@]}" secret set "${NS}/site" "$VS" --url="$SITE2_URL"` → 0; `run "${WP[@]}" secret get "${NS}/site"` (site 1) → `assert_status 1 "a site-2 secret is invisible from site 1"`; `run "${WP[@]}" secret get "${NS}/site" --url="$SITE2_URL" --reveal --field=value` → equals `$VS`.
   - `run "${WP[@]}" network-secret health --format=json` → 0 and valid JSON.
3. `main` already calls `convert_to_multisite` then `case_e_multisite` last; confirm the order.
**Acceptance tests:** inside wp-env the full run (A to E) exits 0. Re-running `bin/smoke-install.sh` afterwards restores a single-site install (the config is recreated and the database dropped), and a second full run also exits 0. Record both in the commit message.
**Out of scope:** `make ci`, `bin/ci-local.sh`, `ci.yml` (P5-02).
**Verification:** as P2-01, twice in a row (install, smoke, install, smoke).
**Depends on:** P4-02

### P5-02: Wire smoke into make ci, bin/ci-local.sh, and a smoke CI job
**Goal:** `make ci` runs `smoke`, `bin/ci-local.sh` runs the two smoke scripts inside the wp-env `cli` container, and `.github/workflows/ci.yml` gains a `smoke` job on PHP 7.4 and 8.3 that runs after `static`.
**Files touched:** `Makefile`, `bin/ci-local.sh`, `.github/workflows/ci.yml`.
**Design constraints:** detailed spec "Shape" ("`make ci` includes `smoke`", "`bin/ci-local.sh` gets the same target", "The CI job `smoke` runs after `static`, on PHP 7.4 and 8.3, with the same MySQL service as the test jobs"); docs/SPEC.md §7 (in CI use the job's MySQL service; through `ci-local.sh` use wp-env's MySQL from the `cli` container; never `wordpress_test`); docs/SPEC.md §3 "Parallel flights" (edits to `Makefile` and `ci.yml` additive and confined to this flight's own section); `ci.yml`'s pin-by-SHA rule (copy the existing `actions/checkout` and `shivammathur/setup-php` lines verbatim, SHA and version comment included).
Implementation:
1. Makefile: `ci: lint compat analyse reference-check test test-ms smoke` (append only).
2. `bin/ci-local.sh`: after the multisite test step, add
   `echo "==> smoke (WP-CLI against a throwaway install)"`, then
   `"${WP_ENV[@]}" run --env-cwd="$CONTAINER_CWD" cli env DB_HOST=mysql DB_USER=root DB_PASS=password bash bin/smoke-install.sh`, then
   `"${WP_ENV[@]}" run --env-cwd="$CONTAINER_CWD" cli bash tests/smoke/smoke.sh`. A comment explains that the smoke test uses its own install inside `.smoke/` and wp-env's development MySQL, never the tests database, and that the two scripts are run directly because the `cli` container has no `make`.
3. `ci.yml`: a new job after `test-multisite`:
   ```
   smoke:
     name: "Smoke / PHP ${{ matrix.php }}"
     needs: static
     runs-on: ubuntu-latest
     strategy: { fail-fast: false, matrix: { php: ['7.4', '8.3'] } }
     services: (the same mysql block as `test`, with MYSQL_DATABASE: wordpress_smoke)
     steps: checkout (pinned line copied), setup-php with php-version from the matrix,
            extensions sodium and mysqli, coverage none; then
            - run: make smoke DB_HOST=127.0.0.1
   ```
   No Composer step: the smoke test needs neither `vendor/` nor the WordPress test suite. A leading comment on the job says why it exists (three dispatch bugs behind a green suite) and why 7.4 and 8.3 (the floor and the newest supported).
**Acceptance tests:** `bin/ci-local.sh --keep` runs the smoke step and ends with `All green.`; `make -n ci` lists `smoke` last; `ci.yml` parses (`python3 -c 'import yaml,sys; yaml.safe_load(open(".github/workflows/ci.yml"))'` or, if PyYAML is absent, `ruby -ryaml -e 'YAML.load_file(".github/workflows/ci.yml")'`; if neither is available, say so in the commit message).
**Out of scope:** any change to `tests/smoke/smoke.sh` or `bin/smoke-install.sh`; a Moto or Vault service; `make test-examples`.
**Verification:** `bin/ci-local.sh --keep` (now includes the smoke step); `make reference-check`; `grep -n 'uses:' .github/workflows/ci.yml` shows only SHA pins.
**Depends on:** P5-01

### P5-03: Push phase 5 and record the manual check
**Goal:** Push the branch with the multisite pass and the pipeline wiring, and record what only a human can verify.
**Files touched:** `docs/PROGRESS.md` (log entry only).
**Design constraints:** docs/SPEC.md §8; §3 "Never publish".
**Acceptance tests:** none new.
**Out of scope:** any code change; waiting on CI.
**Verification:** `git push origin HEAD`; `git status` clean. If `gh` is available, `gh run list --branch "$(git branch --show-current)" --limit 3` may be recorded, but nothing depends on it. Log `Manual check: NOT VERIFIED (human)`: the `smoke` job is green on PHP 7.4 and 8.3 in the Actions tab; `make smoke` on a host without Docker passes both passes.
**Depends on:** P5-02

## Phase 6 — Regression proof

### P6-01: Prove each historical bug fails the smoke test
**Goal:** For each of the three historical dispatch bugs, reintroduce it in the working tree, confirm the smoke test fails, revert, and record the evidence in the commit message and a comment block in `smoke.sh`.
**Files touched:** `tests/smoke/smoke.sh` (comment block only); `cli/class-wp-cli-secret-command.php` is edited and fully reverted within the task, never committed changed.
**Design constraints:** detailed spec "Done when" second bullet (restore the `--version` flag, remove a `--format` description line, drop the `@subcommand` tag; each checked by hand; result in the commit message); docs/SPEC.md §8.6 ("Never commit the reintroduced bug to the branch's history in a passing state"); `docs/journal/test-coverage-gaps.md` "CLI dispatch" (the three bugs); Decisions (working-tree edits, `git checkout --`, no stash).
Implementation, for each bug in turn, inside wp-env with the install provisioned:
1. Bug 1: in `cli/class-wp-cli-secret-command.php`, rename `get()`'s `[--slot=<slot>]` synopsis entry to `[--version=<version>]` and the `$assoc_args['slot']` reads to `'version'`. Run `tests/smoke/smoke.sh`; expect `not ok` on the `get` synopsis row and on the `--slot=previous` case. Capture the `not ok` lines. `git checkout -- cli/class-wp-cli-secret-command.php`.
2. Bug 2: delete the `: Render output in a particular format.` line under `[--format=<format>]` in `list()`. Run; expect `not ok` on the `list` synopsis row (WP-CLI drops a parameter with no description) and on the `list --format=json` case. Capture. Revert.
3. Bug 3: delete the `@subcommand migrate-legacy` line. Run; expect `not ok` on `secret migrate-legacy is registered` and `network-secret migrate-legacy is registered`. Capture. Revert.
4. `git status` must be clean apart from `tests/smoke/smoke.sh`. Add a comment block under the script's header, `# Regression proof`, listing each bug, the change that reintroduces it, and the assertion(s) that catch it. The commit body's `Tests:` section quotes the captured `not ok` lines for all three.
**Acceptance tests:** the three failing runs (evidence in the commit message); a final green run after all reverts; `git diff HEAD -- cli/` empty before committing.
**Out of scope:** any change to `cli/` that survives the task; any change to `docs/`.
**Verification:** `git diff --stat HEAD -- cli/` prints nothing; full smoke run green inside wp-env; `bin/ci-local.sh --keep`; `make reference-check`.
**Depends on:** P5-02

### P6-02: Push phase 6 and record the manual check
**Goal:** Push the branch with the regression evidence and record what only a human can verify.
**Files touched:** `docs/PROGRESS.md` (log entry only).
**Design constraints:** docs/SPEC.md §8; §3 "Never publish".
**Acceptance tests:** none new.
**Out of scope:** any code change.
**Verification:** `git push origin HEAD`; `git log -1 --format=%B` shows the three quoted `not ok` groups. Log `Manual check: NOT VERIFIED (human)`: a reader confirms the commit message evidence matches the detailed spec's three bugs one to one.
**Depends on:** P6-01

## Phase 7 — Documentation and journal

### P7-01: Update the coverage gaps, the spec pages, and the detailed spec's status
**Goal:** Every existing page whose statements this work changes says what is now true: the coverage-gaps entries the smoke test closes are removed or narrowed, three spec pages' "As built" sections mention the end-to-end coverage, and `tests/smoke/SPEC.md` no longer says "planned".
**Files touched:** `docs/journal/test-coverage-gaps.md`, `docs/spec/scope.md`, `docs/spec/extension-points.md`, `docs/spec/retrieval.md`, `tests/smoke/SPEC.md`; `docs/journal/open-questions.md` and `docs/journal/proposal-questions.md` only if a statement in them is now false.
**Design constraints:** detailed spec "Done when" third bullet (drop the CLI dispatch entry and the `--stdin` entry; narrow the drop-in loading entry to the uncatchable-fatal case alone); docs/SPEC.md §2 (update every page whose statements changed) and §3 "Spec pages" (exactly three sections, "As built" only; "Why" untouched because the code did not depart from the proposal), "Nothing private in `docs/`", "Parallel flights" (additive edits, confined to this flight's own entries); docs/SPEC.md §9 (an interface-signature finding goes to `open-questions.md`, not the code); `CLAUDE.md` "Working in this repository" (journal entries are dated; tracking pages are not).
Implementation:
1. `test-coverage-gaps.md`: delete the whole "🟡 CLI dispatch is not covered by any test" section and the whole "🟢 `wp secret set --stdin`'s own code path" section, including their `---` separators. Rewrite the "🟢 Drop-in file loading" section so its heading and body say only that the uncatchable fatal (a class implementing an interface without all its methods) remains outside automated coverage, that `tests/smoke/smoke.sh` case D now exercises the syntax-error, throw-on-load, wrong-type, and sets-nothing cases through the real `require`, and that the fatal is recorded there as expected behaviour. Keep the empirical PHP 7.4/8.5 note. Leave the frontmatter alone.
2. `docs/spec/scope.md`, "As built", the "WP-CLI" paragraph: append one sentence: both commands are exercised end to end against a real `wp` binary by `tests/smoke/smoke.sh`, on single site and multisite.
3. `docs/spec/extension-points.md`, "As built", the paragraph beginning "There is one gap worth knowing about": append one sentence saying the syntax-error, throw, and wrong-type cases are checked through the real loader by the smoke test, and only the fatal remains manual.
4. `docs/spec/retrieval.md`, "As built", the "Fail closed" paragraph: after the sentence naming the two PHPUnit files, add that `tests/smoke/smoke.sh` case D checks the same fail-closed behaviour through the real loader and the exit-code contract (2, not 1).
5. `tests/smoke/SPEC.md`: change `Status: planned.` to `Status: built.` and add, after the ADR link, a sentence pointing to the journal entry path P7-03 will create (`docs/journal/<date>-testing-the-cli-for-real.md`; use the date the entry will carry, which is the day P7-03 runs — if unsure, write today's date and P7-03 corrects it).
6. Read `open-questions.md` and `proposal-questions.md`. If the build produced no finding that changes an interface or a statement there, change nothing and write "reviewed, no change needed" in the progress log. If it did, add a new heading (open-questions) or a sentence under question 4 (proposal-questions), attributing nothing to any employer, customer, or channel.
**Acceptance tests:** `make reference-check` passes (no docblock changed); each spec page still has exactly the three headings `## As proposed`, `## As built`, `## Why` in that order (`grep -c '^## ' <file>` prints 3); `test-coverage-gaps.md` no longer contains the strings `CLI dispatch` or `--stdin`.
**Out of scope:** README, `docs/reference/ci.md`, `docs/index.md`, the journal entry (P7-02, P7-03); any edit to `docs/reference/functions.md`, `classes.md`, `hooks.md`, or `wp-cli.md` by hand.
**Verification:** the greps above; `bin/ci-local.sh --keep`; `make reference-check`.
**Depends on:** P6-01

### P7-02: Document make smoke in the README and the CI reference
**Goal:** `README.md` and `docs/reference/ci.md` describe `make smoke`, what it needs, and the new CI job.
**Files touched:** `README.md`, `docs/reference/ci.md`.
**Design constraints:** docs/SPEC.md §8.7 (add `make smoke` to `docs/reference/ci.md` since it documents make targets; cover it in `README.md`); docs/SPEC.md §2; docs/SPEC.md §3 "Parallel flights" (additive; `build/kms-keyring` adds `make test-examples` to the same tables, so add one row and one paragraph, no restructuring); Decisions (`ci.md` is hand-maintained).
Implementation:
1. `README.md`, the target table: a row `make smoke` — "provision a throwaway WordPress in `.smoke/` and drive `wp secret` / `wp network-secret` end to end (needs MySQL and network access)". In the "Clone to green" section, one sentence that `bin/ci-local.sh` now also runs the smoke test inside wp-env. In the Contributing section's CI sentence, add the smoke job to the description of what the pipeline runs.
2. `docs/reference/ci.md`: add `make smoke` to the command list at the top with a comment; a short new section "The WP-CLI smoke test" after "Without Docker" covering: what it is (a bash harness against a pinned `wp-cli.phar` and a real install in `.smoke/`), the `SMOKE_DB_NAME` (`wordpress_smoke`), `DB_*`, `WP_VERSION`, and `SMOKE_URL` variables, that it needs egress to GitHub releases and wordpress.org, that through `bin/ci-local.sh` it runs in the `cli` container against wp-env's development MySQL, and that the install is disposable and re-provisioned on every run. Add a `smoke` row to the Matrix table (PHP 7.4, 8.3; WordPress latest; "WP-CLI end to end, single site then multisite"). Under "Pinning", one sentence that `wp-cli.phar` is pinned by version and SHA-256 in `bin/smoke-install.sh`.
**Acceptance tests:** `grep -n 'make smoke' README.md docs/reference/ci.md` shows both; `make reference-check` passes (`ci.md` is not generated, so it must not fail).
**Out of scope:** `docs/index.md`; the journal entry; `examples/README.md` (nothing there changed).
**Verification:** the grep; `bin/ci-local.sh --keep`; `make reference-check`.
**Depends on:** P7-01

### P7-03: Write the journal entry and link it from the index
**Goal:** One dated dev-journal entry tells what was built, what it found, what was left out, and what it means for the Trac patch, and `docs/index.md` lists it.
**Files touched:** `docs/journal/<YYYY-MM-DD>-testing-the-cli-for-real.md` (new), `docs/index.md`, and `tests/smoke/SPEC.md` only to correct the date in the link P7-01 wrote.
**Design constraints:** docs/SPEC.md §2 third goal (path, frontmatter `title`, `description`, `date`; the voice of `docs/journal/2026-09-04-0-1-0-is-public.md`: first person, plain, specific; cover what was built, what it found, what was deliberately left out, what it means for the Trac patch; link to the test and to ADR 0008; do not use the `/journal-entry` skill; do not read or clear `docs/journal/_drafts/notes.md`); docs/SPEC.md §3 "Nothing private in `docs/`"; `CLAUDE.md` "Working in this repository" (dated entries sort by `date:`; `docs/index.md` for any new page); docs/SPEC.md §9 (if a finding changed an interface, say so and point at `open-questions.md`).
Implementation:
1. Date is `date +%F` on the day the task runs. Frontmatter exactly: `title: "Testing the CLI for real"`, a one-sentence `description`, `date: <YYYY-MM-DD>`. Then `# Testing the CLI for real`.
2. Sections in this order, with the voice of the 4 September entry (short paragraphs, no bullet-list padding): what was built (the harness, the install script, the five cases, where it runs); what it found (be concrete: if nothing in `src/` or an interface changed, say that plainly, and name the one thing the harness pinned that PHPUnit could not, the `--version` swallow); what was deliberately left out (`rotate --from`, byte-for-byte output, the examples' providers, the uncatchable fatal); what it means for the Trac patch (the CLI surface that ships with 7.2 now has an end-to-end test that `make ci` runs, and the last 🟡 coverage gap is closed). Link `tests/smoke/smoke.sh` with a relative path from `docs/journal/` and link ADR 0008 as `../decisions/0008-the-trac-ticket-replaces-thread-confirmation.md`.
3. `docs/index.md`, "### journal/": add a line for the entry, in the same shape as the 0.1.0 line, placed after it.
4. If P7-01 wrote a different date into `tests/smoke/SPEC.md`, correct it.
**Acceptance tests:** the file exists at the dated path; `head -5` shows the three frontmatter keys; `grep -c 'smoke.sh' <entry>` is at least 1; `grep -c '0008' <entry>` is at least 1; `grep -n 'testing-the-cli-for-real' docs/index.md tests/smoke/SPEC.md` shows both; `docs/journal/_drafts/notes.md` is unchanged (`git diff --stat -- docs/journal/_drafts` empty).
**Out of scope:** publishing; `npm run docs:build`; touching `site/`.
**Verification:** the greps; `bin/ci-local.sh --keep`; `make reference-check`.
**Depends on:** P7-02

### P7-04: Push phase 7 and record the final manual checks
**Goal:** Push the finished branch and record the human-only checks the specs list.
**Files touched:** `docs/PROGRESS.md` (log entry only).
**Design constraints:** docs/SPEC.md §8.7 ("Manual check: `make smoke` on a clean checkout, marked NOT VERIFIED (human)"); §8 ("Every phase ends by pushing the branch"); §3 "Never publish" (no tag, no `sf publish`).
**Acceptance tests:** none new.
**Out of scope:** any code or docs change; creating a PR by hand (the run-finish step handles that per policy).
**Verification:** `git push origin HEAD`; `git status` clean; `git ls-files | grep -c wp-env.override` prints 0. Log `Manual check: NOT VERIFIED (human)`: (1) `make smoke` on a clean checkout with a local MySQL; (2) the CI `smoke` job green on 7.4 and 8.3; (3) `npm run docs:build` renders the new journal entry in the sidebar in date order; (4) a read of the journal entry for voice and for anything private.
**Depends on:** P7-03

## Spec issues

- **`docs/SPEC.md` §5 says a needed timeout gets "a named constant in the example file".** This flight has no example file; the wording was carried over from the example-flight specs. Resolved: a named upper-case `SMOKE_*` variable at the top of the script that needs it. None is expected.
- **`CLAUDE.md` says never edit `docs/reference/` by hand, but `docs/SPEC.md` §8.7 asks for an edit to `docs/reference/ci.md`.** The generator writes only four files (`functions.md`, `classes.md`, `hooks.md`, `wp-cli.md`); `ci.md`, `migrating-from-displace.md`, and `drop-in-example.php` are hand-written. Resolved: edit `ci.md` directly (P7-02); the four generated files are never hand-edited and `make reference-check` proves it.
- **The detailed spec's third case-C bullet depends on `build/kms-keyring`.** Resolved: deferred with a comment marker (P4-01), per docs/SPEC.md §3 "Parallel flights".
- **"For each flag a subcommand declares, `wp help` shows it" versus "a new flag without a row fails loudly".** Only an exact-set comparison satisfies the second sentence. Resolved under Decisions; the merge with `--from` will need one row edited.
- **"A PHP fatal in stderr" depends on `display_errors`.** PHP's CLI SAPI writes displayed errors to stdout by default and logs them to stderr only when `log_errors` is on. Resolved by invoking the phar with `-d display_errors=stderr -d log_errors=0` (P1-01, P2-01), which makes the stream deterministic on every PHP and in every environment.
- **`wp cli has-command` for `network-secret` on a single-site install** relies on WP-CLI instantiating the command class only at invocation (which the command's own docblock states). P2-01 carries a contingency in case a WP-CLI release changes that.
- **`make ci` now needs network egress (GitHub releases, wordpress.org) and a second database.** The other targets need only the test database. Resolved by documenting it in `docs/reference/ci.md` (P7-02); `bin/ci-local.sh` already needs egress for the first Docker pull.
- **The plugin symlink creates a directory cycle inside the repository** (`.smoke/wordpress/wp-content/plugins/secrets-api` points back at the root). Anything that walks the tree must prune `.smoke/`. `phpcs.xml.dist` gets an exclusion in P1-01; phpstan, the reference generator, and the architecture tests all use explicit paths and are unaffected; git ignores it.
- **`docs/journal/test-coverage-gaps.md` carries a `date:` field although `CLAUDE.md` calls tracking pages undated.** Pre-existing; left alone to keep the merge with the other flights clean.
- **The pre-seeded `docs/foundry.json` has no `constraints`.** Added here; nothing else in the seeded file changed. No `extraVerify` entry is added because `make smoke` on the implementer's host needs a MySQL that is not guaranteed; the smoke run is exercised inside wp-env by each task's Verification and by `bin/ci-local.sh` from P5-02 on.

## Review fixes (round 1)

### R1-01: Preserve the root key across multisite conversion
**Goal:** After a single site is converted to multisite, WP_Secrets_Key_Manager finds the pre-conversion wrapped root key in the main site's options, moves it into network storage, and keeps using it, so secrets written before conversion still decrypt and site-key rotation still works. This is the defect that blocked P5-01: docs/spec/network.md 'Why' promises that conversion does not strand secrets.
**Files touched:** src/wp-includes/class-wp-secrets-key-manager.php, tests/phpunit/test-wp-secrets-key-manager.php, docs/spec/network.md, docs/reference/
**Design constraints:** docs/SPEC.md §3 ('src/ is copy-ready for core': core coding standard, 'default' text domain, @since 7.2.0, no function_exists()/class_exists() guards; tests/phpunit/test-architecture.php enforces this; 'Errors, not exceptions'; 'No plaintext in output': never log or put the root key, wrapped or raw, into a WP_Error message; 'Tests only get stronger'; 'Generated reference': if a docblock changes, run make reference and commit the result). docs/SPEC.md §6: do not change any interface signature. WP_Secrets_Key_Manager is a final class, not an interface; keep get_root_key() and rotate_site_key() public signatures unchanged. docs/spec/network.md 'Key derivation' and 'Why'. Behaviour: add one private helper that returns the wrapped root key. It reads get_site_option( ROOT_KEY_OPTION ). If that returns false and is_multisite() is true, it reads the same option from the main site (get_blog_option( get_main_site_id(), ROOT_KEY_OPTION )). If that is a string, it calls add_site_option() with it, deletes the main site's copy only when add_site_option() succeeded (so no stale wrapped copy survives a later rotation), and returns get_site_option()'s value (which handles a concurrent request that added first). Both get_root_key() and rotate_site_key() go through this helper. Generate a new key only when neither location holds one. The single-site path must not change. Spec page edit: add one or two sentences to network.md 'As built' (the Key derivation paragraph that says where the root key is stored) describing the conversion move. Keep exactly three '## ' sections and leave 'Why' untouched, because the code now matches it.
**Acceptance tests:** tests/phpunit/test-wp-secrets-key-manager.php, multisite-only tests skipped on single site with markTestSkipped as the existing multisite tests in that file do: (1) test_get_root_key_adopts_a_pre_conversion_root_key: get a root key, then simulate conversion by moving the wrapped value from sitemeta into the main site's options (update_blog_option on get_main_site_id(), delete_site_option). A fresh WP_Secrets_Key_Manager's get_root_key() returns the same 32 bytes (compare with hash_equals or bin2hex, never print), sitemeta now holds the row, and the main site's option row is gone. (2) test_secret_written_before_conversion_still_decrypts: wp_set_secret on the main site, simulate conversion, reset any cached manager or provider state the suite's base class exposes, and wp_get_secret returns the original value. (3) test_rotate_site_key_works_after_conversion_before_any_read: simulate conversion, then rotate_site_key( old, new ) returns true and the derived master keys are unchanged. (4) Keep test_get_root_key_generates_and_persists_one green: with neither row present, a new key is still generated. Each of (1) to (3) must fail on the current code, where get_root_key() generates a new key. Confirm this by running them before implementing the fix and record the failures in the commit message.
**Out of scope:** tests/smoke/smoke.sh (P5-01 resumes in a later round, once a reviewer unblocks it); plugin/; any interface file; the CLI; the journal entry and other docs pages (P7-01 and P7-03 cover them); an ADR (this fixes code to match an already-published design and does not change the design).
**Verification:** npx @wordpress/env run --env-cwd=wp-content/plugins/cli-smoke tests-cli env WP_MULTISITE=1 vendor/bin/phpunit -c phpunit-multisite.xml.dist tests/phpunit/test-wp-secrets-key-manager.php; the same file single-site; bin/ci-local.sh --keep; make reference-check; grep -c '^## ' docs/spec/network.md prints 3; git diff main -- src/wp-includes/interface-*.php is empty. Manual reproduction inside wp-env on the smoke install: bin/smoke-install.sh, then with the pinned phar: secret set a probe, core multisite-convert, plugin activate secrets-api --network, then secret get --reveal --field=value returns the probe (exit 0, not 2). Record the result, without the value, in the commit message.
**Depends on:** none

### R1-02: Make the smoke list-value and rotation assertions able to fail
**Goal:** The 'list never shows a value' checks run against list outputs that could actually contain a value, and case C asserts that WP_SECRETS_KEY really changed before rotating, so the harness cannot silently stop exercising a real rotation.
**Files touched:** tests/smoke/smoke.sh
**Design constraints:** tests/smoke/SPEC.md case B ('list --format=json, --format=csv, --fields, and --namespace all filter as documented') and case C; PLAN P3-02 step 1 ('no list output contains $V1 or $V2'); PLAN P4-01 negative check; Conventions (bash 3.2; diagnostics never print OUT, a value, or a key; every WP-CLI call through "${WP[@]}"); docs/SPEC.md §3 'No plaintext in output' and 'Tests only get stronger' (keep every existing assertion; only add or move). List: right after each of the default-table list run (add one: secret list), the list --format=json run, and the list --format=csv run, assert that OUT contains neither the first nor the second test value ('list <format> never shows a value'). The existing ids-format assertions may stay. Rotation: after the two config set calls, read WP_SECRETS_KEY and WP_SECRETS_KEY_PREVIOUS back with config get into local variables, compare them in shell, and emit ok/not_ok 'WP_SECRETS_KEY differs from WP_SECRETS_KEY_PREVIOUS before rotate' with a diagnostic that prints neither value. Unset the variables afterwards.
**Acceptance tests:** tests/smoke/smoke.sh itself, run inside wp-env after bin/smoke-install.sh: all assertions ok, exit 0; record the new total in the commit message. Negative checks, done in the working tree and reverted with git checkout -- tests/smoke/smoke.sh before committing the real change: (a) point one new list-value needle at the secret's name, which does appear in json and csv output, and confirm not ok, proving the assertion reads the right output; (b) comment out the config set WP_SECRETS_KEY "$new" line and confirm the new key-differs assertion reports not ok (the plan's original P4-01 negative check now fails as intended). Record both in the commit message.
**Out of scope:** cli/, src/; convert_to_multisite and case_e_multisite (P5-01); CI wiring (P5-02); any output formatting assertion beyond value absence.
**Verification:** npx @wordpress/env run --env-cwd=wp-content/plugins/cli-smoke cli env DB_HOST=mysql DB_USER=root DB_PASS=password bash bin/smoke-install.sh; npx @wordpress/env run --env-cwd=wp-content/plugins/cli-smoke cli bash tests/smoke/smoke.sh; bash -n tests/smoke/smoke.sh; bin/ci-local.sh --keep; make reference-check.
**Depends on:** none

### R1-03: Widen the smoke diagnostic constraint to the variables the suite uses
**Goal:** The smoke-diagnostics-never-print-stdout rule catches a not_ok or diag that interpolates any variable tests/smoke/smoke.sh uses to hold plaintext or key material, including the lower-case locals (v1, v2, vr, vd, old, new, porcelain_out), not only upper-case OUT/VALUE/KEY/OLD/NEW names.
**Files touched:** docs/foundry.json, CLAUDE.md
**Design constraints:** Keep docs/foundry.json's baseBranch, branchPrefix, permissionMode, and both verify commands with their timeouts exactly as they are (docs/SPEC.md §7). Change only this rule's pattern and fixtures. Every existing shouldMatch and shouldNotMatch entry must still behave the same, especially 'not_ok "masked" "exit $STATUS"' and the ERR head form. A length diagnostic such as 'output length ${#OUT}' must not match. In CLAUDE.md, edit only the one '## Constraints' bullet that describes this rule so its wording names the new variable set; never touch the '# Working in this repository' section.
**Acceptance tests:** The rule's own fixtures, which foundry_verify self-tests. Add shouldMatch entries 'not_ok "x" "$v1"', 'diag "key was $old"', 'diag "${new}"', 'not_ok "porcelain" "$porcelain_out"', and 'not_ok "rot" "$vr"'. Add shouldNotMatch entries 'not_ok "$desc" "output length ${#OUT} did not match expected length ${#expected}: $desc"' and 'not_ok "x" "exit status was $STATUS"'. The added shouldMatch lines fail against the current pattern (it is case-sensitive and upper-case only) and pass against the widened one. foundry_verify must report the rule ok with zero hits on the current tree.
**Out of scope:** Any change to tests/smoke/smoke.sh or bin/smoke-install.sh; other constraint rules.
**Verification:** foundry_verify: constraint smoke-diagnostics-never-print-stdout has fixture null and zero hits; bin/ci-local.sh --keep; make reference-check.
**Depends on:** none

## Review fixes (round 2)

### R2-01: Make the smoke diagnostic rule catch any key- or value-holding variable
**Goal:** The smoke-diagnostics-never-print-stdout constraint flags a not_ok or diag that interpolates any variable holding plaintext or key material, independent of the exact variable name, so that newly added locals such as current_key, previous_key (tests/smoke/smoke.sh case C, added by R1-02) and P5-01's VN and VS cannot silently reopen the blind spot R1-03 was meant to close.
**Files touched:** docs/foundry.json, CLAUDE.md
**Design constraints:** Keep docs/foundry.json's baseBranch, branchPrefix, permissionMode, and both verify commands with their timeouts exactly as they are (docs/SPEC.md §7). Change only this one rule's pattern, description, and fixtures. Suggested shape: keep the current alternation and add (a) any interpolated identifier containing 'key' or 'value' in any letter case (e.g. [A-Za-z0-9_]*[Kk][Ee][Yy][A-Za-z0-9_]* and the same for value) and (b) the exact names VN and VS; any name-agnostic form that satisfies the fixtures is fine. The rule must still report zero hits on the real tree, so it must not flag the existing diagnostic lines in tests/smoke/smoke.sh (lines using $STATUS, $expected in assert_status, $desc, ${#OUT}, ${#expected}, $ERR head, $expected_sub, and the normalize_flags expected/actual flags diagnostic). If P5-01 has already landed, re-read tests/smoke/smoke.sh for any other new plaintext or key variable and cover it too. In CLAUDE.md edit only the one '## Constraints' bullet describing this rule; never touch the '# Working in this repository' section. Do not edit tests/smoke/smoke.sh.
**Acceptance tests:** The rule's own fixtures, self-tested by foundry_verify. Add shouldMatch: 'not_ok "rot" "$current_key"', 'diag "prev ${previous_key}"', 'diag "$VN"', 'not_ok "site" "$VS"', 'diag "got $some_value"'. Each of these fails to match the current pattern (verify that by reading the current regex, or by adding the fixtures first and seeing foundry_verify report the fixture failure) and matches the new one. Add shouldNotMatch copied verbatim from the real file: 'not_ok "$desc" "exit status was $STATUS, expected $expected: $desc"' and 'not_ok "secret $expected_sub synopsis flags match the table" \'. Every existing shouldMatch and shouldNotMatch entry stays and still passes.
**Out of scope:** tests/smoke/smoke.sh, bin/smoke-install.sh, src/, other constraint rules, CI wiring.
**Verification:** foundry_verify: constraint smoke-diagnostics-never-print-stdout has fixture null and zero hits; all other constraints unchanged; bin/ci-local.sh --keep green; make reference-check clean.
**Depends on:** none

## Review fixes (round 3)

### R3-01: Correct bug 1's documented cause and the other inaccurate claims about what the smoke test found
**Goal:** Every place this branch explains bug 1 says what the real wp binary actually does (WP-CLI passes --version after a command through to the subcommand; the historical symptom came from wp-env run's own argument parsing). Case A pins that behaviour. Case E asserts directly that a secret written before multisite-convert still decrypts after it. The journal entry, the ci.yml job comment, and docs/reference/ci.md make only true claims.
**Files touched:** tests/smoke/smoke.sh, docs/journal/2026-09-24-testing-the-cli-for-real.md, cli/class-wp-cli-secret-command.php, docs/reference/wp-cli.md, docs/reference/ci.md, .github/workflows/ci.yml, docs/foundry.json, CLAUDE.md
**Design constraints:** Evidence gathered in review (docs/REVIEW.md round 3, finding 1): (a) P6-01's commit shows 'not ok 36 - --version=previous does not select the previous slot' when get()'s flag was renamed to --version. That assertion only fails if the real binary delivered --version=previous to the subcommand and printed the previous value. (b) The pinned phar's Runner::back_compat_conversions() maps --version to `cli version` only when empty($args). (c) On the current tree, `php .smoke/wp-cli.phar --path=.smoke/wordpress --allow-root secret get <name> --version=previous` exits 1 with 'Error: Parameter errors:\n unknown --version parameter'. (d) `npx @wordpress/env run cli wp secret get <name> --version=previous` logs "Starting 'wp secret get <name>'": the flag is gone before WP-CLI sees it. State only these verified facts. Do not invent further causes.

1. cli/class-wp-cli-secret-command.php: change only the prose paragraph under get()'s [--slot=<slot>] (currently 'Named --slot rather than --version because WP-CLI consumes `--version` itself...'). The new text says the name avoids the value being dropped by wrappers such as `wp-env run`, which consume --version themselves (the original bug), and avoids confusion with `wp --version`. Do not change the synopsis line, the ': Which stored version to read.' description, the YAML block, or any code. Run `make reference` and commit the regenerated docs/reference/wp-cli.md in the same commit. Never hand-edit it.

2. tests/smoke/smoke.sh:
   a. Header 'Regression proof' block: drop the Foundry ID '(P6-01)'. Rewrite bug 1's paragraph to say that renaming the flag back to --version is caught by the get synopsis row and the --slot=previous rows. It must also say that through the real binary --version=previous then selects the previous slot, which the 4 September symptom (seen through wp-env run) did not, so the reintroduction pins the rename, not the wrapper.
   b. Case A comment at the `--version=previous` row: replace the 'WP-CLI's own global --version flag swallows it' claim with the verified behaviour.
   c. Keep the existing assert_out_not_contains row unchanged. Directly after it, add `assert_status 1 "get --version=previous exits 1: WP-CLI passes the flag to get, which does not declare it"` and `assert_err_contains "unknown --version parameter" "WP-CLI rejects --version as an undeclared get parameter"`.
   d. In convert_to_multisite, before `core multisite-convert`: set `${NS}/preconvert` to a value held in a local whose name contains 'value' (for example `local preconvert_value="smoke-value-preconvert-$$"`), with assert_status 0. Then, after `plugin activate secrets-api --network`: `run "${WP[@]}" secret get "${NS}/preconvert" --reveal --field=value`, then assert_status 0, then `assert_out_eq "$preconvert_value" "a secret written before multisite-convert still decrypts after it"`.
   Never interpolate a value variable into not_ok or diag. The smoke-diagnostics-never-print-stdout rule must still report zero hits. The variable name must contain 'value' so that rule covers it. If a different name is unavoidable, add it to that rule's pattern and shouldMatch fixtures in docs/foundry.json, and update the matching CLAUDE.md ## Constraints bullet.

3. docs/journal/2026-09-24-testing-the-cli-for-real.md:
   a. Rewrite 'What it found' so it reports, as this harness's finding, that the 4 September --version=previous symptom does not reproduce through a real wp. WP-CLI 2.12.0 hands --version after a command to the subcommand. The flag was being dropped by `wp-env run` before WP-CLI saw it. Case A now pins WP-CLI's side (exit 1, 'unknown --version parameter'). Keep --slot, and say why.
   b. Remove the claim that the reintroduction made bug 1 fail 'for the reason it was supposed to'. State what bug 1's reintroduction actually showed.
   c. Replace 'A reviewer caught it when case E first ran' with an accurate account: case E's first run failed on network-secret health, and that was diagnosed and fixed.
   d. Make 'case E asserts on it directly' true by naming the new pre/post-conversion assertion.
   e. 'each command's synopsis flags' becomes 'each `wp secret` subcommand's synopsis flags'.
   Keep the frontmatter, the date, the four-section order, the ADR 0008 link, and the voice of docs/journal/2026-09-04-0-1-0-is-public.md.

4. .github/workflows/ci.yml: comment-only change to the smoke job's leading comment. Name the three dispatch bugs from tests/smoke/SPEC.md 'Why' and test-coverage-gaps history: --version=previous returning the current value, --format rejected for want of a description line, and migrate-legacy unregistered without @subcommand. Remove the drop-in and root-key items and the 'R1-01' task ID. Change no YAML keys, values, or uses: pins.

5. docs/reference/ci.md (hand-maintained, not generated): in 'The WP-CLI smoke test', replace the pointer to docs/journal/test-coverage-gaps.md with a link to docs/journal/2026-09-24-testing-the-cli-for-real.md. Keep the tests/smoke/SPEC.md reference.

No Foundry task IDs (P*-*, R*-*) in any of these files. Tests only get stronger: no existing assertion is removed or loosened. Commit style per CLAUDE.md, body wrapped at 72 columns.
**Acceptance tests:** tests/smoke/smoke.sh gains 5 assertions (139 -> 144):
- Case A: 'get --version=previous exits 1 ...' and 'WP-CLI rejects --version as an undeclared get parameter'. Both would have failed if WP-CLI really consumed --version, as the old comments claimed: the command would not exit 1 with that stderr.
- convert_to_multisite: the pre-conversion set, its read-back exit status, and 'a secret written before multisite-convert still decrypts after it'. This is the direct end-to-end test for the root-key stranding defect. Before the key-manager fix it failed (the get exits 2, as round 1's review reproduced).
Record in the commit message the TAP lines for the five new assertions from a full green run.
**Out of scope:** Renaming --slot or changing any synopsis line, flag, or code path in cli/. Any change under src/ or plugin/. tests/smoke/SPEC.md (the case A 'pins bug 1's cause' wording is a spec issue left to the operator). docs/journal/test-coverage-gaps.md. Verifying or asserting anything about wp-env's internals beyond the observed log line.
**Verification:** foundry_verify with no file scope: all 13 constraints ok (smoke-diagnostics-never-print-stdout zero hits), bin/ci-local.sh --keep ends 'All green.' with '# passed 144, failed 0', make reference-check clean. `grep -rn "WP-CLI consumes\|global --version\|WP-CLI's own global" cli/ tests/smoke/smoke.sh docs/journal/2026-09-24-testing-the-cli-for-real.md docs/reference/ .github/` prints nothing. `grep -n "R1-01\|P6-01" .github/workflows/ci.yml tests/smoke/smoke.sh docs/journal/2026-09-24-testing-the-cli-for-real.md` prints nothing. `git diff HEAD~1 -- .github/workflows/ci.yml` touches only comment lines. `git diff HEAD~1 -- cli/` touches only docblock prose lines.
**Depends on:** none

## Review fixes (round 4)

### R4-01: Correct the journal's account of bug 1's reintroduction and case A's 'cause' comment
**Goal:** The journal entry's 'What it found' paragraph states what reintroducing --version actually showed (P6-01: the real binary delivered --version=previous to get() and returned the previous value; the suite failed on the synopsis row, the --version=previous absence row, and the two --slot=previous rows), with no invented causal link between bug 1 and the root-key defect. Case A's comment in smoke.sh says it pins WP-CLI's side of bug 1, not its cause.
**Files touched:** docs/journal/2026-09-24-testing-the-cli-for-real.md, tests/smoke/smoke.sh
**Design constraints:** Evidence (docs/REVIEW.md round 4, finding 1): P6-01's commit (31961a1) recorded, with get()'s flag renamed back to --version, 'not ok 24 - secret get synopsis flags match the table', 'not ok 36 - --version=previous does not select the previous slot', 'not ok 52 - get --slot=previous exits 0', 'not ok 53 - get --slot=previous returns the demoted value (bug 1, end to end)'. Row 36 only fails if the previous value came back. Exit 1 with 'unknown --version parameter' is the CURRENT tree's behaviour (get() declares --slot), pinned by case A rows 37 and 38.

1. docs/journal/2026-09-24-testing-the-cli-for-real.md: replace the text from 'Reintroducing `--version` by hand and running against the real binary' through '`convert_to_multisite` now asserts directly that a secret set before conversion still decrypts\nafter it.' (currently lines 49-56, the end of the first 'What it found' paragraph) with this text, rewrapped at 100 columns:

'On the current tree, `get --version=previous` reaches `get()`, which declares `--slot` and not `--version`, and WP-CLI rejects it: exit 1, `unknown --version parameter` on stderr. Case A asserts both. Reintroducing `--version` by hand shows the other side: `get()` declares the flag again, the real binary hands it `--version=previous`, and the previous value comes back. The suite still fails, on the synopsis table, the `--version=previous` row, and both `--slot=previous` rows, so it catches the rename, not the 4 September symptom. Separately, case E'\''s first run failed on the network-secret health check after `wp core multisite-convert`, because `WP_Secrets_Key_Manager` did not preserve the root key across the conversion. That is fixed, and `convert_to_multisite` now asserts directly that a secret set before conversion still decrypts after it.'

(The '\'' above is a plain apostrophe: "case E's".) Also rewrap line 20 of the same file ('with no row and a row with no flag both fail loudly. B: the exit-code contract ...', currently 109 columns) so the 'What was built' paragraph's lines are at most 100 columns; change no words there. Change nothing else in the entry: keep the frontmatter, the date, the four-section order, the ADR 0008 link, and every other sentence.

2. tests/smoke/smoke.sh line 280: change the comment clause "# Pin bug 1's cause, not only its fix:" to "# Pin WP-CLI's side of bug 1, not only its fix:" and rewrap that comment block if needed. Change no code, no assertion, no description string.

No Foundry task IDs (P*-*, R*-*) in either file. Commit style per CLAUDE.md, body wrapped at 72 columns.
**Acceptance tests:** No new assertions; this is a documentation correction. Checks that would have caught the original finding: `grep -n 'which does not declare' docs/journal/2026-09-24-testing-the-cli-for-real.md` prints nothing (the old contradictory sentence is gone); `grep -n 'Diagnosing this' docs/journal/2026-09-24-testing-the-cli-for-real.md` prints nothing; `grep -n 'the previous value comes back' docs/journal/2026-09-24-testing-the-cli-for-real.md` prints one line; `grep -n "Pin bug 1's cause" tests/smoke/smoke.sh` prints nothing. The smoke suite still passes 144 of 144 (no assertion removed or changed): `git diff HEAD~1 -- tests/smoke/smoke.sh` touches only comment lines.
**Out of scope:** tests/smoke/SPEC.md (the operator's spec issue). Any code, synopsis, or assertion in cli/ or tests/smoke/smoke.sh. src/, plugin/, docs/reference/, .github/, docs/foundry.json, CLAUDE.md. Any other paragraph of the journal entry beyond the replaced sentences and the line-20 rewrap.
**Verification:** foundry_verify with no file scope: all 13 constraints ok, bin/ci-local.sh --keep ends 'All green.' with '# passed 144, failed 0', make reference-check clean. The four greps under Tests behave as stated. `awk 'FNR>4 && length > 100' docs/journal/2026-09-24-testing-the-cli-for-real.md` prints nothing except link-reference lines, if any. `git diff HEAD~1 --stat` lists only the two files.
**Depends on:** none
