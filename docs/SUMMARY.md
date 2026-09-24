# Build summary: build/kms-keyring

**Merge line:** `build/kms-keyring`, base `1209b5013018` → head `e174011`. 59 commits before this
summary. 3 review rounds. Final verdict: **APPROVED**. 22 of 22 tasks done, none blocked or skipped.

## What was built

**Phase 0, keyring contract (P0-01 to P0-03).** A reusable keyring conformance suite
(`tests/includes/class-wp-secrets-keyring-conformance.php`) that any `WP_Secrets_Keyring` can be
run against. `Mock_Keyring` could not pass it: it was deterministic and returned `false` on a
failed decode. It now uses a random nonce and an integrity tag, and returns `WP_Error` on every
failure. It is still not cryptography. The keyring interface docblock now states that `wrap()` must
be non-deterministic.

**Phase 1, root-key caching (P1-01 to P1-03).** `WP_Secrets_Key_Manager` caches the unwrapped root
key for the request. The cache is served only while the stored wrapped value is unchanged, and an
unwrap error is never cached. This means a remote keyring (KMS) gets one unwrap call per request,
not one per secret read. It is documented in the examples README, the spec page, and the new
ADR 0009.

**Phase 2, `wp secret rotate --from` (P2-01, P2-02).** `wp secret rotate` takes
`--from=config|config-previous`. It unwraps the root key with the old keyring and re-wraps it
under the active keyring. This is how an existing site adopts a drop-in keyring such as KMS. It
refuses when the old and new keyrings would be the same configuration.

**Phase 3, examples test harness (P3-01 to P3-03).** A shared examples PHPUnit harness
(`phpunit-examples.xml.dist`, `tests/bootstrap-examples.php`, `make test-examples`). It runs
against a Moto emulator pinned by digest. The existing AWS Secrets Manager example now runs its
conformance suite there. A separate `examples` CI job runs the suite with a Moto service container.
It is not part of `make ci`.

**Phase 4, the KMS keyring (P4-01 to P4-04).** `examples/aws-kms-keyring/secrets.php` is a
single-file `WP_Secrets_Keyring` over AWS KMS, using `wp_remote_post()` and hand-written SigV4 with
no SDK. The tests are a Moto fixture, a conformance run, and an integration suite. The integration
suite covers the round trip, one `Decrypt` for ten secret reads, the config-keyring adoption
error, and adoption via `rotate --from=config`. The README includes an adoption walkthrough and the
IAM permissions it needs.

**Phase 5, documentation and journal (P5-01 to P5-04).** Spec pages brought in line with the code,
journal tracking pages, READMEs and the index updated, and a dev journal entry, "A KMS keyring".
The local Moto container was removed.

**Review fixes (R1-01, R1-02, R2-01).**
- R1-01 restored an end-to-end test scenario that P1-01 had replaced: a misconfigured
  `WP_SECRETS_KEY` must give `KEY_UNAVAILABLE`, not null.
- R1-02 and R2-01 corrected published docs that had become false or pointed at Foundry-only
  records.

## Decisions that shaped it

From PLAN.md Decisions:

- **Shared harness names (P3-01, P3-02):** `phpunit-examples.xml.dist`,
  `tests/bootstrap-examples.php`, `make test-examples`, CI job `examples`, and the endpoint set by
  env var `WP_SECRETS_TEST_AWS_ENDPOINT`. Chosen so the parallel Vault flight can match them. The
  merge cost is accepted.
- **No interface signature changes (all):** any need would go to `open-questions.md`. None arose.
- **No `extraVerify` (all examples tasks):** the examples suite needs Moto and wp-env, so no
  automated gate runs it. Each task ran it by hand.
- **`Mock_Keyring` reworked (P0-01):** random 8-byte nonce, SHA-256 integrity tag, and `WP_Error`
  on failure, so it passes the conformance suite. Still not cryptography.
- **What "same configuration" means for rotate (P2-01):**
  - `--from=config` is refused if the active keyring is a `WP_Secrets_Config_Key_Provider`.
  - `--from=config-previous` is refused only if the constants are identical and the active keyring
    is the config keyring.
  - Anything else is treated as different and fails closed in `unwrap()`.
- **The "new" keyring for rotate is always the active one (P2-01):**
  `_wp_secrets_get_key_manager()->get_keyring()`. That is the drop-in keyring, the broken-drop-in
  keyring (which fails closed), or the config keyring.
- **Cache shape (P1-01):** two private properties, `$cached_root_key` and `$cached_wrapped`. The
  option is still read on every call. There is no static, object cache or transient. Callers rely
  on PHP copy-on-write, so `memzero` on a caller's copy leaves the cache intact.
- **Emulator settings (P3-01):** Moto on `us-east-1` with `testing`/`testing` credentials. Local
  runs use host port 5051 via `host.docker.internal`. CI uses `127.0.0.1:5000`. The XML sets a
  default that a real environment variable overrides.
- **The Moto KMS fixture copies the SigV4 signer (P4-01, P4-02)** rather than reaching into the
  example's private method.
- **Examples suite is single-site only (P3-01):** multisite is left to the Vault flight.
- **One ADR (P1-02):** 0009, root key cached for the request. `rotate --from` gets no ADR because
  it is `cli/`. The ADR number may be renumbered at merge.
- **KMS error codes (P4-01):** everything is `WP_SECRETS_ERROR_KEY_UNAVAILABLE`, except a bad
  `wrap()` argument, which is `INVALID_VALUE`. No plaintext, key or blob appears in messages.
- **Tunables (P4-01):** everything comes from the detailed spec. The timeout is
  `AWS_KMS_Keyring::TIMEOUT` (3 s) and never a literal.
- **Journal entry written by hand (P5-03).** The `/journal-entry` skill was not used.
- **Hand-written reference files (P3-02):** `ci.md`, `migrating-from-displace.md` and
  `drop-in-example.php` in `docs/reference/` are hand-written and may be edited. Only four files are
  generated.

From HANDOFF.md Interpretation choices:

- **Phase-end push tasks (P0-03, P3-03, P4-04, P5-04)** had no code change, so each landed as an
  empty `<ID>: <title>` commit followed by `git push`.
- **P5-01:** `docs/spec/scope.md` lists `rotate` by name only, with no flags, so it was left
  unedited and does not mention `--from`. `providers-and-keyrings.md` was already accurate.
- **P5-02:** the `docs/index.md` description of `test-coverage-gaps.md` was not changed, because
  its text had not changed.
- The R1 and R2 fix tasks had no interpretation choices.

## Assumptions still in play

None. No `⚠️ ASSUMPTION` config keys were introduced. The detailed spec named every constant,
including the 3 s KMS timeout.

## Spec issues

These are edits for SPEC.md (and `examples/aws-kms-keyring/SPEC.md`), from PLAN.md and all three
review rounds:

- The commit that added docs/SPEC.md refers to `examples/kms-keyring/SPEC.md`. The real path is
  `examples/aws-kms-keyring/SPEC.md`.
- `examples/README.md` said each binding has its own `composer.json`, which contradicts the
  no-Composer rule. P5-02 fixed the README. The SPEC does not need to change.
- `CLAUDE.md` says `docs/reference/` is generated and never edited by hand, but only four files
  are generated. `ci.md`, `migrating-from-displace.md` and `drop-in-example.php` are hand-written.
  The CLAUDE.md wording should say so.
- Detailed spec §4 requires `Mock_Keyring` to pass the conformance suite, but as specified it
  could not. P0-01 resolved this. The spec could note the mock's new behaviour.
- Detailed spec §3's "same configuration" rotate refusal is not implementable through the
  interface as written. The spec should adopt the `instanceof` plus constant-comparison rule that
  was built.
- **Where the live-AWS result is recorded** (all three review rounds): detailed spec §5 says "in the
  commit message", but docs/SPEC.md §1 says human steps are `NOT VERIFIED (human)`. Pick a place
  for the human to record the live run, such as a follow-up commit or the journal.
- `ci.yml` runs only on `main` pushes and PRs, so the `examples` job's green run can only be seen
  on the PR.
- The "ten reads, one KMS call" check is automated against Moto
  (`test_ten_secret_reads_make_one_kms_decrypt_call`). Only the live count is still manual.
- `open-questions.md`, `test-coverage-gaps.md` and `proposal-questions.md` carry a `date:` field,
  although `CLAUDE.md` says tracking documents are undated. The owner should decide which is right.
- **The examples suite has no automated gate** (all three review rounds):
  - docs/SPEC.md §7 allows `extraVerify` only for existing make targets, and none starts Moto plus
    wp-env.
  - As a result, `foundry_mutate` cannot reach `examples/`.
  - A later flight should add a make target that starts Moto and runs the suite.

## Manual checks owed

- **Phases 0 to 2:** none required by SPEC.
- **Phase 3:** the `examples` CI job going green on this PR. It has never run, because it only
  triggers on PRs and pushes to `main`. Check that the Moto service container starts and that the
  suite reports 30 tests with 1 expected skip (the read-only-provider test).
- **Phases 4 and 5:** the live AWS KMS run from `examples/aws-kms-keyring/SPEC.md` "Done when",
  using a throwaway AWS account and the README's IAM permissions:
  - A fresh site works end to end.
  - An existing config-keyed site adopts KMS with `wp secret rotate --from=config`, and its secrets
    still read afterwards.
  - A request that reads ten secrets makes exactly one KMS `Decrypt` call.
  - Record the result somewhere, since the spec's "commit message" location is ambiguous (see Spec
    issues).
- **Phase 5:** the local Moto container `secrets-api-moto-kms` is removed but the image is kept.
  Re-run it from the pinned digest to test locally.
- **Before merge:** strip the Foundry files (`docs/PLAN.md`, `PROGRESS.md`, `HANDOFF.md`,
  `REVIEW.md`, `SUMMARY.md`, `SPEC.md`, `foundry.json`) from `docs/`, per the parallel-flights
  practice. ADR 0009 may need renumbering against the Vault and smoke flights.

## Review history

- **Round 1: CHANGES REQUESTED.** 2 findings, 2 fix tasks (R1-01, R1-02), 0 unblocked,
  converging.
  - A weakened test in P1-01.
  - Four false or stale published-doc statements, from P3-01, P3-02, P4-03, P5-02 and P5-03.
- **Round 2: CHANGES REQUESTED.** 1 finding, 1 fix task (R2-01), 0 unblocked, converging.
  - **This finding recurred.** It traced to P3-01, P5-02 and P5-03 again, the same false-docs
    class as round 1.
  - The "CI does not provide Moto" sentence had been fixed in the KMS README only, and a copy
    remained in the Secrets Manager README.
  - Published journal pages also cited Foundry task IDs.
- **Round 3: APPROVED.** 0 findings, 0 fix tasks, no recurrence. **This was a notes-only
  approval.** Its `## Notes` flagged these items, which were not queued as work:
  - The SigV4 signing comment in both AWS examples gives the wrong reason: Moto does not verify
    signatures.
  - A redundant substring assertion in
    `test_rotate_from_config_previous_refuses_when_both_constants_are_identical`.
  - `KeyId` pinning on `Decrypt` is implemented but untested.
  - The conformance non-determinism test docblock gives a true but not load-bearing reason.
  - Two over-long lines left by R2-01 in the journal files.
  - R2-01's commit body carries a `Manual check:` line, which is reserved for push tasks.

## Pipeline friction

- review, tool-gap: `foundry_mutate` can only run the configured verify/extraVerify commands. This
  flight's plan set no extraVerify for the examples suite, because it needs Moto and wp-env. So
  `examples/aws-kms-keyring/secrets.php` cannot be mutation-sampled through the tool at all: any
  mutation there "survives" trivially, and the reviewer has no sanctioned way to sample that
  module.
