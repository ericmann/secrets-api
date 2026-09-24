# Review: build/kms-keyring
Round: 1

**Verdict: CHANGES REQUESTED**

Reviewed `1209b5013018..80a3c94`, commit by commit, against `docs/SPEC.md`,
`examples/aws-kms-keyring/SPEC.md` and `docs/PLAN.md`.

What I ran myself:

- `foundry_verify`: all 13 constraints pass, with no fixture failures and no hits.
  `bin/ci-local.sh --keep` is green: 481 tests single-site and 481 multisite.
  `make reference-check` is green.
- The examples suite, against a Moto container I started from the pinned digest
  `sha256:91fd602a…ae32c` (the same digest as `ci.yml`) and removed afterwards:
  30 tests, 93 assertions, 1 skip (the read-only provider test, which the suite skips
  for a writable provider). That matches the log.
- `foundry_mutate` on each module the verify commands reach. Every mutation was
  killed:
  - Key manager: caching a `WP_Error` from `unwrap()` was caught by
    `test_an_unwrap_error_is_not_cached`.
  - Key manager: serving the cache without comparing the wrapped value was caught by
    `test_a_changed_wrapped_value_is_unwrapped_again_rather_than_served_from_cache`
    and by the three-state test.
  - CLI: making `--from=config` unwrap with the previous key was caught by
    `test_rotate_from_config_moves_the_root_key_onto_the_dropin_keyring` and
    `test_rotate_never_logs_key_material`.
  - `Mock_Keyring`: a fixed nonce was caught by the Mock conformance
    non-determinism test and by the rotate cache test.
  - One earlier key-manager mutation (`if ( true )`) was caught by phpstan, not by
    a test. I replaced it with the `is_object()` variant listed above, which reached
    the tests.
- `examples/aws-kms-keyring/secrets.php` could not be mutation-sampled. The examples
  suite is not a configured verify command, so there was nothing to run the mutation
  against. I logged this as pipeline friction. I checked it by reading instead:
  - `KeyId` is sent on `Decrypt`.
  - The encryption context is the fixed class constant.
  - The timeout is `self::TIMEOUT`.
  - Every `WP_Error` uses `KEY_UNAVAILABLE`, or `INVALID_VALUE` for a bad `wrap()`
    argument.
  - No message echoes a request body, key material, or blob.

## Findings

### 1. Constraint: an existing test was weakened (R1-01)

- **Category:** 1, Constraints. This is the reader-checked constraint "no existing
  test deleted or weakened".
- **Where:** `tests/phpunit/test-secrets-three-state-contract.php:88-114`
  (`test_key_unavailable_is_wp_error_not_null`), changed in P1-01 (`9458df6`).
- **What is wrong:** the test used to prove one end-to-end scenario: an operator
  sets `WP_SECRETS_KEY` to an unusable value after secrets exist, and
  `wp_get_secret()` returns `WP_SECRETS_ERROR_KEY_UNAVAILABLE`, not null. P1-01
  replaced that scenario with a different one, a corrupted wrapped-root-key option,
  and dropped `@runInSeparateProcess`. The misconfigured-constant path is no longer
  checked end to end anywhere. Only the keyring-level unit test in
  `test-wp-secrets-config-key-provider.php:212` still covers it.
- **Why the rewrite was not needed:** the original premise still holds under the
  cache, as long as the write does not prime the static key manager. Write the secret
  through a hand-built `WP_Secrets_Libsodium_Provider( new WP_Secrets_Option_Store(),
  new WP_Secrets_Key_Manager( new WP_Secrets_Config_Key_Provider() ) )`, then
  `define( 'WP_SECRETS_KEY', 424242 )`, then call `wp_get_secret()`. The fresh
  request-scoped manager unwraps under the unusable constant. The same
  hand-built-provider pattern already appears in P2-01's and P4-02's tests.
- **What would break:** a regression that let a misconfigured `WP_SECRETS_KEY`
  collapse into null, or into a fourth state, would pass the suite.
- **Minimal fix:**
  - Restore the original scenario under the original test name, as an
    isolated-process test that writes through a hand-built provider.
  - Keep the corrupted-option scenario as an additional test with a new name.
- **Task:** P1-01.

### 2. Spec drift: the published docs say things that are not true (R1-02)

**Category:** 5, Spec drift. SPEC §2 requires the documentation to match the code,
and requires the journal entry to cover "what it found".

**a. The journal entry misreports the Mock_Keyring finding.**
`docs/journal/2026-09-24-a-kms-keyring.md:55-62`, task P5-03.

- The entry says `Mock_Keyring` "is weaker than the contract it stands in for"
  because it is not a signed network request, has no timeout, and cannot produce the
  `kms1:` error. It then says this gap "is recorded in open-questions.md".
- Neither claim is true:
  - `open-questions.md` never mentions `Mock_Keyring`. I checked the diff.
  - The real finding, which PLAN's Spec issues and P0-01 record, is different. The
    mock was deterministic and returned `false` on a failed decode, so it failed the
    keyring contract the suite checks. P0-01 fixed it, and it now passes.
- As written, a public page describes a gap that does not exist, points readers to a
  record that does not exist, and leaves out the change that was actually made.

**b. The docs publish this worktree's directory name as if it were the plugin's.**
`examples/README.md:96`, `examples/aws-kms-keyring/README.md:192` and
`examples/aws-secrets-manager/README.md:148`. Tasks P3-01, P4-03 and P5-02.

- All three print
  `npx @wordpress/env run --env-cwd=wp-content/plugins/kms-keyring …`.
- `kms-keyring` is this flight's worktree directory. `bin/ci-local.sh:19-20` derives
  the real path from `basename "$PWD"`, and the main checkout is `secrets-management`.
- After merge the published command fails for everyone.

**c. `docs/reference/ci.md:80-82` is out of date.**
Task P3-02, overtaken by P4-01.

- It says the Moto service is what "the AWS Secrets Manager example runs its
  conformance suite against".
- Since P4-01, the KMS keyring's conformance and integration tests run there too.

**d. `examples/aws-kms-keyring/README.md:195-196` contradicts the CI job.**
Task P4-03.

- It says `make test-examples` needs Moto, "which CI does not provide by default".
- The `examples` CI job added in P3-02 provides exactly that. The accurate statement
  is that `make ci` does not include it, and the separate `examples` job runs it.

**Minimal fix:**

- Rewrite the journal paragraph to state the real finding.
- Replace the hard-coded `kms-keyring` path with the `$(basename "$PWD")` form, or a
  named placeholder, in all three READMEs.
- Name both examples in `ci.md`.
- Correct the KMS README sentence.

## Spec issues

- Detailed spec §5 says the live-AWS result "goes in the commit message", while
  docs/SPEC.md §1 says human steps are logged `NOT VERIFIED (human)`. PLAN resolves
  this correctly: docs/SPEC.md wins on process. A human who does the live run will
  need to record it somewhere other than an existing commit.
- docs/SPEC.md §7 allows `extraVerify` only for make targets that already exist. The
  examples suite can only run inside wp-env with Moto up. As a result, no automated
  gate, and no reviewer mutation, reaches `examples/`. The tests are good, but they
  are only as current as the last person who started Moto by hand. A future flight
  may want a make target that starts Moto and runs the suite inside wp-env, so it can
  be wired into `extraVerify`.

## Manual checks still owed

Copied from HANDOFF.md:

- **Phase 3:** the `examples` CI job going green. It only runs on a pull request or a
  push to `main`, so nobody has seen it run yet. `NOT VERIFIED (human)`.
- **Phases 4 and 5:** the live AWS KMS run from `examples/aws-kms-keyring/SPEC.md`
  "Done when". It covers a fresh site, adopting an existing site with
  `wp secret rotate --from=config`, and a count of KMS calls for a request that reads
  ten secrets (expected: 1). `NOT VERIFIED (human)`.
- **Phase 5:** the local Moto container `secrets-api-moto-kms` was removed. The image
  was kept.

## Notes

- **The signing comment disagrees with the coverage-gaps page.** The comment above
  the endpoint override, in `examples/aws-kms-keyring/secrets.php:210-215` and the
  same block in `examples/aws-secrets-manager/secrets.php`, gives this reason for
  signing `host:port`: "or the emulator's own signature check fails". The new
  `test-coverage-gaps.md` entry says, correctly, that Moto does not verify SigV4 by
  default. The code is right: the signed host should match what `WP_Http` sends. Only
  the stated reason is wrong. Worth fixing when either file is next touched.
- **One conformance comment gives the wrong reason.** The comment on
  `test_two_wraps_of_the_same_bytes_return_different_strings` in
  `tests/includes/class-wp-secrets-keyring-conformance.php` says the reason is
  ciphertext-comparison leakage. PLAN asked for the caller that depends on the
  property, which is `rotate_site_key()` and `update_site_option()`. The interface
  docblock and `extension-points.md` both give the right reason.
- **One assertion adds nothing.** In
  `test_rotate_from_config_previous_refuses_when_both_constants_are_identical`, the
  second `assertStringContainsString( 'WP_SECRETS_KEY', … )` cannot fail on its own,
  because `WP_SECRETS_KEY` is a substring of `WP_SECRETS_KEY_PREVIOUS`.
- **`KeyId` pinning on `Decrypt` is present by reading but untested.** A test that
  wraps under key A and unwraps with a keyring pinned to key B would prove it, if
  Moto enforces `KeyId` on `Decrypt`.
- **The ignore-file edit came from the pipeline.** The `.gitignore` change
  (`.foundry/implement.lock`) came from Foundry's own `chore: start implementation
  run`, not from a task. It also removed the file's trailing blank line.
- **Worth confirming before merge.** The `test_rotation_does_not_change_any_derived_master_key`
  edit in P1-01 is not a weakening. The property is still asserted, on a fresh manager,
  and the rotating manager's primed cache is the designed behaviour.
