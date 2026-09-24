# Review: build/cli-smoke
Round: 1

**Verdict: CHANGES REQUESTED**

Reviewed `1209b5013018..3fdc23e` commit by commit against `docs/SPEC.md`, `tests/smoke/SPEC.md`,
and `docs/PLAN.md`. `foundry_verify` passed: all 13 constraint rules self-tested clean with zero
hits, `bin/ci-local.sh --keep` ended `All green.` (456 single-site and 456 multisite tests), and
`make reference-check` passed. I also ran `bin/smoke-install.sh` and `tests/smoke/smoke.sh`
myself inside wp-env: 117 of 117 passed, exit 0. Nothing in the TAP output contains a
`smoke-value-` string or anything shaped like a key. Neither `.smoke/` nor
`.wp-env.override.json` is tracked.

The build cannot be approved because P5-01 is blocked, and the blocker is a real `src/` defect.
I reproduced it independently. There are also two test-strength gaps in the smoke suite and a
blind spot in one constraint rule.

## Findings

### 1. Category 7 (blocked task, real defect): multisite conversion replaces the root key and strands every existing secret
- **Where:** `src/wp-includes/class-wp-secrets-key-manager.php:175-190` (`get_root_key()`), and
  the same read in `rotate_site_key()` at `:206-207`.
- **What is wrong:** the wrapped root key is read with `get_site_option()`. On a single site that
  reads `wp_options`; on multisite it reads `wp_sitemeta`. `wp core multisite-convert` (core's
  `populate_network()`) does not copy the `_wp_secrets_root_key` row across. After conversion the
  first `get_root_key()` finds nothing and calls `generate_root_key()` without any warning.
  `docs/spec/network.md` "Why" says "converting a single site into a network does not strand its
  secrets". As built, it does.
- **Reproduced in review** on the smoke install. Before conversion, `secret set review/probe`
  worked and the root key was in `wp_options`. After `core multisite-convert` and a network
  activate: `get_site_option('_wp_secrets_root_key') === false` was true, and
  `secret get review/probe --reveal` exited **2**. Each table then held one row: the new key in
  `wp_sitemeta`, and the orphaned original in `wp_options`.
- **What breaks:** every site-scope secret written before conversion can never be decrypted
  again. `rotate` after conversion fails with "No root key exists to rotate" if nothing has called
  `get_root_key()` yet. P5-01's `network-secret health` assertion fails because of this, and so
  P5-02 through P7-04 were skipped. The implementer was right not to hide it in `smoke.sh`.
- **Minimal fix:** before generating a new key on multisite, look for the pre-conversion row in the
  main site's options and move it into network storage. Route both `get_root_key()` and
  `rotate_site_key()` through that same lookup. Details are in fix task R1-01.
- **Task:** P5-01, fixed by R1-01.
- **Why P5-01 is not unblocked this round:** `foundry_task_next` picks the first todo task in
  PROGRESS order. An unblocked P5-01 would run before R1-01 and block again. The round-2 reviewer
  should unblock P5-01, P5-02, P5-03, P6-01, P6-02, P7-01, P7-02, P7-03, and P7-04 once R1-01 is
  done and verified. I logged this as Foundry feedback.

### 2. Category 3 (tests): "list never shows a value" is checked against output that can never contain one
- **Where:** `tests/smoke/smoke.sh:365-370`.
- **What is wrong:** the two "list never shows a value" assertions run against
  `list --namespace=… --format=ids`. `cli/class-wp-cli-secret-command.php:301-303` handles `ids`
  by printing only `wp_list_pluck( $entries, 'name' )`, so those assertions pass by construction.
  The `--format=json`, `--format=csv`, and table outputs are the only list outputs that could leak
  a value, and none of them is checked. PLAN P3-02 step 1 says "no `list` output contains `$V1` or
  `$V2`".
- **What breaks:** a regression that adds a value column to `list`'s default fields, or puts
  values into `wp_list_secrets()` entries, would pass the smoke suite.
- **Minimal fix:** assert value absence on the default table, `--format=json`, and
  `--format=csv` outputs, each checked right after its own `run`.
- **Task:** P3-02, fixed by R1-02.

### 3. Category 3 (tests): case C does not check that the key actually changed
- **Where:** `tests/smoke/smoke.sh:469-480`.
- **What is wrong:** the case asserts that the two `config set` calls exit 0, but never that
  `WP_SECRETS_KEY` now differs from `WP_SECRETS_KEY_PREVIOUS`. HANDOFF's interpretation note
  confirms it: with the `config set WP_SECRETS_KEY "$new"` line removed, all 102 assertions still
  passed. The implementer's reasoning is correct (rotating to the same key is a valid no-op, and
  the config keyring does not fall back to PREVIOUS, so a no-op `rotate` would be caught by step
  4). But the harness itself can silently stop doing a real rotation and stay green.
- **Minimal fix:** after the two writes, read both constants back, compare them in shell
  variables, and `ok`/`not_ok` "WP_SECRETS_KEY differs from WP_SECRETS_KEY_PREVIOUS" without
  printing either. The plan's negative check then fails as P4-01 intended.
- **Task:** P4-01, fixed by R1-02.

### 4. Category 1 (constraint rule blind spot): the diagnostic rule cannot see the variable names the code uses
- **Where:** `docs/foundry.json`, constraint `smoke-diagnostics-never-print-stdout`.
- **What is wrong:** the pattern `\$\{?(OUT|VALUE|KEY|OLD|NEW)[A-Z0-9_]*\b` is case-sensitive and
  matches only upper-case names. The suite holds plaintext and key material in lower-case locals:
  `v1`, `v2`, `vr`, `vd`, `old`, `new`, `porcelain_out` (`smoke.sh:262, 284, 451-452, 516`). A
  `diag "$old"` or `not_ok "x" "$v1"` would pass the scan. No such line exists today, so nothing
  is violated yet. But the rule does not protect the variables it was written for, and fix R1-02
  edits the code next to `old`/`new`.
- **Minimal fix:** widen the pattern to the names the code uses and add `shouldMatch` fixtures for
  them. Keep every existing `shouldNotMatch` passing, including the `${#OUT}` length form.
- **Task:** none (rule defect). Fixed by R1-03.

## Spec issues

- **`docs/SPEC.md` §4 says to change `src/wp-includes/` "only where the detailed spec says to", and
  `tests/smoke/SPEC.md` never says to.** At the same time, §2 expects the journal to cover
  "anything that changed `src/` or an interface". The smoke test was built to find defects like
  finding 1, and the fix does not touch any interface. It makes the code match what
  `docs/spec/network.md` already promises. I resolved this by queueing the fix in this flight
  (R1-01) rather than routing it elsewhere. HANDOFF suggested kms-keyring or vault-provider, but
  neither owns multisite conversion. If you would rather land this fix on its own branch, drop
  R1-01 and mark P5-01's multisite health assertion as waiting on that branch. Expect a small
  merge touch-point with `build/kms-keyring` in `class-wp-secrets-key-manager.php`.
- **PLAN P4-01's prescribed negative check could never fail.** Skipping the new-key write leaves
  `KEY == PREVIOUS`, which is a legitimate no-op rotation. R1-02's key-differs assertion is what
  makes that negative check meaningful.

## Manual checks still owed

From HANDOFF.md and the progress log, all `NOT VERIFIED (human)`:

- P1-02: `make smoke` on a host without Docker (MySQL on `127.0.0.1`, `DB_PASS` as needed)
  provisions the install; the WP-CLI pin (2.12.0, SHA-256 `ce34ddd8…20d85c`) matches the release
  page by eye.
- P2-03: `make smoke` on a host without Docker runs case A green.
- P3-03: read the full TAP output once by eye for any line showing a value or key. The reviewer's
  own run of 117 lines had none, but this is still owed.
- P4-03: run the uncatchable-fatal drop-in row on a PHP newer than 8.3 and confirm it is still a
  fatal. The implementer's run under PHP 8.5.10 fataled as expected.
- After R1-01 lands: re-run `bin/smoke-install.sh` + `tests/smoke/smoke.sh` through conversion and
  confirm secrets set before conversion are still readable after it (HANDOFF). P5-01's own
  acceptance run covers this once it is unblocked.

## Notes

- `foundry_mutate` on `tests/smoke/smoke.sh` (`assert_status`'s `-eq` inverted to `-ne`)
  **survived**. No configured verify command runs the smoke suite until P5-02 wires it into
  `bin/ci-local.sh`, so no mutation of the smoke scripts can be killed yet. This is expected at
  this point in the plan, and P5-02 closes it. It is not a separate fix task. The round-2 or
  later reviewer should mutation-sample `smoke.sh` again once P5-02 is done.
- `phpcs.xml.dist`: the `.smoke/` exclusion's reason is an XML comment on the line above, not
  "on the same line" as PLAN P1-01 and SPEC §3 ask. Every existing exclusion in the file uses the
  line-above style, and the reason is present and specific. Left as is.
- `remove_dropin` deletes `$DROPIN` only when this run wrote it (PLAN: "if present"). That is
  safer, and the install script removes any leftover drop-in anyway.
- `smoke.sh:46-51`: `ERR_FILE` is created before the missing-install check, which exits before
  the `EXIT` trap is installed, so one temp file is left behind on that path. Too small to matter.
- The "set --stdin from a pipe" description is exercised with file redirection. The command reads
  `php://stdin` (`cli/class-wp-cli-secret-command.php:72`), which behaves the same for a pipe and
  a file, so coverage is equivalent.
- The `.gitignore` edit in `b2c2864` (`.foundry/implement.lock`) was made by Foundry's own
  run-start, not by the implementer.
