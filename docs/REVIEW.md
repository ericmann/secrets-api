# Review: build/cli-smoke
Round: 4

**Verdict: CHANGES REQUESTED**

I reviewed `1209b5013018..d1ca11c` against `docs/SPEC.md`, `tests/smoke/SPEC.md`, and
`docs/PLAN.md`. The only task commit since round 3 is `f264c3e` (R3-01). I read it line by line
against its PLAN entry and against the P6-01 commit's recorded evidence (`31961a1`). Earlier rounds
reviewed every other task commit. I re-checked the whole-branch invariants below.

My own `foundry_verify` run (no file scope) passed. All 13 constraint rules self-tested clean with
zero hits. `bin/ci-local.sh --keep` ended `All green.`: PHPUnit passed on single site and on
multisite, and the smoke suite passed 144 of 144. `make reference-check` was clean.

Whole-branch invariants I checked:
- The `# Working in this repository` section of `CLAUDE.md` is byte-identical to `main`. The
  branch only appends to it.
- `git diff main -- src/wp-includes/interface-*.php` is empty.
- Neither `.smoke/` nor `.wp-env.override.json` is tracked.
- R3-01's `ci.yml` diff touches comment lines only, and its `cli/` diff touches docblock prose
  only. `docs/reference/wp-cli.md` matches the generator.
- The task's verification greps (for `WP-CLI consumes`, `global --version`, `WP-CLI's own global`,
  `R1-01`, and `P6-01`) print nothing.
- The new pre-conversion local is `preconvert_value`, so `smoke-diagnostics-never-print-stdout`
  covers it. No diagnostic interpolates it.

Mutation tests with `foundry_mutate`:

| Mutation | Result |
|---|---|
| `class-wp-secrets-key-manager.php`: make `get_wrapped_root_key()` return early on every path | killed by phpstan (always-true condition). This says nothing about the tests, so I ran the next mutation. |
| `class-wp-secrets-key-manager.php`: read the pre-conversion root key from a misspelled option name, so adoption never finds it | killed: 3 multisite PHPUnit failures (the R1-01 tests) |

Categories 1 to 3 are clean. R3-01 did what it was asked in `cli/`, `smoke.sh`, `ci.yml`, and
`ci.md`, and its 5 new assertions pass. The verdict is CHANGES REQUESTED for one reason. Task step
3b asked the journal to "state what bug 1's reintroduction actually showed". The rewritten
paragraph says the opposite of what happened. The branch's own evidence contradicts it, and so
does the `smoke.sh` header that the same commit got right. This is one small fix task. It includes
exact replacement text so that it converges.

## Findings

### 1. Category 5 (spec drift: the journal deliverable misstates the evidence): the bug 1 reintroduction is described backwards
- **Where:** `docs/journal/2026-09-24-testing-the-cli-for-real.md:49-56`
- **What is wrong:** the entry says:
  > Reintroducing `--version` by hand and running against the real binary shows what that rename
  > actually guards against: `get --version=previous` now reaches `get()`, which does not declare
  > `--version`, and WP-CLI rejects it — exit 1, `unknown --version parameter` on stderr.

  This contradicts itself. Reintroducing `--version` means `get()` declares `--version` again, so
  it cannot then "not declare `--version`". What P6-01 recorded when the flag was renamed back is
  this: `not ok 36 - --version=previous does not select the previous slot`. That means the real
  binary delivered `--version=previous` to `get()`, and `get()` returned the previous value. The
  suite failed on the synopsis row (24), that row (36), and the two `--slot=previous` rows
  (52, 53). Exit 1 with `unknown --version parameter` is how the *current* tree behaves, where
  `get()` declares `--slot`. Case A's new rows 37 and 38 pin exactly that. The `smoke.sh` header,
  rewritten in the same commit, gets this right ("--version=previous then reaches get() and
  selects the previous slot"). The journal now disagrees with the test file it links to. The next
  sentence, "Diagnosing this also turned up a second defect", invents a causal link as well. The
  root-key defect came from case E's first run, not from diagnosing bug 1. The entry's own "What
  was built" section says so.
- **What would break:** SPEC §2 requires this journal entry to report "what it found", and the
  Trac patch will cite it. As written, it tells readers that restoring `--version` makes WP-CLI
  reject the flag. A reader who reruns the experiment gets the previous value back instead.
- **Minimal fix:** replace the sentences from "Reintroducing `--version` by hand" through "still
  decrypts after it." (lines 49-56) with a verified account. Exact text is in the task.
- **Task:** R4-01 (from R3-01 step 3b).

### 2. Category 5 (small, same task): case A's comment still says it pins bug 1's "cause"
- **Where:** `tests/smoke/smoke.sh:280`: `# Pin bug 1's cause, not only its fix: ...`
- **What is wrong:** bug 1's cause was `wp-env run` dropping the flag. The rest of the comment
  correctly says what the rows pin: WP-CLI hands `--version` to `get()`, and `get()` rejects it.
  So the rows pin WP-CLI's side of bug 1, not its cause. Only the opening clause is wrong.
- **Minimal fix:** change the clause to "Pin WP-CLI's side of bug 1, not only its fix:". Change no
  code and no assertion.
- **Task:** R4-01 (from R3-01 step 2b).

## Spec issues

- **`tests/smoke/SPEC.md` case A** still says the `--version=previous` row "pins bug 1's cause, not
  only its fix". Round 3 raised this and left it to the operator. The cause was the `wp-env run`
  wrapper, which the harness deliberately never goes through. What the row can pin is WP-CLI
  rejecting an undeclared `--version`, and it now does. I have not queued a change, because this
  is the operator's design document.
- **`docs/SPEC.md` §4** limits `src/` changes to places where the detailed spec calls for them.
  This flight carries R1-01. The code matches `docs/spec/network.md`, no interface changed, and
  the journal says so plainly. I agree with keeping it.

## Manual checks still owed

From HANDOFF.md and the progress log, all `NOT VERIFIED (human)`:

- **P1-02:** `make smoke` on a host without Docker (MySQL on `127.0.0.1`, `DB_PASS` as needed)
  provisions the install. Check the WP-CLI pin (2.12.0, SHA-256 `ce34ddd8…20d85c`) against the
  release page by eye.
- **P2-03:** `make smoke` on a host without Docker runs case A green.
- **P3-03:** read the full TAP output once by eye for any line that shows a value or a key. My
  144-line run had none.
- **P4-03:** run the uncatchable-fatal drop-in row on a PHP newer than 8.3 and confirm it is
  still a fatal. The smoke runs on this branch used wp-env's `cli` container, which runs PHP 7.4.
- **P5-03:** the `smoke` CI job is green on PHP 7.4 and 8.3. `make smoke` on a host without
  Docker passes both the single-site and the multisite pass.
- **P6-02:** a reader confirms that the P6-01 commit's three `not ok` groups match the detailed
  spec's three bugs one to one.
- **P7-04:**
  1. `make smoke` on a clean checkout with a local MySQL.
  2. The CI `smoke` job is green on 7.4 and 8.3.
  3. `npm run docs:build` renders the new journal entry in the sidebar in date order.
  4. Read the journal entry for voice and for anything private. Do this after R4-01.

## Notes

- HANDOFF.md's round 3 section says the journal "now says what the reintroduction actually showed
  (get() rejects the undeclared flag once `--slot` is renamed back)". That is the same inversion
  as finding 1. Only the pipeline document is affected, so there is no separate task.
- Journal line 20 is 109 columns, because R3-01 reflowed only the first half of that paragraph.
  This is cosmetic, and the renderer ignores it.
- The round 3 notes still apply and are too small for tasks:
  - `SITE2_ID` and `SITE2_URL` are captured with bare substitutions, so a failing `site create`
    aborts the run under `set -e` with no `not ok` line.
  - The diagnostic rule's `[^#]*` stops scanning a line at the first `#`.
