# WP-CLI smoke test build progress
Branch: build/cli-smoke
Started: 2026-09-24T20:47:35.233Z

## Tasks
- [x] P1-01 Provision the throwaway install with a pinned WP-CLI
- [x] P1-02 Push phase 1 and record the manual check
- [x] P2-01 Create smoke.sh with the TAP helpers and the has-command matrix
- [x] P2-02 Check the flag table exactly and pin the --version cause
- [x] P2-03 Push phase 2 and record the manual check
- [x] P3-01 Cover set and get, masking, stdin, porcelain, slots, and JSON
- [x] P3-02 Cover list filters, retire, delete, absence, keys, health, dropin, import, migrate, and the single-site refusal
- [x] P3-03 Push phase 3 and record the manual check
- [x] P4-01 Rotate the site key end to end
- [x] P4-02 Load drop-ins through the real loader, with cleanup on exit
- [x] P4-03 Push phase 4 and record the manual check
- [x] P5-01 Convert to multisite and run the network pass
- [x] P5-02 Wire smoke into make ci, bin/ci-local.sh, and a smoke CI job
- [x] P5-03 Push phase 5 and record the manual check
- [x] P6-01 Prove each historical bug fails the smoke test
- [x] P6-02 Push phase 6 and record the manual check
- [x] P7-01 Update the coverage gaps, the spec pages, and the detailed spec's status
- [x] P7-02 Document make smoke in the README and the CI reference
- [x] P7-03 Write the journal entry and link it from the index
- [ ] P7-04 Push phase 7 and record the final manual checks
- [x] R1-01 Preserve the root key across multisite conversion
- [x] R1-02 Make the smoke list-value and rotation assertions able to fail
- [x] R1-03 Widen the smoke diagnostic constraint to the variables the suite uses
- [ ] R2-01 Make the smoke diagnostic rule catch any key- or value-holding variable

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

### P2-03 — a874194
Pushed build/cli-smoke to origin. No code changes.

Push: done (origin/build/cli-smoke).
Manual check: NOT VERIFIED (human)
- make smoke on a host without Docker runs case A green.

### P3-01 — cb8145c
Added run_stdin() helper (same contract as run(), stdin from a file)
and filled in case_b_behaviour: positional set, set --stdin round
trip, set --porcelain (one line, matches get --field=fingerprint),
default masking, --reveal (--field=value and table), --slot=previous
demotion (bug 1 end to end), and --format=json (php -r json_decode
validity + exactly one row named for the secret).

Verified inside wp-env cli container against a fresh install: 58
assertions total (38-58 new), 0 failed, exit 0.

Namespace/value conventions from Conventions section used throughout:
NS="smoke-$$", values "smoke-value-<label>-$$". list, retire, delete,
generate-key, health, dropin, import-option, migrate-legacy,
network-secret refusal, rotation, and drop-ins remain out of scope
here (P3-02 and later).

### P3-02 — fe509b7
Extended case_b_behaviour with list (JSON/CSV validity, fields header,
--namespace prefix filter, never a value across both namespaces),
retire, delete, absence (never-set get, no-value set), generate-key
(44 chars / 32 bytes), health/dropin (JSON validity, "Drop-in active:
no"), import-option (copy not move: source option still present),
migrate-legacy --dry-run, and network-secret's single-site refusal
(non-zero exit, stderr contains "multisite", from WP_CLI::error()'s
"Network secrets require a multisite installation.").

Verified inside wp-env cli container against a fresh install: 92
assertions total (59-92 new), 0 failed, exit 0. bin/ci-local.sh --keep
and make reference-check both green.

Rotation (C), drop-ins (D), multisite (E), and CI wiring remain out of
scope, per the task.

### P3-03 — 7b1acc3
Pushed build/cli-smoke to origin. No code changes.

Push: done (origin/build/cli-smoke).
Manual check: NOT VERIFIED (human)
- Read the full TAP output by eye for any line showing a value or
  key: every case-B run this phase passed cleanly (92/92), so no
  not_ok diagnostic ever printed; passing lines are descriptions
  only, never OUT.

### P4-01 — eeea787
Implemented case_c_rotation in tests/smoke/smoke.sh: refusal before
WP_SECRETS_KEY_PREVIOUS exists (with message check), the two config
writes, rotate --yes, get --reveal decrypting the original value, and
health --format=json showing "good" on the decrypt-check row (matched
on "decrypt" since the JSON check column holds Site Health label text,
not the literal word "undecryptable").

Verified against a real wp-env mariadb-backed smoke install: full
suite 102 assertions, 0 failed, exit 0. bin/ci-local.sh --keep and
make reference-check both green.

Interpretation: the task's prescribed negative check (skip setting
WP_SECRETS_KEY to $new, expect step 3/4 to fail) does not actually
fail — WP_SECRETS_KEY_PREVIOUS ends up equal to WP_SECRETS_KEY, and
docs/spec/rotation.md's site-key rotation has no requirement that old
and new differ, so it's a legitimate no-op that succeeds. Verified
this by hand (disabled the line, reran, 102/102 still passed,
reverted). Recorded in the commit message; no test encodes this as
an assertion since it isn't a defect.

### P4-02 — d92a115
Implemented case_d_dropin: write_dropin/remove_dropin helpers (finish
EXIT trap now calls remove_dropin first), and 15 assertions covering
the four drop-in shapes (syntax error, throws on load, wrong-type
provider global -- all exit 2 via WP_Secrets_Broken_Provider; sets
nothing behaves exactly as no drop-in) plus the recorded uncatchable
fatal (class implementing WP_Secrets_Keyring with no methods: exit
non-zero, "Fatal error" on stderr) and a final "no drop-in remains"
check.

Verified against a real wp-env mariadb-backed smoke install: full
suite 117 assertions, 0 failed, exit 0; confirmed the drop-in file is
gone afterward. bin/ci-local.sh --keep and make reference-check both
green.

Manual trap check done as specified: inserted `exit 3` after the
first write_dropin, reran, confirmed the drop-in file was removed and
exit status was non-zero, reverted (diff-verified byte-identical to
pre-edit).

### P4-03 — 5948fe9
Push: pushed to origin/build/cli-smoke (3578368..5948fe9).

Manual check: NOT VERIFIED (human)
- Run the uncatchable-fatal drop-in row (a class implementing
  WP_Secrets_Keyring with no methods) on a PHP newer than 8.3 and
  confirm it is still a fatal, not a catchable TypeError/Error. Note:
  this run's own smoke pass already exercised that row under PHP
  8.5.10 and it fataled as expected (assertion 116), but the task
  calls for a human to do this check explicitly against whichever PHP
  versions the project targets.

### P5-01 — blocked
BLOCKED: Tried: implemented convert_to_multisite and case_e_multisite exactly as the task
describes (multisite-convert, network-activate, site create --slug=smoke2,
network-secret round trip, site-scope invisibility across sites,
network-secret health --format=json). 130 of 131 assertions pass; the last
one (network-secret health --format=json exits 0) fails because health
halts with exit 1 (a "critical: Some secrets cannot be decrypted" row).

What fails: every site-scope secret created in cases A-D (before
multisite-convert) becomes permanently undecryptable the moment the
install converts to multisite -- not "would need re-encryption", but
genuinely lost. Verified directly: `_wp_secrets_root_key` lives in
wp_options pre-conversion; `wp core multisite-convert` never migrates
that row into wp_sitemeta, so WP_Secrets_Key_Manager::get_root_key()'s
`get_site_option()` call finds nothing post-conversion and silently
calls generate_root_key(), creating a brand-new root key (confirmed: a
second, different `_wp_secrets_root_key` row appears in wp_sitemeta
while the original wp_options row is orphaned). Every master key
derived from the old root key is gone with it.

This directly contradicts docs/spec/network.md's own "Why" section:
"converting a single site into a network does not strand its secrets."
As built, it does. This is a src/ defect (no migration hook exists
anywhere for `_wp_secrets_root_key` across `wp core multisite-convert`;
grepped secrets-api.php and all of src/ for any multisite-convert or
wpmu_new_blog handling -- there is none), not a smoke-test defect, and
this task's Files touched is tests/smoke/smoke.sh only, so I can't fix
it here. Retiring/deleting the pre-conversion secrets in smoke.sh
before the health check would make the assertion pass but would hide a
real, spec-contradicting data-loss bug rather than surface it, which is
exactly what this smoke suite exists to catch.

What I think the fix is: on activation/upgrade (or via an explicit
`wp secret migrate-root-key`-style step), copy `_wp_secrets_root_key`
from `wp_options` into `wp_sitemeta` (site_id = 1) before or during
`wp core multisite-convert`, or have `get_root_key()` fall back to
`get_option()` before generating a new one when `is_multisite()` and
`get_site_option()` returns false. That's a src/wp-includes change,
out of scope for this task and this flight's touched-files list; needs
routing to whichever flight owns src/wp-includes/class-wp-secrets-key-manager.php
(kms-keyring or vault-provider per the parallel-flights split) or a
PLAN update authorizing a src/ fix here.

### P5-02 — skipped
SKIPPED: depends on P5-01

### P5-03 — skipped
SKIPPED: depends on P5-02

### P6-01 — skipped
SKIPPED: depends on P5-02

### P6-02 — skipped
SKIPPED: depends on P6-01

### P7-01 — skipped
SKIPPED: depends on P6-01

### P7-02 — skipped
SKIPPED: depends on P7-01

### P7-03 — skipped
SKIPPED: depends on P7-02

### P7-04 — skipped
SKIPPED: depends on P7-03

### R1-01 — fe08d2a
Added private get_wrapped_root_key() helper to WP_Secrets_Key_Manager,
used by both get_root_key() and rotate_site_key(). On a get_site_option()
miss under is_multisite(), it falls back to get_blog_option(main_site_id,
ROOT_KEY_OPTION), adopts it via add_site_option(), deletes the main-site
copy only on successful adoption, and re-reads get_site_option() to
handle a concurrent adopter. Public signatures unchanged; single-site
path unchanged (is_multisite() short-circuits).

Tests added (multisite-gated, markTestSkipped on single site):
test_get_root_key_adopts_a_pre_conversion_root_key,
test_secret_written_before_conversion_still_decrypts,
test_rotate_site_key_works_after_conversion_before_any_read. All three
confirmed failing pre-fix, passing post-fix. Full suite green
single-site and multisite (21/21 this file, 459/459 overall via
bin/ci-local.sh --keep). make reference-check clean.

docs/spec/network.md: added two sentences to "As built" Key derivation
describing the conversion adoption; still exactly 3 sections, Why
untouched.

Manual repro in wp-env: secret set probe -> core multisite-convert ->
plugin activate secrets-api --network -> secret get --reveal returned
the probe, exit 0.

### R1-02 — 42154c8
Added a default-table `secret list` run plus not-contains asserts for
$v1/$v2, and moved/added not-contains asserts right after the
list --format=json and list --format=csv runs (before any later run()
call overwrites $OUT). Case C: added config-get reads of WP_SECRETS_KEY
and WP_SECRETS_KEY_PREVIOUS after both config-set calls, asserting they
differ before rotate --yes.

125 assertions pass, exit 0 (was 121).

Negative check (a): pointed the csv needle at $s (the secret's own
name) -- not_ok as expected (124 passed). Used --format=csv rather
than --format=json for this check: WP-CLI's json_encode escapes "/" as
"\/", so a namespaced name never appears as a raw substring of JSON
output, only CSV. Reverted before commit.

Negative check (b): sandbox declined to run smoke.sh with the
`config set WP_SECRETS_KEY "$new"` line commented out (flagged as
"Security Test Removal"); logged as pipeline friction and worked
through by hand instead -- skipping that line leaves WP_SECRETS_KEY at
$old, equal to the value just written into WP_SECRETS_KEY_PREVIOUS, so
the new != check would report not_ok. Reverted (never actually
applied) before commit.

bin/ci-local.sh --keep and make reference-check both green.

### R1-03 — 1643f2e
Widened smoke-diagnostics-never-print-stdout's pattern in
docs/foundry.json to also match the suite's lower-case locals (v1,
v2, vr, vd, old, new, porcelain_out) alongside the existing
OUT/VALUE*/KEY*/OLD/NEW. Added the 5 required shouldMatch fixtures
and 2 shouldNotMatch fixtures (length diagnostic, $STATUS); all pass
under foundry_verify with zero real hits against the tracked tree.
baseBranch/branchPrefix/permissionMode/verify commands unchanged.

CLAUDE.md: updated only the one '## Constraints' bullet describing
this rule; the '# Working in this repository' / Documentation section
is untouched.

bin/ci-local.sh --keep and make reference-check both green.

### P5-01 — unblocked (round 2)
Blocker was the src/ root-key-stranding defect on multisite conversion; R1-01 fixed it (get_wrapped_root_key adopts the main site's row), mutation-tested and verified end to end by the reviewer: after multisite-convert + network activate, secret get returns the pre-conversion value and network-secret health --format=json exits 0.

### P5-02 — unblocked (round 2)
Skipped only as a dependent of P5-01, which is unblocked this round.

### P5-03 — unblocked (round 2)
Skipped only as a dependent of P5-01, which is unblocked this round.

### P6-01 — unblocked (round 2)
Skipped only as a dependent of P5-01, which is unblocked this round.

### P6-02 — unblocked (round 2)
Skipped only as a dependent of P5-01, which is unblocked this round.

### P7-01 — unblocked (round 2)
Skipped only as a dependent of P5-01, which is unblocked this round. Note: this flight now changed src/ (R1-01); docs/spec/network.md 'As built' already records it.

### P7-02 — unblocked (round 2)
Skipped only as a dependent of P5-01, which is unblocked this round.

### P7-03 — unblocked (round 2)
Skipped only as a dependent of P5-01, which is unblocked this round. Note: per docs/SPEC.md §2 the journal entry must cover the multisite-conversion root-key defect the harness found and the R1-01 fix to class-wp-secrets-key-manager.php (step 2's 'if nothing in src/ changed' branch no longer applies).

### P7-04 — unblocked (round 2)
Skipped only as a dependent of P5-01, which is unblocked this round.

### P5-01 — 53619ee
Implemented convert_to_multisite (multisite-convert, network-activate,
site create --slug=smoke2, exports SITE2_ID/SITE2_URL) and
case_e_multisite (network-secret round-trip, network scope visible
from site 2, site-scope secret invisible from site 1 / visible from
site 2 with --url, network-secret health --format=json valid JSON).
14 new assertions, 139 total, 0 failed.

Verified against a live wp-env install: ran bin/smoke-install.sh then
smoke.sh (139/139, exit 0), reran bin/smoke-install.sh (confirms it
restores single-site) and smoke.sh again (139/139, exit 0) -- both
runs pass, as required. foundry_verify green: all constraints,
bin/ci-local.sh --keep (459/459 single-site, 459/459 multisite via
PHPUnit), make reference-check clean.

No interpretation choices beyond the task's own numbered steps.

### P5-02 — 9822d97
Makefile: appended smoke to ci: target. bin/ci-local.sh: added an
"==> smoke" step after test-ms, running bin/smoke-install.sh then
tests/smoke/smoke.sh in the cli container (never tests-cli, never
wordpress_test) with a comment explaining why. ci.yml: new smoke job
after test-multisite, needs: static, matrix php 7.4/8.3, mysql
service (MYSQL_DATABASE: wordpress_smoke), checkout/setup-php lines
copied verbatim (SHA pins unchanged), no Composer step, single
`make smoke DB_HOST=127.0.0.1` run step.

Verified: bin/ci-local.sh --keep ran end to end (lint, compat,
analyse, test 459/459, test-ms 459/459, smoke 139/139) and printed
"All green."; make -n ci lists smoke last; ci.yml parses via
`ruby -ryaml` (PyYAML absent on this host, noted per the task);
grep -n 'uses:' shows only SHA pins; make reference-check clean.

No interpretation choices; followed the task's snippets directly.

### P5-03 — e3879a6
Pushed build/cli-smoke to origin (d659ce0..b9280df, fast-forward, no
force) before this empty task commit; pushed again after. git status
clean throughout aside from PROGRESS.md's own in-flight state. gh
not invoked (nothing depends on it).

Manual check: NOT VERIFIED (human): the smoke job is green on PHP
7.4 and 8.3 in the Actions tab; make smoke on a host without Docker
passes both passes.

### P6-01 — 31961a1
Added a "Regression proof" comment block under smoke.sh's header
documenting each of the three historical bugs, the exact edit that
reintroduces it, and the assertion(s) that catch it. No functional
change to smoke.sh's logic.

For each bug: edited cli/class-wp-cli-secret-command.php by hand
inside wp-env, ran a fresh install + smoke.sh, captured not-ok
lines, then git checkout -- cli/class-wp-cli-secret-command.php.
Bug 1 (--slot renamed to --version): 4 failures (synopsis row,
--version=previous no-op check, both --slot=previous assertions).
Bug 2 (list --format description line deleted): 9 failures
(synopsis row + every list --format=* case). Bug 3 (@subcommand
migrate-legacy deleted): 4 failures (both registration rows,
synopsis row, migrate-legacy --dry-run). Full text in the commit.

git diff --stat HEAD -- cli/ empty before committing. Final green
run: 139/139, exit 0. bin/ci-local.sh --keep and make reference-check
both clean.

### P6-02 — 3b9e557
Pushed build/cli-smoke to origin (f4df960..01f0253, fast-forward, no
force) before this empty task commit; pushed again after.
git log -1 --format=%B on 31961a1 (P6-01) shows the three quoted
not-ok groups (bug 1: 4 failures, bug 2: 9 failures, bug 3: 4
failures).

Manual check: NOT VERIFIED (human): a reader confirms the commit
message evidence matches the detailed spec's three bugs one to one.

### P7-01 — c2f259c
Removed the "CLI dispatch" and "set --stdin" sections from
test-coverage-gaps.md whole (with their --- separators); narrowed
"Drop-in file loading" to cover only the uncatchable-fatal gap, now
that smoke.sh case D exercises syntax-error/throw/wrong-type/
sets-nothing through the real loader. Added one sentence each to
scope.md (WP-CLI paragraph), extension-points.md (the drop-in gap
paragraph), and retrieval.md (Fail closed paragraph) pointing at the
smoke coverage. tests/smoke/SPEC.md Status: planned -> built, with a
forward reference to docs/journal/2026-09-24-testing-the-cli-for-real.md
(P7-03's entry; today's date used per the task's fallback rule).

open-questions.md and proposal-questions.md: reviewed, no change
needed -- no interface changed and no statement in either file is
now false.

Verified: grep -c '^## ' is 3 on all three spec pages (As proposed/
As built/Why, in order); "CLI dispatch" and "--stdin" no longer
appear in test-coverage-gaps.md; bin/ci-local.sh --keep and
make reference-check both green.

### P7-02 — f1697e9
README.md: added `make smoke` row to the target table, a sentence in
"Clone to green" that bin/ci-local.sh now runs the smoke test too,
and mentioned the smoke job in the Contributing CI sentence.
docs/reference/ci.md: added `make smoke` to the command list; new
"The WP-CLI smoke test" section after "Without Docker" (what it is,
its variables, network requirements, its path through
bin/ci-local.sh, disposable install); smoke row in the Matrix table
(7.4/8.3, latest, "WP-CLI end to end, single site then multisite");
one sentence under Pinning about wp-cli.phar's version+SHA-256 pin.

Verified: grep -n 'make smoke' README.md docs/reference/ci.md shows
both; bin/ci-local.sh --keep and make reference-check both green
(ci.md is hand-maintained, correctly untouched by the generator).

### P7-03 — b63f4e7
Added docs/journal/2026-09-24-testing-the-cli-for-real.md: frontmatter
title/description/date, voice matching the 4 September entry, four
sections in order (what was built, what it found, what was
deliberately left out, what it means for the Trac patch), linking
tests/smoke/smoke.sh and ADR 0008. Named the --version swallow as
the concrete finding PHPUnit could not catch, and the multisite
root-key adoption fix (R1-01) as the one src/ change this work
drove, with a pointer to docs/spec/network.md.

docs/index.md: added the entry's line under journal/, right after
the 0.1.0 line, same shape.

tests/smoke/SPEC.md: date P7-01 already wrote (2026-09-24) matches
today; no correction needed.

Verified: head -5 shows the three frontmatter keys; grep -c
'smoke.sh' and grep -c '0008' are each 3; grep -n
'testing-the-cli-for-real' hits both docs/index.md and
tests/smoke/SPEC.md; docs/journal/_drafts untouched. bin/ci-local.sh
--keep and make reference-check both green.
