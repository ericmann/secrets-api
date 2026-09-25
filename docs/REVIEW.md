# Review: build/cli-smoke
Round: 3

**Verdict: CHANGES REQUESTED**

I reviewed `1209b5013018..d7e5c06` against `docs/SPEC.md`, `tests/smoke/SPEC.md`, and
`docs/PLAN.md`. I read every round-2 task commit line by line: `53619ee` P5-01, `9822d97` P5-02,
`31961a1` P6-01, `c2f259c` P7-01, `f1697e9` P7-02, `b63f4e7` P7-03, and `504ff07` R2-01. I also
checked the push-only commits (P5-03, P6-02, P7-04). Earlier rounds reviewed phases 1 to 4 and
the R1 fixes. I re-checked the whole-branch invariants listed below.

My own `foundry_verify` run passed. All 13 constraint rules self-tested clean with zero hits.
`bin/ci-local.sh --keep` ended `All green.`: PHPUnit passed on single site and on multisite, and
the smoke suite passed 139 of 139 inside wp-env's `cli` container. `make reference-check` was
clean.

Whole-branch invariants I checked:
- The `# Working in this repository` section of `CLAUDE.md` is unchanged. The branch only
  appends to it.
- The interface files have no diff against `main`.
- Neither `.smoke/` nor `.wp-env.override.json` is tracked.
- Every `uses:` line is pinned to a SHA.
- The `Makefile`, `ci.yml`, `docs/index.md`, and `.gitignore` edits are additive.
- The three spec pages still have exactly three sections each.

`smoke.sh` is now part of the verify command, so I mutation-tested it and the CLI dispatch layer
with `foundry_mutate`:

| Mutation | Result |
|---|---|
| `smoke.sh`: drop `--url="$SITE2_URL"` from case E's site-2 `secret set` | killed: 3 failures ("invisible from site 1", the site-2 read and its round-trip) |
| `cli/class-wp-cli-secret-command.php`: delete `dropin`'s `: Also show …` description line under `[--verbose]` (the bug 2 shape, on a different command) | killed: the `dropin` synopsis row fails; PHPUnit could not see this one |

Round 2 left the code correct and well covered. Categories 1 to 3 are clean. The verdict is
CHANGES REQUESTED for one reason: the journal entry is the deliverable SPEC §2 requires, and it
states a cause for bug 1 that is false. This branch's own P6-01 evidence and the real `wp` binary
both contradict it. The same claim appears in `smoke.sh`'s comments, the `get` docblock, and the
generated reference. Several smaller claims in the same pages are also wrong. All of it is one
fix task.

## Findings

### 1. Category 5 (spec drift: the docs do not match the code): bug 1's cause is wrong, and this branch's own evidence shows it
- **Where:**
  - `docs/journal/2026-09-24-testing-the-cli-for-real.md:40-45`
  - `tests/smoke/smoke.sh:19-25` and `:272-276`
  - `cli/class-wp-cli-secret-command.php:117-120`, and the text generated from it at
    `docs/reference/wp-cli.md:87` and `:312`
  - `.github/workflows/ci.yml:188-191`
- **What is wrong:** each of these says `--version=previous` returned the current value because
  "WP-CLI's own global `--version` flag consumes the argument before the subcommand ever sees
  it". The pinned WP-CLI 2.12.0 does not do that:
  - **P6-01's own evidence.** With `get()`'s flag renamed back to `--version`, the failures
    included `not ok 36 - --version=previous does not select the previous slot`. That assertion
    is `assert_out_not_contains "smoke-value-a-$$"`. It can only fail if the real binary passed
    `--version=previous` to the subcommand and printed the *previous* value. So the
    reintroduction did not reproduce the historical symptom through a real `wp`.
  - **WP-CLI's source.** In the pinned phar, `Runner::back_compat_conversions()` rewrites
    `--version` to `wp cli version` only when `empty( $args )`, meaning no command was given.
  - **Reproduced in review on the current tree.** Through the pinned phar,
    `wp secret get <name> --version=previous` exits 1 with
    `Error: Parameter errors: unknown --version parameter`. So the flag reaches the subcommand.
    Through the wrapper the original 4 September run used,
    `npx @wordpress/env run cli wp secret get <name> --version=previous` logs
    `Starting 'wp secret get <name>'`. The flag is gone before the command reaches the
    container. wp-env's own argument parser consumed `--version`. WP-CLI did not.
- **Why it matters:** SPEC §2 asks the journal entry to cover "what it found", and it is the
  record the Trac patch will cite. This is the one thing the harness actually found about bug 1,
  and the entry reports the opposite. The entry also says reintroducing the bug "makes the suite
  fail for the reason each was supposed to". For bug 1 that is not what happened: the suite
  failed because the synopsis table and `--slot` changed. The `get` docblock ships in
  `wp help secret get` and in the published reference, so it spreads the same wrong cause.
  Renaming the flag to `--slot` is still the right call, because it avoids the wrapper and any
  confusion with `wp --version`. Only the stated reason is wrong.
- **Minimal fix:**
  - Correct the cause in all five places. Say only what was verified: WP-CLI passes
    `--version` after a command to the subcommand, and the historical symptom came from
    `wp-env run`'s argument handling.
  - Make case A's `--version=previous` row pin what the real binary does. Add `assert_status 1`
    and an `assert_err_contains "unknown --version parameter"` next to the existing
    output-absence check. Both would have failed if WP-CLI really swallowed the flag.
  - Rewrite the journal's "What it found" paragraph to report this as a finding.
- **Task:** R3-01 (from P2-02, P6-01, P7-03, and P5-02's comment).

### 2. Category 5: other claims in the journal entry and CI comment that are not true
- **Where:**
  - `docs/journal/2026-09-24-testing-the-cli-for-real.md:19`, `:31-36`
  - `.github/workflows/ci.yml:188-191`
  - `docs/reference/ci.md` ("see `docs/journal/test-coverage-gaps.md`")
- **What is wrong:**
  - The journal says "case E asserts on it directly" about the root-key fix. It does not. Case E
    never reads a secret written before conversion. The only smoke assertion that fails without
    the fix is `network-secret health --format=json exits 0`, and it catches the fix indirectly,
    through health's exit status.
  - The journal says "A reviewer caught it when case E first ran". It was case E's first run
    (P5-01's implementation attempt) that caught it. The review then confirmed it.
  - The journal says "each command's synopsis flags match an exact table". The table is checked
    for `wp secret` only.
  - The `ci.yml` comment says the job exists because of "three real bugs (bug 1's
    --version=previous swallow, a fail-open drop-in path, and the multisite root-key stranding
    R1-01 fixed)". The three dispatch bugs are `--version`, `--format`, and `@subcommand`
    (tests/smoke/SPEC.md "Why"; PLAN P5-02 asked for "three dispatch bugs behind a green
    suite"). The comment also cites a Foundry task ID, which means nothing to anyone reading
    `ci.yml` after merge.
  - `docs/reference/ci.md` sends readers to `test-coverage-gaps.md` for why the smoke test
    exists. P7-01 correctly removed that page's CLI dispatch entry, so the pointer now leads
    nowhere relevant.
- **What would break:** readers of the published journal and the CI config get a wrong account
  of what the harness covers and why it exists.
- **Minimal fix:** add one direct end-to-end assertion. Set a secret before
  `core multisite-convert`, read it back with `--reveal` after network activation, and compare.
  That makes the journal's claim true and pins the one `src/` defect this flight found through
  the real binary. Then correct the other sentences, rewrite the `ci.yml` comment to name the
  three dispatch bugs with no task IDs, and point `ci.md` at the journal entry.
- **Task:** R3-01 (from P5-01, P5-02, P7-02, and P7-03).

## Spec issues

- **`tests/smoke/SPEC.md` case A** says `wp secret get --version=previous` "is not accepted as
  the slot selector. This pins bug 1's cause, not only its fix." The cause was wp-env's `run`
  wrapper, not WP-CLI (finding 1). A test through the real binary can pin that WP-CLI rejects an
  undeclared `--version`, which R3-01 adds. It cannot pin a wrapper the harness deliberately
  never goes through. I have not queued a change to the detailed spec. It is the operator's
  design document, and its "Why" paragraph is still true as history. The operator may want to
  reword the case A sentence.
- **`docs/SPEC.md` §4** limits `src/` changes to where the detailed spec calls for them. This
  flight carries R1-01. Rounds 1 and 2 kept it here because the code now matches
  `docs/spec/network.md` and no interface changed. I agree, and the journal now says so plainly.

## Manual checks still owed

From HANDOFF.md and the progress log, all `NOT VERIFIED (human)`:

- **P1-02:** `make smoke` on a host without Docker (MySQL on `127.0.0.1`, `DB_PASS` as needed)
  provisions the install. Check the WP-CLI pin (2.12.0, SHA-256 `ce34ddd8…20d85c`) against the
  release page by eye.
- **P2-03:** `make smoke` on a host without Docker runs case A green.
- **P3-03:** read the full TAP output once by eye for any line that shows a value or a key.
  My 139-line run, and the two mutation runs with failures, had none. Failing diagnostics print
  lengths and exit statuses only.
- **P4-03:** run the uncatchable-fatal drop-in row on a PHP newer than 8.3 and confirm it is
  still a fatal. See the note below: the smoke runs so far used PHP 7.4.33, not 8.5.10.
- **P5-03:** the `smoke` CI job is green on PHP 7.4 and 8.3. `make smoke` on a host without
  Docker passes both the single-site and multisite passes.
- **P6-02:** a reader confirms the P6-01 commit's three `not ok` groups match the detailed
  spec's three bugs one to one. They do. Also note that bug 1's group shows `--version=previous`
  working through the real binary (finding 1).
- **P7-04:**
  1. `make smoke` on a clean checkout with a local MySQL.
  2. The CI `smoke` job is green on 7.4 and 8.3.
  3. `npm run docs:build` renders the new journal entry in the sidebar in date order.
  4. Read the journal entry for voice and for anything private. Do this after R3-01 rewrites it.

## Notes

- HANDOFF.md and the P4-03 progress entry say the smoke pass ran the uncatchable-fatal row
  "under PHP 8.5.10". wp-env's `cli` container, where every smoke run on this branch happened,
  runs PHP 7.4.33 (`.wp-env.json` `phpVersion: "7.4"`, confirmed with `php -r 'echo
  PHP_VERSION;'`). `test-coverage-gaps.md` correctly says 7.4.33. Only the pipeline documents
  are wrong, so this is not a task. It does mean the P4-03 manual check has no evidence behind
  it yet.
- `convert_to_multisite` captures `SITE2_ID` and `SITE2_URL` with bare command substitutions.
  Under `set -e`, a failing `site create` aborts the run with no `not ok` line. The trap still
  exits 1, so this is only a diagnostic gap.
- The R2-01 pattern's `[^#]*` stops at the first `#`. A diagnostic that interpolates `${#OUT}`
  and then, later on the same line, a value variable would not be scanned past the `#`. No
  line in the file has that shape. This is too small for a task.
