# Review: build/kms-keyring
Round: 2

**Verdict: CHANGES REQUESTED**

I reviewed `1209b5013018..7ffc9b7` against `docs/SPEC.md`, `examples/aws-kms-keyring/SPEC.md`
and `docs/PLAN.md`. I read the two round-1 fix commits (`387d21d` R1-01 and `936d773` R1-02) line
by line. I re-read the code this flight added to `src/`, `cli/` and `examples/aws-kms-keyring/`.
The only changes since round 1's head (`80a3c94`) are the R1 test file, five docs files, and
Foundry bookkeeping. No `src/`, `cli/` or `examples/*.php` file changed.

What I ran myself:

- **`foundry_verify`:**
  - All 13 constraints pass, with no fixture failures and no hits.
  - `bin/ci-local.sh --keep` is green: 482 tests single-site and 482 multisite. That is one
    more than round 1, from the new
    `test_a_corrupted_wrapped_root_key_is_wp_error_not_null`.
  - `make reference-check` is green.
- **The examples suite:**
  - I ran it against a Moto container started from the digest pinned in `ci.yml`
    (`sha256:91fd602a…ae32c`), then removed the container.
  - Result: 30 tests, 93 assertions, 1 skip (the read-only-provider test).
  - I used the README's new command,
    `--env-cwd="wp-content/plugins/$(basename "$PWD")"`, so it works as published.
- **`foundry_mutate`:**
  - **Libsodium provider.** Changed `get()` to return `null` when `get_master_key()` returns a
    `WP_Error`. **Killed.** It failed three tests, including the restored
    `test_key_unavailable_is_wp_error_not_null` at line 113. So R1-01's scenario really reaches
    the fresh request-scoped unwrap under the unusable constant. It does not pass by accident.
  - **CLI `--from=config`.** Changed the drop-in guard's `instanceof` check to a class that can
    never match. **Killed** by
    `test_rotate_from_config_refuses_when_the_active_keyring_is_the_config_keyring`.
  - My first CLI attempt used `if ( false )`. phpcs caught it (`UnconditionalIfStatement`)
    before any test ran, so I replaced it with the `instanceof` variant above.
  - Round 1 already mutation-sampled the key manager, the rotate path and `Mock_Keyring`. None
    of that code has changed since.
- **Reader-checked constraints, all confirmed:**
  - `KeyId` is sent on `Decrypt`.
  - The encryption context is the fixed class constant.
  - `.wp-env.override.json` is not tracked.
  - `make ci` does not include `test-examples`.
  - `CLAUDE.md`'s original section is unchanged (this branch removes no lines from it).
  - No test was weakened: R1-01 restores the scenario round 1 found missing and keeps the
    corrupted-option scenario under a new name.

R1-01 is fixed. R1-02 fixed the five statements it named. Two statements of the same kind
remain.

## Findings

### 1. Spec drift: published docs still contain a false statement and dead references (R2-01)

**Category:** 5, spec drift. `docs/SPEC.md` §2 requires the documentation to match the code.

**a. `examples/aws-secrets-manager/README.md:152-153` claims CI has no Moto.** From task P3-01.

- The text says `make test-examples` "needs Moto running, which CI does not provide by
  default".
- P3-02 added the `examples` CI job, which runs this suite against a pinned Moto service
  container.
- This is the same false sentence round 1 found in the KMS README (finding 2d). R1-02 fixed
  only the KMS copy, because the task named only that file. This copy is still wrong.
- **What would break:** a reader of the published Secrets Manager README concludes the suite
  never runs in CI and treats it as untested.

**b. Published pages cite Foundry task IDs as if readers can look them up.** From tasks P5-02 and
P5-03 (the P0-01 wording came from R1-02's own task text).

- `docs/journal/2026-09-24-a-kms-keyring.md:58`: "P0-01 made it non-deterministic…".
- `docs/journal/test-coverage-gaps.md:93`: "the output is recorded in the P2-01 commit body".
- `docs/journal/test-coverage-gaps.md:142-143`: "recorded in the P4-04 log entry as not yet
  verified".
- The P4-04 "log entry" is in `docs/PROGRESS.md`, a Foundry pipeline file that is removed from
  `docs/` before merge. After merge the reference points at nothing. This is the class of error
  round 1 flagged: a public page pointing to a record that does not exist.
- "P0-01" and "P2-01" mean nothing to a reader of the published site.
- **What would break:** readers are sent to a log that is gone, or to IDs they cannot resolve.

**Minimal fix (one docs-only task):**

- In the Secrets Manager README, replace the false clause with the sentence the KMS README now
  uses: `make ci` does not include the suite, and the separate `examples` CI job runs it against
  a pinned Moto service container.
- Rewrite the three task-ID references so they stand on their own:
  - "This work made it non-deterministic…"
  - "recorded in the commit that added `--from`"
  - "has not been run yet; it is listed as a manual check in the README"

**Task:** R2-01 (fixes P3-01, P5-02, P5-03).

## Spec issues

These carry over from round 1 and are unchanged:

- **Where the live-AWS result is recorded.** The detailed spec §5 says it "goes in the commit
  message". `docs/SPEC.md` §1 says human steps are logged `NOT VERIFIED (human)`. PLAN correctly
  resolves this in favour of `docs/SPEC.md` on process. Whoever does the live run will need
  somewhere other than an existing commit to record it.
- **The examples suite has no automated gate.** It needs wp-env plus Moto, and no existing make
  target starts both. So no `extraVerify` gate runs it, and no reviewer mutation reaches
  `examples/`. Both rounds' reviewers ran it by hand. A later flight could add a make target that
  starts Moto and runs the suite, so it can be wired into `extraVerify`.

## Manual checks still owed

Copied from HANDOFF.md:

- **Phase 3:** the `examples` CI job going green on the PR. It runs only on a pull request or a
  push to `main`, so nobody has seen it run yet. `NOT VERIFIED (human)`.
- **Phases 4 and 5:** the live AWS KMS run from `examples/aws-kms-keyring/SPEC.md` "Done when".
  It covers a fresh site, adopting an existing site with `wp secret rotate --from=config`, and a
  count of KMS calls for a request that reads ten secrets (expected: 1). `NOT VERIFIED (human)`.
- **Phase 5:** the local Moto container `secrets-api-moto-kms` was removed. The image was kept.
  The container I started for this review, `secrets-api-moto-kms-review2`, was also removed.

## Notes

The first three are round 1 notes that still apply. None of them blocks approval.

- **The signing comment gives the wrong reason.** `examples/aws-kms-keyring/secrets.php:210-215`,
  and the same block in the Secrets Manager example, say the signed host must match "or the
  emulator's own signature check fails". Moto does not verify SigV4 by default, which
  `test-coverage-gaps.md` states correctly. The code is right; only the stated reason is wrong.
- **One assertion adds nothing.**
  `test_rotate_from_config_previous_refuses_when_both_constants_are_identical` asserts
  `'WP_SECRETS_KEY'` as a substring after already asserting `'WP_SECRETS_KEY_PREVIOUS'`, so the
  second check cannot fail on its own.
- **`KeyId` pinning on `Decrypt` is present but untested.** A test could wrap under key A and
  unwrap with a keyring pinned to key B, if Moto enforces `KeyId` on `Decrypt`.
- **The rotate cache behaves correctly with memzero.** `rotate_site_key()` assigns
  `$root_key` to the cache and then zeroes it. `sodium_memzero()` only wipes a buffer it owns
  exclusively (refcount 1), and `wp_secrets_memzero()` then resets the local variable, so the
  cached copy survives. The existing rotate-then-read tests confirm this.
