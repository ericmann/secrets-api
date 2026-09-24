# Review: build/kms-keyring
Round: 3

**Verdict: APPROVED**

I reviewed the whole branch, `1209b5013018..3c690b0`, against `docs/SPEC.md`,
`examples/aws-kms-keyring/SPEC.md` and `docs/PLAN.md`. Since round 2, the only change is the R2-01
commit (`19be872`), which touched three documentation files, plus Foundry bookkeeping. No file
under `src/`, `cli/`, `tests/` or `examples/*.php` changed. I read R2-01 line by line. I also
re-read the full diff for `src/`, `cli/`, `tests/includes/`, the two bootstraps, `Makefile`,
`phpunit-examples.xml.dist`, the KMS keyring and its two test files, and the journal entry.

What I ran myself:

- **`foundry_verify` (no files argument):**
  - All 13 constraints pass. No fixture failures, no hits.
  - `bin/ci-local.sh --keep` is green: 482 tests single-site and 482 multisite.
  - `make reference-check` is green.
- **The examples suite:**
  - I started Moto from the digest pinned in `ci.yml` (`sha256:91fd602a…ae32c`) as
    `secrets-api-moto-kms-review3` on port 5051.
  - I ran the suite with the README's command, `--env-cwd="wp-content/plugins/$(basename "$PWD")"`.
  - Result: 30 tests, 93 assertions, 1 skip (the read-only-provider test the suite skips itself
    for a writable provider).
  - I removed the container afterwards.
- **`foundry_mutate`, one mechanic per module. All three were killed:**
  - **Key manager (`src/`).** Deleted the cache hit in `get_root_key()`. Killed by 6 tests,
    including `test_unwrap_is_called_once_across_repeated_master_key_derivations` (10 unwraps
    instead of 1) and `test_unwrap_is_called_once_across_many_secret_reads` (11 instead of 1).
  - **CLI (`cli/`).** Made the identical-constants refusal for `--from=config-previous` impossible
    to trigger. Killed by `test_rotate_from_config_previous_refuses_when_both_constants_are_identical`.
    Round 2 already killed the `--from=config` drop-in guard mutation.
  - **Test double (`tests/includes/`).** Disabled `Mock_Keyring`'s integrity-tag check. Killed by
    `Tests_Secrets_MockKeyringConformance::test_unwrap_of_a_value_with_one_flipped_byte_is_a_wp_error`.
  - `examples/` is out of reach of `foundry_mutate`, because no verify command runs the examples
    suite (see Spec issues). Instead I read the KMS tests against the code. The adoption-error test
    would fail without the `kms1:` prefix check, because Moto's error would not say
    `rotate --from=config`. The ten-reads test would see 11 `Decrypt` calls without the cache. And
    the `health` assertion is not vacuous, because a `critical` result calls `WP_CLI::halt( 1 )`.
- **R2-01's own acceptance greps, all clean:**
  - `does not provide by default` appears in neither AWS README.
  - The `\b[PR][0-9]-[0-9]{2}\b` task-ID pattern appears in none of the published paths:
    `docs/journal`, `docs/spec`, `docs/reference`, `docs/decisions`, `docs/index.md`, the three
    example READMEs and `README.md`.
  - The corrected "examples CI job" sentence is in `examples/aws-secrets-manager/README.md:152-153`.
  - I also grepped for `PROGRESS.md`, `HANDOFF.md`, `PLAN.md`, `REVIEW.md`, `Foundry` and
    `plugins/kms-keyring` across the same published paths. There are no hits.
- **Constraints checked by reading, all confirmed:**
  - `KeyId` is sent on `Decrypt` (`examples/aws-kms-keyring/secrets.php:161`).
  - The encryption context is the fixed class constant.
  - `.wp-env.override.json` is not tracked.
  - The `ci:` target does not include `test-examples`.
  - This branch removes no line from `CLAUDE.md`.
  - `--from` has a `: description` line.
  - No plaintext or key material reaches any `WP_Error` message or CLI line.
  - No test was deleted or weakened. The only removed test lines are two I checked:
    - R1-01 restored the three-state scenario and kept the corrupted-option case under a new
      name.
    - In the key manager rotate test, the final assertion now uses a fresh manager built with
      the old keyring. It still states "the old keyring alone is no longer sufficient".

Categories 1 to 3 are clean across the branch. No task is blocked or skipped.

## Findings

None.

## Spec issues

These carry over from rounds 1 and 2, unchanged:

- **Where the live-AWS result is recorded.** The detailed spec §5 says it "goes in the commit
  message", but `docs/SPEC.md` §1 says human steps are logged `NOT VERIFIED (human)`. PLAN
  correctly follows `docs/SPEC.md` on process. Whoever does the live run needs somewhere other
  than an existing commit to record the result.
- **The examples suite has no automated gate.** It needs wp-env and Moto together, and no make
  target starts both. So no `extraVerify` gate runs it, and `foundry_mutate` cannot reach
  `examples/`. All three review rounds ran it by hand. A later flight could add a make target that
  starts Moto and runs the suite, so it can be wired into `extraVerify`.

## Manual checks still owed

Copied from HANDOFF.md:

- **Phase 3:** the `examples` CI job going green on the PR. It runs only on a pull request or a
  push to `main`, so nobody has seen it run yet. `NOT VERIFIED (human)`.
- **Phases 4 and 5:** the live AWS KMS run from `examples/aws-kms-keyring/SPEC.md` "Done when": a
  fresh site, adopting an existing site with `wp secret rotate --from=config`, and a count of KMS
  calls for a request that reads ten secrets (expected: 1). `NOT VERIFIED (human)`.
- **Phase 5:** the local Moto container `secrets-api-moto-kms` was removed and the image kept. The
  container I started for this review, `secrets-api-moto-kms-review3`, is also removed.

## Notes

None of these blocks approval.

- **Wrong reason in the signing comment** (carried over).
  `examples/aws-kms-keyring/secrets.php:210-215`, and the same block in the Secrets Manager
  example, say the signed host must match "or the emulator's own signature check fails". Moto does
  not verify SigV4 by default. The code is right; only the stated reason is wrong.
- **An assertion that adds nothing** (carried over).
  `test_rotate_from_config_previous_refuses_when_both_constants_are_identical` asserts the
  substring `'WP_SECRETS_KEY'` after already asserting `'WP_SECRETS_KEY_PREVIOUS'`, so the second
  check cannot fail on its own.
- **`KeyId` pinning on `Decrypt` is present but untested** (carried over). A test could wrap
  under key A and unwrap with a keyring pinned to key B, if Moto enforces `KeyId` on `Decrypt`.
- **The conformance test gives a different reason for non-determinism.** The docblock on
  `test_two_wraps_of_the_same_bytes_return_different_strings` justifies it by ciphertext
  comparison. The interface docblock and the journal give the load-bearing reason:
  `rotate_site_key()` and `update_site_option()` treat an unchanged value as a failure. Both
  reasons are true. Only the second is the one the key manager depends on.
- **Two R2-01 lines are over-long.** R2-01 left `docs/journal/2026-09-24-a-kms-keyring.md:58` and
  `docs/journal/test-coverage-gaps.md:93` longer than the surrounding wrap width. Markdown renders
  them the same.
- **A convention slip in R2-01's commit body.** It carries a `Manual check:` line, which the
  commit convention reserves for phase push tasks. This is bookkeeping only.
