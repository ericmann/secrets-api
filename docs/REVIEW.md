# Review: build/cli-smoke
Round: 5

**Verdict: APPROVED**

I reviewed `1209b5013018..e936c6d` against `docs/SPEC.md`, `tests/smoke/SPEC.md`, and
`docs/PLAN.md`. The only task commit since round 4 is `37cff5f` (R4-01). I read it line by line
against its PLAN entry and against the P6-01 evidence in `31961a1`. Earlier rounds reviewed every
other task commit, so for those I re-checked the whole-branch invariants below.

My own `foundry_verify` run (no file scope) passed. All 13 constraint rules passed their own
fixture self-tests and found zero hits. `bin/ci-local.sh --keep` ended `All green.`: PHPUnit
passed on single site and on multisite, and the smoke suite passed 144 of 144.
`make reference-check` was clean.

R4-01 checks:
- The journal's replacement text in `docs/journal/2026-09-24-testing-the-cli-for-real.md:50-59`
  matches the task's text word for word, wrapped at 100 columns.
- It now describes the two tree states correctly. On the current tree, WP-CLI rejects
  `--version`. With the flag reintroduced, the previous value comes back. It also drops the
  invented causal link to the root-key defect.
- The account agrees with P6-01's recorded `not ok` rows 24, 36, 52, and 53, and with the
  `smoke.sh` header.
- The acceptance greps behaved as the task specified:
  - `which does not declare`: no matches.
  - `Diagnosing this`: no matches.
  - `the previous value comes back`: one line (53).
  - `Pin bug 1's cause` in `smoke.sh`: no matches.
- The `smoke.sh` diff changes one comment line. No code, assertion, or description string
  changed.
- The commit touches only the two files the task named.

Whole-branch invariants:
- The `# Working in this repository` section of `CLAUDE.md` is byte-identical to `main`'s
  `CLAUDE.md`. The branch only appends below it.
- `git diff main -- src/wp-includes/interface-*.php` is empty.
- Neither `.smoke/` nor `.wp-env.override.json` is tracked.
- The `.gitignore` diff has two changes. P1-01 added `/.smoke/`, which Phase 1 calls for.
  Foundry's own `chore: start implementation run` commit added `.foundry/implement.lock`. No
  implementer commit touched `.gitignore`.
- The working tree was clean after each mutation run.

Mutation tests with `foundry_mutate`:

| Mutation | Result |
|---|---|
| `cli/class-wp-cli-secret-command.php`: `get()` halts with 1 instead of 2 on a `WP_Error` | Killed. PHPUnit failed `test_get_broken_secret_halts_with_exit_code_2`. |
| `secrets-api.php`: register `network-secrets` instead of `network-secret` (ran `bin/ci-local.sh --keep` only) | Killed by the smoke suite alone: 125 passed, 19 failed (row 101, rows 134-138, and rows 143-144, among others). Both PHPUnit suites passed. The smoke suite covers command registration, and PHPUnit does not. |

Earlier rounds mutated `src/wp-includes/class-wp-secrets-key-manager.php` (the R1-01 root-key
adoption), and the multisite PHPUnit tests killed it. That file has not changed since then.

Categories 1 through 3 are clean across the branch. No task is blocked or skipped, and there are
no open tasks.

## Findings

None.

## Spec issues

- **`tests/smoke/SPEC.md:64`** still says the `--version=previous` row "pins bug 1's cause". The
  cause was the `wp-env run` wrapper dropping the flag. The harness deliberately never goes
  through that wrapper, so no row can pin it. What case A pins now is WP-CLI's side: it rejects
  an undeclared `--version` with exit 1 and `unknown --version parameter`. Everything else on the
  branch now says so: the journal, the `smoke.sh` comments, the `get()` docblock, and the
  reference docs. This is the operator's design document, so I have not queued a change. A
  one-line wording fix at merge would bring it into line.
- **`docs/SPEC.md` §4** limits `src/` changes to places where the detailed spec calls for them.
  This branch carries R1-01, the root-key adoption across `wp core multisite-convert`. The code
  matches what `docs/spec/network.md` already claimed, no interface changed, and the journal says
  so plainly. I agree with keeping it, but the operator should know it is the one `src/` change.

## Manual checks still owed

From HANDOFF.md and the progress log, all `NOT VERIFIED (human)`:

- **P1-02:** run `make smoke` on a host without Docker (MySQL on `127.0.0.1`, `DB_PASS` as
  needed) and confirm it provisions the install. Compare the WP-CLI pin (2.12.0, SHA-256
  `ce34ddd8…20d85c`) with the release page by eye.
- **P2-03:** `make smoke` on a host without Docker runs case A green.
- **P3-03:** read the full TAP output once by eye for any line that shows a value or a key. My
  144-line run had none.
- **P4-03:** run the uncatchable-fatal drop-in row on a PHP newer than 8.3 and confirm it is
  still a fatal. The smoke runs on this branch used wp-env's `cli` container.
- **P5-03:** the `smoke` CI job is green on PHP 7.4 and 8.3. `make smoke` on a host without
  Docker passes both the single-site and the multisite pass.
- **P6-02:** a reader confirms that the P6-01 commit's three `not ok` groups match the detailed
  spec's three bugs one to one.
- **P7-04:**
  1. `make smoke` on a clean checkout with a local MySQL.
  2. The CI `smoke` job is green on 7.4 and 8.3.
  3. `npm run docs:build` renders the new journal entry in the sidebar in date order.
  4. Read the journal entry for voice and for anything private.
- **R4-01:** confirm the rewritten "What it found" paragraph reads correctly on the rendered docs
  site.

## Notes

- Journal line 22 is a stray short line (`` `generate-key`, ``) left by the R4-01 rewrap. The
  paragraph is otherwise wrapped at 100 columns. Markdown ignores it.
- `awk` counts line 86 of the journal as 104 bytes long, because each 🟡/🟢 emoji takes 4 bytes. It
  displays at about 100 columns.
- In the registration mutation, row 100 (`network-secret refuses on a single-site install`) still
  passed. That row checks only for a non-zero exit, and an unregistered command also exits
  non-zero. Row 101 (the explanatory message) caught the mutation, so the pair is sound. Row 100
  alone would not be.
- The rest of the journal's "reintroducing" sentence is accurate for P6-01's tree. Case A rows 37
  and 38 were added later, and today a reintroduction would also fail them. The paragraph does not
  claim the list is exhaustive.
- Earlier rounds' notes still apply, and all are too small for tasks:
  - `SITE2_ID` and `SITE2_URL` are captured with bare command substitutions, so a failing
    `site create` aborts the run under `set -e` with no `not ok` line.
  - The diagnostic rule's `[^#]*` stops scanning a line at the first `#`.
  - HANDOFF.md's round 3 summary still carries the inverted description of the reintroduction.
    It is a pipeline document, not published.
