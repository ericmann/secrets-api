# Review: build/cli-smoke
Round: 2

**Verdict: CHANGES REQUESTED**

I reviewed `1209b5013018..23d65b9` against `docs/SPEC.md`, `tests/smoke/SPEC.md`, and
`docs/PLAN.md`. I read the round-1 fix commits (`fe08d2a` R1-01, `42154c8` R1-02, `1643f2e` R1-03)
line by line. I also re-scanned the whole branch diff for edits no task called for and found none
beyond what round 1 already reviewed.

My own `foundry_verify` run passed. All 13 constraint rules self-tested clean with zero hits.
`bin/ci-local.sh --keep` ended `All green.` with 459 tests on single site and 459 on multisite, and
`make reference-check` was clean.

What I checked by hand:

- I ran `bin/smoke-install.sh` and `tests/smoke/smoke.sh` inside wp-env: 125 of 125 passed, exit 0.
  No TAP line contains a `smoke-value-` string.
- I reproduced the round-1 defect scenario against the fixed code on a fresh smoke install. I set a
  probe, ran `core multisite-convert`, then `plugin activate secrets-api --network`. After that,
  `secret get --reveal --field=value` exited 0 and returned the probe. I compared the value in the
  shell without printing it.
- After conversion, `get_site_option('_wp_secrets_root_key')` is set and the main site's stranded
  copy is gone. `secret health` and `network-secret health --format=json` both exit 0. This was
  the assertion that blocked P5-01.
- I re-provisioned the smoke install afterwards.

I mutation-tested R1-01 with `foundry_mutate` on `class-wp-secrets-key-manager.php`:

| Mutation | Result |
|---|---|
| `get_root_key()` reads `get_site_option()` directly | killed: adoption and pre-conversion decrypt tests fail on multisite |
| `rotate_site_key()` reads `get_site_option()` directly | killed: rotate-after-conversion test fails |
| Delete the `delete_blog_option()` call | killed: the stranded-row assertion fails |
| Short-circuit the helper's multisite branch | killed, but by phpstan (dead code), not by a test. The next three rows cover the same mechanic through tests. |

The fix is correct and covered, and R1-02 and R1-03 do what their tasks asked. The verdict is
CHANGES REQUESTED for two reasons. First, P5-01 is still blocked and eight tasks are still
skipped. Its blocker is now fixed, so this round unblocks all nine. Second, the diagnostic rule
still has a blind spot, described below.

## Findings

### 1. Category 7 (blocked and skipped tasks): P5-01's blocker is fixed; unblock P5-01 and its eight dependents
- **Where:** `docs/PROGRESS.md` lines 17-25 (P5-01 `[!]`; P5-02, P5-03, P6-01, P6-02, P7-01,
  P7-02, P7-03, P7-04 `[-]`).
- **What was wrong:** P5-01 blocked on a real `src/` defect: multisite conversion replaced the
  root key. R1-01 fixed it at `src/wp-includes/class-wp-secrets-key-manager.php:248-282`
  (`get_wrapped_root_key()`), and both `get_root_key()` and `rotate_site_key()` now go through
  that helper. I verified the fix end to end on the smoke install, as described above. P5-01's
  failing assertion, `network-secret health --format=json exits 0`, now passes by hand.
- **What breaks if left:** phases 5 to 7 never run. That leaves no multisite pass, no `make ci`
  or CI wiring, no regression proof, and no docs or journal. SPEC §1's "Done means" cannot be met.
- **Fix:** unblock all nine this round. No plan change is needed. P5-01's task text still holds,
  and so does everything after it. When P7-01 and P7-03 run, they must reflect that this flight
  changed `src/`. SPEC §2 says the journal covers "anything that changed `src/`". The conditional
  in P7-03 step 2 ("if nothing in `src/` … changed, say that plainly") now takes its other branch,
  so the entry must describe the conversion defect the harness found and the R1-01 fix.
  `docs/spec/network.md` "As built" already carries the R1-01 sentence.
- **Task:** P5-01 through P7-04 (unblocked).

### 2. Category 1 (constraint rule blind spot): the diagnostic rule still cannot see key-holding locals
- **Where:** `docs/foundry.json`, constraint `smoke-diagnostics-never-print-stdout`. The locals it
  misses are at `tests/smoke/smoke.sh:486-488` (`current_key` and `previous_key`, added by R1-02),
  plus PLAN P5-01's planned `VN` and `VS`.
- **What is wrong:** R1-03 widened the pattern to a fixed list of names: `(OUT|VALUE|KEY|OLD|NEW)`
  followed by upper-case characters, or exactly `v1|v2|vr|vd|old|new|porcelain_out`. In the same
  round, R1-02 added `current_key` and `previous_key`. Both hold raw `WP_SECRETS_KEY` material,
  and neither matches. The next task to run, P5-01, adds `VN` and `VS`. Those hold plaintext and
  don't match either. So a `not_ok "…" "$current_key"` or `diag "$VN"` would pass the scan. R1-03's
  goal was that the rule "catches a not_ok or diag that interpolates any variable
  tests/smoke/smoke.sh uses to hold plaintext or key material", and the final tree does not meet
  it. No violating line exists today, so no code is wrong yet. But every new variable reopens the
  same blind spot, and SPEC §3 "No plaintext in output" is the rule this constraint enforces.
- **What would break:** the next diagnostic that interpolates a new key or value variable prints
  key material or plaintext to CI logs, and the constraint scan stays green.
- **Minimal fix:** make the rule independent of individual names. Also match any interpolated
  identifier that contains `key` or `value` in any case, plus `VN` and `VS`. Keep every existing
  fixture. Add `shouldMatch` fixtures for the missed shapes, and `shouldNotMatch` fixtures copied
  from the real diagnostic lines in the file, so a widened pattern cannot flag them.
- **Task:** R1-03's goal, fixed by R2-01.

## Spec issues

- **`docs/SPEC.md` §4 says to change `src/wp-includes/` only where the detailed spec says to**,
  but this flight now carries a `src/` fix (R1-01). The round-1 reviewer raised this and resolved
  it by keeping the fix here, because the code now matches what `docs/spec/network.md` already
  promised and no interface changed. I agree. If you would rather move R1-01 to its own branch,
  P5-01's multisite health assertion depends on it and must wait for that branch.

## Manual checks still owed

From HANDOFF.md and the progress log, all `NOT VERIFIED (human)`:

- P1-02: `make smoke` on a host without Docker (MySQL on `127.0.0.1`, with `DB_PASS` as needed)
  provisions the install. The WP-CLI pin (2.12.0, SHA-256 `ce34ddd8…20d85c`) matches the release
  page by eye.
- P2-03: `make smoke` on a host without Docker runs case A green.
- P3-03: read the full TAP output once by eye for any line that shows a value or a key. My own
  125-line run had none, but this check is still owed.
- P4-03: run the uncatchable-fatal drop-in row on a PHP newer than 8.3 and confirm it is still a
  fatal. The implementer's run under PHP 8.5.10 fataled as expected.
- HANDOFF's post-R1-01 check: re-run the install and smoke through conversion and confirm that
  secrets set before conversion are still readable. I did this by hand once in review, as
  described above. P5-01's own full run (A to E, twice) is the lasting evidence and is still to
  come.

## Notes

- `tests/phpunit/test-wp-secrets-key-manager.php:198`:
  `assertFalse( get_blog_option( … ROOT_KEY_OPTION ) )` prints the wrapped root key in the failure
  message. I saw this when mutating out the `delete_blog_option()` call. The value is
  ciphertext, not raw key material, so SPEC §3's rule is not broken. An
  `assertFalse( false !== … )` form would keep even the wrapped value out of test output. This
  is too small for a task.
- R1-02's negative check (b) was reasoned through, not executed, because the sandbox refused to
  run it. The reasoning holds. Without the `config set WP_SECRETS_KEY "$new"` write, both
  constants hold `$old`, and the `!=` check reports `not ok`.
- `tests/smoke/smoke.sh` is still outside every verify command until P5-02 wires it into
  `bin/ci-local.sh`, so `foundry_mutate` cannot kill a smoke-script mutation yet. The reviewer
  after P5-02 should mutation-sample `smoke.sh`, for example by inverting `assert_status`'s `-eq`.
- Re-running `smoke.sh` without re-provisioning first fails case C's refusal row, because
  `WP_SECRETS_KEY_PREVIOUS` is already defined. This is expected: the design re-provisions on
  every run, and `make smoke` always does.
