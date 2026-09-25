# Build summary: build/cli-smoke

**Merge line:** `build/cli-smoke`, `main` (`1209b50`) → `9e61d13`. 81 commits before this summary
commit, 26 tasks done (20 planned and 6 review fixes), 0 blocked, 0 skipped. Five review rounds.
Final verdict: **APPROVED** (round 5).

Before merging, remove the Foundry pipeline files: `docs/SPEC.md`, `docs/PLAN.md`,
`docs/PROGRESS.md`, `docs/HANDOFF.md`, `docs/REVIEW.md`, `docs/SUMMARY.md`, `docs/foundry.json`,
`.foundry/`, and the Foundry block appended to `CLAUDE.md` below `# Working in this repository`.
The reviewer confirmed that section of `CLAUDE.md` is byte-identical to `main`.

## What was built

**Phase 1: install harness (P1-01 to P1-02).** `bin/smoke-install.sh` provisions a throwaway
WordPress install under `.smoke/`, which is gitignored and excluded from phpcs. It uses a pinned
WP-CLI 2.12.0 phar, verified by SHA-256, and creates its own `wordpress_smoke` database through
a `php -r` mysqli snippet. It sets `WP_SECRETS_KEY` with `--quiet`, so the key is never echoed.

**Phase 2: registration cases (P2-01 to P2-03).** `tests/smoke/smoke.sh` has TAP helpers and a
`wp cli has-command` matrix for every `secret` and `network-secret` subcommand. It compares each
`wp secret` subcommand's SYNOPSIS flags against a table, as an exact set. Case A also pins how
the real binary handles `get --version=previous`: exit 1, with `unknown --version parameter`.

**Phase 3: behaviour and exit codes (P3-01 to P3-03).** Case B covers set/get, masking,
`--stdin`, porcelain, slots, JSON, list filters (with value-absence checks on table, JSON and CSV
output), retire, delete, absence, keys, health, dropin, import, migrate-legacy, and
`network-secret` refusing to run on a single site.

**Phase 4: rotation and drop-ins (P4-01 to P4-03).** Case C rotates the site key end to end. It
now also asserts that `WP_SECRETS_KEY` differs from `WP_SECRETS_KEY_PREVIOUS`. Case D loads
drop-ins through the real loader and cleans up on exit. That includes a broken provider and an
uncatchable-fatal keyring.

**Phase 5: multisite pass and wiring (P5-01 to P5-03).** The script converts the install to
multisite, network-activates the plugin, and creates site 2. Case E checks network-secret round
trips, per-site isolation via `--url`, `network-secret health`, and that a secret written before
conversion still decrypts after it. `make ci`, `bin/ci-local.sh` (inside wp-env's `cli` container) and a new `smoke` CI job (PHP 7.4
and 8.3, MySQL service) now all run the suite. It stands at 144 assertions.

**Phase 6: regression proof (P6-01 to P6-02).** Each of the three historical dispatch bugs was
reintroduced by hand, and in each case the suite failed: `--slot` renamed back to `--version`,
`list`'s `--format` description line deleted, and `@subcommand migrate-legacy` removed. Each was
reverted without a commit. The `not ok` groups are quoted in the commit `31961a1`
message and summarised in the `smoke.sh` header.

**Phase 7: docs and journal (P7-01 to P7-04).** `test-coverage-gaps.md` drops the CLI dispatch
and `--stdin` gaps and narrows the drop-in gap to the uncatchable fatal. `scope.md`,
`extension-points.md` and `retrieval.md` gain one sentence each, and `tests/smoke/SPEC.md` is
marked built. `make smoke` is documented in the README and `docs/reference/ci.md`. A new journal
entry, `docs/journal/2026-09-24-testing-the-cli-for-real.md`, is linked from `docs/index.md`.

**Review fixes (R1-01 to R4-01).**
- R1-01 is the one `src/` change. `WP_Secrets_Key_Manager` now adopts the pre-conversion root key
  from the main site's options into `wp_sitemeta`, instead of silently generating a new one.
  Before this fix, every existing secret was stranded. It comes with 3 multisite PHPUnit tests.
- R1-02 made the list-value and rotation assertions able to fail.
- R1-03 and R2-01 widened the `smoke-diagnostics-never-print-stdout` constraint to any
  interpolated variable whose name contains `key` or `value`, plus `VN`/`VS`.
- R3-01 and R4-01 corrected the documented cause of bug 1 in the journal, the `get()` docblock,
  the regenerated `docs/reference/wp-cli.md`, `smoke.sh`, and the `ci.yml` comment. The cause was
  `wp-env run` dropping the flag, not WP-CLI swallowing it.

## Decisions that shaped it

- **Shared examples harness:** not built; no example provider runs through the CLI. (PLAN)
- **Interface-changing findings:** never change a signature; record in `open-questions.md` and
  the journal. None arose. (P7-01, P7-03)
- **`rotate --from=config`:** deferred to `build/kms-keyring` with a comment marker. (P4-01)
- **Flag table:** exact-set comparison in both directions. When kms-keyring merges, the `rotate`
  row must gain `--from`. (P2-02)
- **Where the multisite conversion lives:** inside `smoke.sh`, after the single-site pass.
  `make smoke` and `ci-local.sh` run the same two scripts. (P5-01, P5-02)
- **How `wp` is invoked:** only through the `WP=( php -d display_errors=stderr -d log_errors=0
  … --allow-root )` array, which keeps fatals on stderr. (P1-01, P2-01)
- **Install (P1-01, P5-01):** database via a mysqli `php -r` snippet (no `wp db`, no `mysql`
  client); `WP_VERSION` default `latest`, unpinned; WP-CLI pinned to 2.12.0 by version and
  SHA-256; `SMOKE_URL=http://smoke.test`, user `smoke` with a never-printed random password,
  site 2 slug `smoke2`.
- **"dropin reports it broken":** stdout contains `Provider: WP_Secrets_Broken_Provider`. (P4-02)
- **`network-secret` registration:** `has-command` on single site; the flag table covers
  `secret` only. (P2-01)
- **No ADR** (PLAN); **`docs/reference/ci.md` edited by hand**, as the generator does not own it
  (P7-02); **no tunables**, which would have been `SMOKE_*` variables (PLAN).
- **Regression proof:** working-tree edits only, reverted with `git checkout --`, never
  committed, no `git stash`. (P6-01)
- **Interpretation, P4-01:** the negative check PLAN prescribed could not fail, because a
  rotation to an unchanged key is a legitimate no-op. The implementer recorded this instead of
  asserting it. R1-02 then added the key-differs assertion, which makes the check meaningful.
- **Interpretation, P7-01/P7-03:** `tests/smoke/SPEC.md` references the journal entry by
  today's date (2026-09-24), per the task's fallback rule. No correction was needed.
- **Interpretation, R3-01:** kept the name `--slot`, even though WP-CLI does not in fact consume
  `--version`. Renaming was out of scope. The docblock now gives two reasons that still hold:
  `wp-env run` drops `--version`, and the name would collide with `wp --version`.
- **Review decision, R1-01:** the root-key fix landed in this flight rather than on a separate
  branch. `docs/SPEC.md` §4 limits `src/` changes, but the fix makes the code match
  `docs/spec/network.md`, and no interface changed. If you disagree, P5-01's multisite
  assertions and the pre-conversion round trip depend on it. Expect a small touch-point with
  `build/kms-keyring` in `class-wp-secrets-key-manager.php`.

## Assumptions still in play

None. Neither spec had a `⚠️ ASSUMPTION` key, and no `SMOKE_*` tunable was introduced.

## Spec issues (suggested edits to the SPEC documents)

- **`tests/smoke/SPEC.md:64`, case A:** it says the `--version=previous` row "pins bug 1's cause,
  not only its fix". The cause was the `wp-env run` wrapper, which the harness deliberately never
  uses. What the row pins is WP-CLI rejecting an undeclared `--version`. A one-line rewording
  would fix it. Raised in rounds 3, 4, and 5, and never queued, because this is the operator's
  document.
- **`docs/SPEC.md` §4:** it restricts `src/` changes to what the detailed spec calls for, while §2
  expects the journal to cover `src/` changes. Consider allowing fixes that the smoke test's
  findings require. This flight carries one such fix (R1-01). Raised in rounds 1 to 5.
- **`docs/SPEC.md` §5:** "a named constant in the example file" was carried over from the
  example flights. For this flight it should read "an upper-case `SMOKE_*` variable in the
  script". (PLAN)
- **`CLAUDE.md` vs `docs/SPEC.md` §8.7:** `CLAUDE.md` says to never hand-edit
  `docs/reference/`, but only four files there are generated. `ci.md`,
  `migrating-from-displace.md`, and `drop-in-example.php` are hand-written. Consider saying so
  in `CLAUDE.md`. (PLAN)
- **Detailed spec, case C, third bullet:** depends on `build/kms-keyring`. Deferred; pick it up
  when that branch merges. (PLAN)
- **Detailed spec, case A:** "wp help shows each flag" versus "a new flag without a row fails
  loudly". Only an exact-set comparison satisfies both; say so explicitly. (PLAN)
- **Detailed spec:** "a PHP fatal in stderr" depends on `display_errors`. The harness forces it
  with `-d` flags, and the spec could say so. (PLAN)
- **Smaller notes (PLAN):** `has-command` for `network-secret` relies on WP-CLI instantiating
  command classes lazily. `make ci` now needs network egress and a second database (documented in
  `ci.md`). The `.smoke/` plugin symlink is a directory cycle that tree walkers must prune.
  `test-coverage-gaps.md` carries a `date:` field although tracking pages are undated (left
  alone). The pre-seeded `docs/foundry.json` had no `constraints` (added here).
- **PLAN P4-01:** its prescribed negative check could never fail. R1-02 fixed it. (Round 1)

## Manual checks owed

All are `NOT VERIFIED (human)`:

- **Phase 1 (P1-02):** on a host without Docker (MySQL on `127.0.0.1`, `DB_PASS` as needed), run
  `make smoke` and confirm it provisions the install. Compare the WP-CLI pin (2.12.0, SHA-256
  `ce34ddd8…20d85c`) with the release page by eye.
- **Phase 2 (P2-03):** on a host without Docker, `make smoke` runs case A green.
- **Phase 3 (P3-03):** read the full TAP output once for any line that shows a value or a key.
  The reviewer's 144-line run had none.
- **Phase 4 (P4-03):** run the uncatchable-fatal drop-in row on a PHP newer than 8.3 and confirm
  it is still a fatal, not a catchable `Error`. The pipeline documents claim a PHP 8.5.10 run,
  but every smoke run actually used wp-env's PHP 7.4.33, so there is no evidence for this yet.
- **Phase 5 (P5-03):** the `smoke` CI job is green on PHP 7.4 and 8.3. On a host without
  Docker, `make smoke` passes both the single-site and the multisite pass.
- **Phase 6 (P6-02):** confirm that the three `not ok` groups in commit `31961a1` map one to one
  onto the detailed spec's three bugs. Bug 1's group shows `--version=previous` reaching `get()`
  through the real binary.
- **Phase 7 (P7-04):** `make smoke` on a clean checkout with a local MySQL; the CI `smoke` job
  green on 7.4 and 8.3; `npm run docs:build` shows the new journal entry in the sidebar in date
  order; read the journal entry for voice, anything private, and (R4-01) whether the rewritten
  "What it found" paragraph reads correctly on the rendered site.

## Review history

| Round | Verdict | Findings | Fix tasks | Unblocked | Recurred? | Non-converging |
|---|---|---|---|---|---|---|
| 1 | CHANGES REQUESTED | 4 | 3 (R1-01..03) | 0 | n/a | no |
| 2 | CHANGES REQUESTED | 2 | 1 (R2-01) | 9 (P5-01..P7-04) | yes | yes |
| 3 | CHANGES REQUESTED | 2 | 1 (R3-01) | 0 | yes | no |
| 4 | CHANGES REQUESTED | 2 | 1 (R4-01) | 0 | yes | yes |
| 5 | APPROVED | 0 | 0 | 0 | no | no |

Recurrences: round 2 re-listed P5-01 (round 1 could not unblock it ahead of R1-01; see
Pipeline friction) and re-fixed R1-03's diagnostic-rule gap as R2-01. Round 3 flagged tasks run
after round 2's unblock (P5-01, P5-02, P6-01, P7-02, P7-03). Round 4 flagged R3-01's own rewrite.

**Round 5 was a notes-only approval.** Its `## Notes` flagged, without queuing work:
- Journal line 22 is a stray short line, and one line is 104 bytes because of emoji.
- Row 100 ("network-secret refuses on a single-site install") checks only for a non-zero exit.
  It is sound only paired with row 101.
- `SITE2_ID`/`SITE2_URL` use bare substitutions, so a failing `site create` aborts the run
  under `set -e` with no `not ok` line.
- The diagnostic rule's `[^#]*` stops scanning a line at the first `#`.
- HANDOFF's round 3 text still describes the reintroduction backwards (pipeline doc only).

Earlier rounds also noted that a PHPUnit assertion prints the wrapped (encrypted) root key when
it fails, and that the pipeline documents misreport the PHP version as 8.5.10.

## Pipeline friction

- **plan / ambiguous-prompt:** the plan-build skill says to set `baseBranch` to the planner's
  branch. `docs/SPEC.md` §7 says to keep the pre-seeded `main`. The two contradict when the
  operator pre-creates a `build/` worktree. SPEC was followed. The skill should say which wins.
- **implement / other:** P5-01 uncovered a real cross-flight defect: multisite conversion
  regenerates the root key. Diagnosing it by hand consumed most of the task before it blocked,
  and no PLAN task owned the `src/` fix.
- **review / unblock-ordering:** unblocking resets a task to todo in place, and
  `foundry_task_next` takes the first todo in PROGRESS order. So an unblocked P5-01 would have
  run before its R1-01 fix. There is no "unblock after" option, and that cost an extra,
  non-converging round.
- **review / mutate-coverage:** no verify command ran `tests/smoke/smoke.sh` until P5-02, so any
  `foundry_mutate` on it was bound to survive. Confirming that took a ~5-minute `ci-local` run.
  `foundry_mutate` should warn when no triggered command references the file.
- **implement / tool-refusal:** the sandbox's auto-mode classifier blocked R1-02's required
  negative check (a temporarily commented-out `config set WP_SECRETS_KEY`) as "Security Test
  Removal". The check was reasoned through by hand instead of executed.
