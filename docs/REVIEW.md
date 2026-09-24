# Review: HashiCorp Vault KV v2 provider example
Round: 2

**Branch:** `build/vault-provider` (base `1209b50`, head `3d9d3c4` at review start)
**Verdict:** APPROVED

## How this was reviewed

- Read HANDOFF.md (including its Round 1 section), PROGRESS.md, CLAUDE.md, docs/SPEC.md, the
  relevant parts of `examples/vault-provider/SPEC.md`, the round-1 REVIEW.md, and PLAN.md's
  "Review fixes (round 1)". Then read every round-1 fix commit with `git show` (`c2cee99` R1-01,
  `46eec67` R1-02, `daa6292` R1-03). The phase tasks P1-01 through P6-04 were reviewed commit by
  commit in round 1. Since then, only the ten files those three fix commits name have changed
  (`git diff --stat 3be654e..HEAD`, excluding Foundry files). I re-read the whole-branch diff stat
  and the boundary checks below against HEAD.
- `foundry_verify`: all 13 constraints pass with no fixture failures and no hits. That includes
  the new `no-foundry-task-ids-in-shipped-files`. `bin/ci-local.sh --keep` is green for single
  site (456 tests) and multisite (456), and `make reference-check` is green.
- Reader-checked constraints:
  - `git diff main..HEAD --stat -- src plugin cli` is empty.
  - `.wp-env.override.json` is untracked.
  - `tests/` gained only `tests/bootstrap-examples.php`.
  - In `test-coverage-gaps.md`, the round-1 edit stays inside this flight's own section. It also
    removes the doubled blank line before that section, as R1-03 asked.
  - The Vault digest is still identical in the Makefile, `ci.yml`, and the README.
  - `$value` still reaches no `WP_Error`, `error_log()`, or listing.
  - `write_flag()` adds only metadata keys, never the value.
- Started the pinned Vault dev container (it reports 2.1.1) and ran the examples suite myself.
  Single site: 70 tests, 5 skipped. Multisite: 70 tests, 1 skipped. Both green. I removed the
  container afterwards.
- Mutation sampling. `foundry_mutate` cannot reach the examples suite; this was logged in
  round 1. So, as in round 1, I applied each mutation to a copy of the tree in the tests-cli
  container's `/tmp`, never to the working tree, and ran the real examples suite in both modes:
  - **Killed:** `write_flag()` posts only the flag, without `array_merge` with the existing
    `custom_metadata`. This fails exactly
    `test_setting_and_clearing_the_flag_preserves_other_custom_metadata`, in both modes.
  - **Killed:** `request()`'s transport-error code changed from
    `WP_SECRETS_ERROR_STORE_UNAVAILABLE`. This fails the strengthened
    `test_an_unreachable_vault_is_an_error_not_absence` and
    `test_a_transport_failure_is_store_unavailable`.
  - **Covered by overlapping checks:** `Vault_Test_Server::request()` returning silently on a
    transport error. `metadata()`'s and `list_keys()`'s own non-200 checks still fail the harness
    tests loudly, so both checks guard the same behaviour. See Notes.
  - **Survived:** `wipe_recursive()`'s non-204 DELETE check deleted. See Notes. This check is
    defensive, and R1-02 named no test for it.
- Checked the round-1 findings one by one. The merge in finding 1 is fixed and tested. The helper
  collapse in finding 2 now fails loudly: in my flaky local runs the failure messages name the
  failing URL and cURL error at the point of failure, not three tests later. The unreachable test
  in finding 3 asserts the error code. Every item in finding 4 is corrected. `git grep` finds no
  `plugins/vault-provider`, `progress entry`, `empty map`, or task ID in any shipped file.

## Findings

None.

## Interpretation choices (HANDOFF.md)

- **P5-01, `scope_prefix()` reads the blog id at call time:** accepted in round 1 and unchanged.
- **P6-01, the ADR 0009 link dangled for one commit:** harmless, and it resolves at HEAD.
- **R1-01, `write_flag()` reuses the `$meta` that `set()` already read:** consistent with PLAN,
  which proposed exactly this signature. The data write between that read and the flag write does
  not touch `custom_metadata`. For a new secret `$meta` is null, and the merge starts from
  `array()`. The docblock states that the read-then-write sequence is not atomic, as R1-01
  required.

## Blocked and skipped tasks

None. 20 of 20 tasks are done.

## Spec issues

- None in `docs/SPEC.md` or the detailed spec. The two PLAN-level inaccuracies recorded in round 1
  still stand as history:
  - P1-03's "current 1.x release": the pinned image is 2.1.1.
  - PLAN Conventions' "Vault rejects an empty map". The code and README no longer repeat this.

## Manual checks still owed

From HANDOFF.md:

**Phase 2 / Phase 3 (from PROGRESS.md)**
1. On a real wp-env site, the drop-in reports `Provider: Vault_KV2_Provider`, and
   `set`/`get --reveal` round-trip.
2. The sequence `set`/`set`/`retire`/`get --slot=previous` reports absence, and
   `vault kv metadata get` shows the destroyed version.

**Phase 4**
1. The `examples` job is green on GitHub Actions, single site and multisite.
2. Against a real sealed Vault (`vault operator seal` on a non-dev server), `wp secret get` reports
   an error rather than absence.
3. `wp secret health` on a real site shows the flagged secret after `wp secret import-option`.

**Phase 5**
1. Against live AWS, a secret set on blog 1 appears in the console as `wp/site/1/<name>`.
2. The rename walkthrough in the README's "Upgrading from an earlier copy of this example" works
   on a throwaway AWS account.

**Phase 6**
1. An OpenBao run: start `openbao/openbao` in dev mode on another port, point `VAULT_ADDR` at it,
   run the examples suite, and record the result in a commit message.
2. The `examples` CI job is green on GitHub Actions. It has never been verified on a hosted
   runner.
3. `npm run docs:build` in `site/` renders the pages the README links to, ADR 0009, and the
   journal entry, with the journal sidebar sorted by `date`.
4. A reviewer reads `examples/vault-provider/README.md`'s "The four questions" against the
   detailed spec (see the Note on question 3's wording).

**Round 1**
1. The `examples` CI job is green on a hosted runner with the round-1 changes.
2. The next time a task touches a file under the paths of `no-foundry-task-ids-in-shipped-files`,
   spot-check that rule against a real diff that carries a task ID.

## Notes

- **README question 3's reasoning doesn't quite follow.** In
  `examples/vault-provider/README.md:129-133`, the text says the flag is "never omitted, because
  Vault replaces `custom_metadata` wholesale". Now that the write merges, the merge could drop the
  key just as easily. The real reason for writing `"0"` is simply the chosen encoding, since
  `flag_is_set()` reads exactly `"1"`. The `write_flag()` docblock's "re-deriving the rest of the
  map" explanation is similarly thin. Every factual claim is now correct: other keys survive, the
  flag is `"1"`/`"0"`, Vault 1.9+ is required, and the two requests are not a transaction. This
  is phrasing, not a defect. It is worth one tightening pass when a human does the Phase 6
  read-through.
- **Two helper checks have no dedicated test.**
  - `Vault_Test_Server::wipe_recursive()`'s non-204 DELETE check has no test. Removing it passes
    the suite, because an unreachable server fails earlier, at the LIST. The dev server never
    answers a metadata DELETE with anything but 204.
  - `request()`'s `Assert::fail()` is not tested on its own, because the status checks behind it
    catch the same condition.

  R1-02 named its two tests, and both exist and pass. These are extra safety checks, not missing
  coverage of a spec mechanic.
- **Local runs are still flaky, now with clear failure messages.** On this machine the
  examples suite still fails intermittently: 2 of 4 back-to-back single-site runs. The cause is
  `cURL error 28: Failed to connect to host.docker.internal port 8201 after ~5200 ms`, which is the
  Docker Desktop port-forward environment property round 1 described. Since R1-02, the helper's
  failures name the URL and cURL error where the connection fails. When the provider's own request
  is the one that times out, it surfaces as that test's `WP_Error`. I reproduced both failing tests
  green in isolation (12 of 14 runs; the other 2 failures were the same connect timeout). CI's
  service container is on localhost and should not see this.
- Carried over from round 1 and still true: `.gitignore`'s change came from Foundry's own
  `chore: start implementation run` commit, not from a task. Strip it with the other Foundry files
  before merge. `CLAUDE.md`'s Foundry section and `docs/SPEC.md` still hard-code
  `--env-cwd=wp-content/plugins/vault-provider`. That is correct for this worktree, and both are
  Foundry files.
