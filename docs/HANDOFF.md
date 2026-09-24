# HANDOFF

Branch: `build/cli-smoke`
Base: `1209b5013018`
Head: `4ea429b`

Task counts: 24 done, 0 blocked, 0 skipped, 0 todo, 0 in progress. 24 total.

## Round 1

Review-fix round. Three `R1-*` tasks queued by the round-0 reviewer, all done, zero open.

**R1-01 — Preserve the root key across multisite conversion.** This is the fix for the P5-01
defect described below under "Blocked": `WP_Secrets_Key_Manager` now has a private
`get_wrapped_root_key()` helper, used by both `get_root_key()` and `rotate_site_key()`. On a
`get_site_option()` miss under `is_multisite()`, it falls back to
`get_blog_option( get_main_site_id(), ROOT_KEY_OPTION )`, adopts that value into `wp_sitemeta` via
`add_site_option()`, deletes the stranded main-site row only on successful adoption, and re-reads
`get_site_option()` to handle a concurrent adopter. Public signatures unchanged; single-site path
unchanged. 3 new multisite-gated tests added to `tests/phpunit/test-wp-secrets-key-manager.php`,
confirmed failing pre-fix (recorded in the commit message) and passing post-fix, 21/21 in that
file on both single-site and multisite. `docs/spec/network.md`'s "As built" Key derivation
paragraph gained two sentences describing the adoption; still exactly 3 sections, "Why" untouched
since the code now matches it. Manual reproduction in wp-env (`secret set` a probe → `core
multisite-convert` → `plugin activate secrets-api --network` → `secret get --reveal` returns the
probe, exit 0) is recorded in the R1-01 commit message.

This directly unblocks the P5-01 finding below, but **P5-01 itself is still `[!]` blocked and
P5-02/P5-03/P6-01/P6-02/P7-01..04 are still skipped** — this round's tasks were scoped to
`src/`, its tests, and the one spec page; PLAN.md still routes P5-01 through the blocked/skipped
chain until a controller re-queues it. Re-running P5-01 (or an equivalent task) should now find
the root-key adoption in place and be able to complete the `convert_to_multisite` /
`case_e_multisite` smoke coverage that P5-01's own attempt could not.

**R1-02 — Make the smoke list-value and rotation assertions able to fail.** `tests/smoke/smoke.sh`
gained a default-table `secret list` run plus two value-absence asserts, two more value-absence
asserts each right after the existing `list --format=json` and `list --format=csv` runs (moved/
added before any later `run()` call could overwrite `$OUT`), and, in case C, a `config get`
readback of `WP_SECRETS_KEY`/`WP_SECRETS_KEY_PREVIOUS` asserting they differ before `rotate --yes`.
125 assertions now pass (up from 121). Negative check (a) (pointing the csv needle at the secret's
own name) confirmed `not ok` as required — done against `--format=csv`, not `--format=json`,
because WP-CLI's JSON encoder escapes `/` as `\/`, so a namespaced name never appears as a raw
substring of JSON output. Negative check (b) (commenting out the `WP_SECRETS_KEY` config-set line)
was reasoned through by hand rather than executed: the sandbox's auto-mode classifier refused to
run the modified script, flagging it "Security Test Removal"; logged as pipeline friction. Both
negative-check edits were reverted before the real commit.

**R1-03 — Widen the smoke diagnostic constraint to the variables the suite uses.**
`smoke-diagnostics-never-print-stdout` in `docs/foundry.json` now also matches the suite's
lower-case locals (`v1`, `v2`, `vr`, `vd`, `old`, `new`, `porcelain_out`), matched by exact name
rather than a broad case-insensitive pattern to avoid flagging unrelated words. All 5 required
`shouldMatch` and 2 `shouldNotMatch` fixtures pass; the rule reports zero real hits against the
tracked tree. `baseBranch`, `branchPrefix`, `permissionMode`, and both verify commands (with
timeouts) are untouched. `CLAUDE.md` got the matching one-bullet update in `## Constraints`; the
`# Working in this repository` / Documentation section is untouched.

All three tasks verified with `bin/ci-local.sh --keep` (459/459 both single-site and multisite)
and `make reference-check` (clean) after each commit, and again at round end via `foundry_verify`
with no file scope (all 13 constraints ok, both verify commands green).

### Round 1 pipeline friction

One entry logged via `foundry_feedback_log` (category `tool-refusal`): the sandbox's auto-mode
classifier declined to run `tests/smoke/smoke.sh` with a task-required, temporary negative-check
edit (a commented-out `config set WP_SECRETS_KEY` line), labeling it "Security Test Removal" even
though the task (R1-02) explicitly called for the edit and its immediate revert. Worked around by
verifying the logic by hand instead of executing it.

## Round 2

Review-fix round. The reviewer unblocked P5-01 (fixed upstream by R1-01, already landed in Round
1) along with its whole dependent chain (P5-02, P5-03, P6-01, P6-02, P7-01..04), plus queued one
new fix task, R2-01. All 10 open tasks are now `[x]`; 0 blocked, 0 skipped, 0 todo.

**P5-01 — Convert to multisite and run the network pass.** Implemented `convert_to_multisite`
(`wp core multisite-convert`, network-activate, `site create --slug=smoke2`, export
`SITE2_ID`/`SITE2_URL`) and `case_e_multisite` (network-secret round-trip visible from both sites,
a site-2 secret invisible from site 1 and readable from site 2 with `--url`, `network-secret
health --format=json` valid JSON). 14 new assertions, 139 total. Ran the full A–E suite twice
against wp-env with `bin/smoke-install.sh` reprovisioning between runs, per the task's acceptance
test: 139/139 both times. R1-01's root-key adoption fix (from Round 1) is what made this task
completable — the same `network-secret health` check that failed 1/131 last round now passes.

**P5-02 — Wire smoke into `make ci`, `bin/ci-local.sh`, and a smoke CI job.** `Makefile`'s `ci:`
target gained `smoke`; `bin/ci-local.sh` gained an `==> smoke` step after `test-ms`, running
`bin/smoke-install.sh` then `tests/smoke/smoke.sh` in wp-env's `cli` container (never `tests-cli`,
never `wordpress_test`); `ci.yml` gained a `smoke` job after `test-multisite` (`needs: static`,
PHP 7.4/8.3 matrix, `mysql` service with `MYSQL_DATABASE: wordpress_smoke`, no Composer step,
checkout/setup-php lines copied verbatim). Verified `bin/ci-local.sh --keep` runs end to end and
prints "All green."; `make -n ci` lists `smoke` last; `ci.yml` parses via `ruby -ryaml` (PyYAML
absent on this host); every `uses:` line is still a SHA pin.

**P5-03 / P6-02 / P7-04 — phase-end pushes.** Each pushed `build/cli-smoke` to `origin` (policy
`push=on`) via an empty commit titled for the task (no code to change), logged `Manual check: NOT
VERIFIED (human)` per the task text, and re-pushed after `foundry_task_done` added the PROGRESS.md
commit.

**P6-01 — Prove each historical bug fails the smoke test.** Reintroduced each of the three
historical CLI dispatch bugs by hand inside wp-env, confirmed the suite failed for the right
reason, and reverted with `git checkout --` before committing (`git diff --stat HEAD -- cli/` was
empty at commit time). Bug 1 (`get()`'s `--slot` renamed to `--version`): 4 failures. Bug 2
(`list()`'s `--format` description line deleted): 9 failures. Bug 3 (`@subcommand migrate-legacy`
deleted): 4 failures. All three `not ok` groups are quoted verbatim in the P6-01 commit message. A
"Regression proof" comment block under `tests/smoke/smoke.sh`'s header records the same mapping.
Final green run after all reverts: 139/139.

**P7-01 — Update the coverage gaps, the spec pages, and the detailed spec's status.**
`docs/journal/test-coverage-gaps.md`: removed the "CLI dispatch" and "set `--stdin`" sections
whole (with their `---` separators); narrowed "Drop-in file loading" to the uncatchable-fatal case
alone, now that case D covers the rest through the real loader. One sentence each added to
`docs/spec/scope.md` (WP-CLI paragraph), `docs/spec/extension-points.md` (the drop-in gap
paragraph), and `docs/spec/retrieval.md` (Fail closed paragraph). `tests/smoke/SPEC.md`:
`Status: planned` → `Status: built`, with a forward reference to the P7-03 journal entry (today's
date, 2026-09-24, per the task's fallback rule — and it turned out to be right, since P7-03 landed
the same day). `open-questions.md` and `proposal-questions.md`: reviewed, no change needed — no
interface changed. All three spec pages still have exactly 3 `## ` headings, in order; "CLI
dispatch" and "--stdin" no longer appear in `test-coverage-gaps.md`.

**P7-02 — Document `make smoke` in the README and the CI reference.** `README.md`: a `make smoke`
row in the target table, a sentence in "Clone to green," and a mention in the Contributing CI
sentence. `docs/reference/ci.md`: `make smoke` added to the command list; a new "The WP-CLI smoke
test" section after "Without Docker" (variables, network requirements, its path through
`bin/ci-local.sh`, disposable install); a `smoke` row in the Matrix table; one sentence under
Pinning about `wp-cli.phar`'s version+SHA-256 pin.

**P7-03 — Write the journal entry and link it from the index.**
`docs/journal/2026-09-24-testing-the-cli-for-real.md` (new): frontmatter, voice matching the 4
September entry, four sections in the required order (what was built / found / left out / means
for the Trac patch). Per the dependency log note, this entry names the `--version` swallow as the
concrete PHPUnit-can't-catch finding **and** names the multisite root-key adoption fix (R1-01) as
the one `src/` change this work drove — the "if nothing in `src/` changed" branch of the task's
instructions did not apply, since R1-01 landed in Round 1. `docs/index.md` got the entry's line
under `journal/`, after the 0.1.0 line. `tests/smoke/SPEC.md`'s date, already written by P7-01,
matched today and needed no correction.

**R2-01 — Make the smoke diagnostic rule catch any key- or value-holding variable.** The
`smoke-diagnostics-never-print-stdout` constraint in `docs/foundry.json` now also matches any
interpolated identifier containing `key` or `value` in any letter case
(`[A-Za-z0-9_]*[Kk][Ee][Yy][A-Za-z0-9_]*` and the equivalent for `value`), plus the exact names
`VN` and `VS`, on top of the existing exact-name alternation from R1-03. This closes the blind
spot R1-03 left open: `current_key`/`previous_key` (added by R1-02) and `VN`/`VS` (added by this
round's P5-01) would not have matched the old pattern. Added the 5 required `shouldMatch` and 2
required `shouldNotMatch` fixtures (copied verbatim from the real file); every prior fixture still
passes. `baseBranch`, `branchPrefix`, `permissionMode`, and both verify commands (with timeouts)
are untouched. `CLAUDE.md` got only the matching `## Constraints` bullet update — confirmed via
`git diff CLAUDE.md` that the `# Working in this repository` heading and every other line are
untouched. Re-read `tests/smoke/smoke.sh` for any other new plaintext/key variable since P5-01
landed: none found beyond what the fixtures now cover, and none of those variables are
interpolated inside an existing `not_ok`/`diag` call, so the real-tree scan is still zero hits.

All ten tasks verified individually with `foundry_verify` (all 13 constraints ok, `bin/ci-local.sh
--keep` green, `make reference-check` clean) and again at round end with `foundry_verify` and no
file scope: 13/13 constraints ok, `bin/ci-local.sh --keep` green (459/459 single-site PHPUnit,
459/459 multisite PHPUnit, 139/139 smoke), `make reference-check` clean.

### Round 2 pipeline friction

None logged this round.

## Interpretation choices

- **P4-01** — The task's prescribed negative check (skip setting `WP_SECRETS_KEY` to the new
  value, expect `rotate --yes` or the following `get` to fail) does not actually fail. Skipping
  that line leaves `WP_SECRETS_KEY_PREVIOUS` equal to `WP_SECRETS_KEY` (both the original key), and
  per `docs/spec/rotation.md`, site-key rotation has no requirement that the old and new keyrings
  differ — rotating to an unchanged key is a legitimate no-op that succeeds. Verified by hand
  (disabled the line, reran, 102/102 still passed, reverted). No test encodes this as an assertion
  since it isn't a defect; recorded in the P4-01 commit message and log entry instead.

- **P7-01 / P7-03** — `tests/smoke/SPEC.md`'s forward reference to the P7-03 journal entry uses
  today's date, `2026-09-24`, per the task's explicit fallback rule ("if unsure, write today's
  date and P7-03 corrects it"). P7-03 ran the same day, so no correction was needed.

No other task in Round 2 required an interpretation call beyond what is described in that task's
own entry above; each was implemented literally against the PLAN.md text and the cited SPEC
sections.

## ⚠️ ASSUMPTION config keys

None were introduced in Round 2 either. No new config surface was added; the smoke test and its
CI wiring only drive the existing CLI and the existing Makefile/wp-env plumbing.

## What a human must check by hand, per phase

- **Phase 4 (P4-03 log)** — Run the uncatchable-fatal drop-in row (a class implementing
  `WP_Secrets_Keyring` with no methods) by hand on a PHP version newer than 8.3, and confirm it is
  still a fatal, not a catchable `TypeError`/`Error`. This run's own smoke pass already exercised
  that row under PHP 8.5.10 and it fataled as expected (assertion 116 in the P4-02 run), but the
  task calls for an explicit human check against whichever PHP versions the project targets going
  forward.
- **Phases 1–3 (earlier rounds, unchanged this round)** — Their own manual-check log entries in
  `docs/PROGRESS.md` still apply; nothing in this round touched that work.
- **P5-01, resolved this round** — `src/wp-includes/class-wp-secrets-key-manager.php` now
  preserves the root key across `wp core multisite-convert` (R1-01, Round 1), and this round's
  P5-01 confirmed it end to end: a full A–E smoke run, then a fresh `bin/smoke-install.sh` +
  another full run, both 139/139. No further human action needed on this specific finding, though
  the general phase-end manual checks below still apply.
- **Phase 5 (P5-03 log)** — the `smoke` CI job green on PHP 7.4 and 8.3 in the Actions tab; `make
  smoke` on a host without Docker passes both passes.
- **Phase 6 (P6-02 log)** — a reader confirms the P6-01 commit's quoted evidence (three `not ok`
  groups) matches the detailed spec's three historical bugs one to one.
- **Phase 7 (P7-04 log)** — (1) `make smoke` on a clean checkout with a local MySQL; (2) the CI
  `smoke` job green on 7.4 and 8.3; (3) `npm run docs:build` renders the new journal entry in the
  sidebar in date order; (4) a read of the journal entry for voice and for anything private.

## Notes for the reviewer

- All 139 smoke assertions pass cleanly and repeatably against a real `wp` binary, on single site
  and multisite. I ran the full suite (A–E) from scratch multiple times this round — twice back to
  back for P5-01's specific acceptance test (install, smoke, install, smoke), once more inline
  inside `bin/ci-local.sh --keep` for P5-02, once more for P7-01/P7-02, and a final `foundry_verify`
  with no file scope at round end — using the wp-env-provisioned MariaDB container as `.smoke`'s
  database (no local MySQL/MariaDB on this host). Every run was 139/139, exit 0.
- `bin/ci-local.sh --keep` and `make reference-check` both pass on the final tree; the last
  `foundry_verify` run (no file scope) shows all 13 constraints ok and both commands green.
- P6-01's regression-proof cycle (reintroduce each historical bug, confirm failure, revert) was
  run against a real wp-env install for all three bugs, one at a time, with a fresh
  `bin/smoke-install.sh` between bugs 1 and 2 to avoid state bleed from case E's multisite
  conversion; `git diff --stat HEAD -- cli/` was empty before each commit.
- Nothing in `src/`, `plugin/`, or the three interface files changed this round. The only
  production-code change in this entire branch is R1-01 from Round 1.
- `docs/foundry.json`'s `smoke-diagnostics-never-print-stdout` rule (R2-01) is now broader than
  an exact-name list; if a future task adds another plaintext-holding local that does *not*
  contain `key` or `value` in its name, it will not be caught automatically — name new
  plaintext-holding locals with `key` or `value` in them, or extend the rule again.
