# WP-CLI smoke test build progress
Branch: build/cli-smoke
Started: 2026-09-24T20:47:35.233Z

## Tasks
- [x] P1-01 Provision the throwaway install with a pinned WP-CLI
- [x] P1-02 Push phase 1 and record the manual check
- [x] P2-01 Create smoke.sh with the TAP helpers and the has-command matrix
- [x] P2-02 Check the flag table exactly and pin the --version cause
- [ ] P2-03 Push phase 2 and record the manual check
- [ ] P3-01 Cover set and get, masking, stdin, porcelain, slots, and JSON
- [ ] P3-02 Cover list filters, retire, delete, absence, keys, health, dropin, import, migrate, and the single-site refusal
- [ ] P3-03 Push phase 3 and record the manual check
- [ ] P4-01 Rotate the site key end to end
- [ ] P4-02 Load drop-ins through the real loader, with cleanup on exit
- [ ] P4-03 Push phase 4 and record the manual check
- [ ] P5-01 Convert to multisite and run the network pass
- [ ] P5-02 Wire smoke into make ci, bin/ci-local.sh, and a smoke CI job
- [ ] P5-03 Push phase 5 and record the manual check
- [ ] P6-01 Prove each historical bug fails the smoke test
- [ ] P6-02 Push phase 6 and record the manual check
- [ ] P7-01 Update the coverage gaps, the spec pages, and the detailed spec's status
- [ ] P7-02 Document make smoke in the README and the CI reference
- [ ] P7-03 Write the journal entry and link it from the index
- [ ] P7-04 Push phase 7 and record the final manual checks

## Log
(one entry per task, appended by implement)

### P1-01 — e1e7676
Implemented bin/smoke-install.sh exactly per spec: pinned wp-cli 2.12.0
(SHA256 ce34ddd8...20d85c, resolved+verified against the release's own
checksum file today). WP array matches the plan's shared vocabulary
verbatim; only WP-CLI calls use it. mysqli DB create/drop mirrors
install_db()'s host:port/socket split, args passed via $argv.

Verified inside this worktree's wp-env cli container: script exit 0,
core is-installed / plugin is-active exit 0, config get WP_SECRETS_KEY
prints 44 chars (32 bytes), second run idempotent (exit 0, single-site,
no drop-in), checksum-mismatch path exits 1 with expected/actual
digests. `wp config set ... --quiet` was required to stop WP-CLI's own
success line from echoing the generated key in plaintext.

Environment note (not a script defect): this container's php.ini caps
memory_limit at 128M, and `wp core download`'s extraction needs more
regardless of how wp-cli is invoked (reproduced with the container's
own preinstalled `wp` binary too). Verified full correctness by
temporarily bumping memory_limit for that one manual check only; the
committed script is untouched from the plan's exact WP array.

bin/ci-local.sh --keep and make reference-check both green. phpcs.xml
.smoke exclude confirmed (`vendor/bin/phpcs .` clean, no symlink
cycle).

### P1-02 — c964e29
Pushed build/cli-smoke to origin (new branch, PR link printed by GitHub).
No code changes; this task only carries the phase-1 push and manual
check record.

Push: done (origin/build/cli-smoke).
Manual check: NOT VERIFIED (human)
- make smoke on a host without Docker, MySQL on 127.0.0.1, DB_PASS set
  as needed, provisions the install.
- The WP-CLI pin (2.12.0, SHA256 ce34ddd8...20d85c) matches the release
  page by eye.

### P2-01 — d57c943
tests/smoke/smoke.sh created with the exact layout markers, helper
contract (ok/not_ok/diag/run/assert_*), finish() EXIT trap (P4-02 will
extend it for drop-in cleanup), and case_a_registration covering all
11 subcommands under secret and network-secret via `wp cli
has-command`. Empty stubs added for case_b/c/d, convert_to_multisite,
case_e, called in order from main().

Verified inside wp-env cli container against a fresh
bin/smoke-install.sh run: 22 ok lines, 1..22, passed 22/failed 0, exit
0. Sanity-probed the harness itself with a temporary not_ok, confirmed
23 lines / failed 1 / exit 1, then reverted (never committed).

`wp cli has-command` worked directly; the help-based contingency in
the task was not needed. Makefile's smoke recipe now runs
bin/smoke-install.sh then tests/smoke/smoke.sh.

### P2-02 — 1fdce68
Added EXPECTED_FLAGS table (11 rows, secret only per Decisions),
normalize_flags() and synopsis_flags() helpers, a flag-table loop in
case_a_registration (assertions 23-33), and the --version=previous pin
(assertions 34-37: set A, set B, get --version=previous --reveal
--field=value must not contain value A, delete).

Verified inside wp-env cli container: 37/37 pass, exit 0. Negative
check on the get row (dropped --reveal) reproduced not-ok with the
expected/actual flag lists, reverted uncommitted.

Interpretation: synopsis_flags()'s flag regex needed
--[a-zA-Z][a-zA-Z-]* rather than --[a-zA-Z-]*, because generate-key's
SYNOPSIS-section prose contains a bare em-dash ("-- adding the
constant") that the looser pattern matched as a zero-letter flag.

network-secret's synopsis is untested here, per Decisions ("checked
for secret only").
