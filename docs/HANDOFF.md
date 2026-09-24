# Handoff

Branch: `build/kms-keyring`
Base: `1209b5013018`
Head: `770baa7`

Task counts: 19 total, 19 done, 0 todo, 0 in progress, 0 blocked, 0 skipped.

## Blocked and skipped tasks

None. Every task in `docs/PLAN.md` completed.

## Interpretation choices, by task ID

- **P3-03 / P4-04 / P5-04 (phase-end pushes):** "Files touched: `docs/PROGRESS.md` (log entry
  only)" tasks carry no code change of their own, so each was landed as an empty commit titled
  `<ID>: <title>` (matching the pattern already established by `P0-03` earlier in this flight),
  followed by `git push`. `foundry_task_done` then committed `docs/PROGRESS.md` on top.
- **P5-01:** `docs/spec/scope.md`'s WP-CLI section lists `rotate` by subcommand name only, with no
  flags described, so per the task's own fallback instruction it was left untouched rather than
  edited to mention `--from`. `docs/spec/providers-and-keyrings.md` was re-read as instructed and
  found already accurate (it already described request-scoped root-key caching from P1-02); no
  sentence needed fixing.
- **P5-02:** `docs/index.md`'s `journal/test-coverage-gaps.md` description text did not change, so
  per the task text it received no edit in that task (P5-03 later added the new journal entry's
  own index line, which was a separate, required edit).
- No other task allowed more than one reading; every other task text specified exact class names,
  method bodies, section headings, or file contents.

## ⚠️ ASSUMPTION config keys

None introduced. Per `docs/PLAN.md` Decisions "Tunables": the detailed spec for this flight named
every constant and the 3 s KMS timeout explicitly, so nothing was invented and there are no tuning
tasks or `⚠️ ASSUMPTION` markers anywhere in this work.

## What a human must check by hand, per phase

- **Phase 0 (P0-*):** none required by SPEC.
- **Phase 1 (P1-*):** none required by SPEC.
- **Phase 2 (P2-*):** none required by SPEC.
- **Phase 3 (P3-*):** the `examples` CI job (added in P3-02) going green — it only runs on a pull
  request or a `main` push, so it has not been observed running in GitHub Actions yet. Logged as
  `Manual check: NOT VERIFIED (human) — examples CI job green on the PR` in P3-03.
- **Phase 4 (P4-*):** the live-AWS-KMS verification named in `examples/aws-kms-keyring/SPEC.md`
  "Done when": a fresh site, adoption of an existing site via `wp secret rotate --from=config`,
  and a count of real KMS calls for a request that reads ten secrets (expected: 1). Everything
  that can be automated for this — the conformance suite and the full integration suite — runs
  green against Moto, but nothing has been run against real AWS KMS yet. Logged in P4-04.
- **Phase 5 (P5-*):** same live-KMS check, restated as the phase-6/final manual step in P5-04's
  log entry, plus the local Moto container (`secrets-api-moto-kms`) has been removed as
  instructed (the image was left in place; re-pull or `docker run` it again to test locally).

## For the reviewer

- **What this flight built:** `examples/aws-kms-keyring/` — a single-file `WP_Secrets_Keyring`
  over AWS KMS (`secrets.php`), a Moto test fixture and a conformance-suite run
  (`tests/class-moto-kms-fixture.php`, `tests/test-aws-kms-keyring-conformance.php`), a full
  integration suite proving the round trip, the one-Decrypt-per-ten-reads claim, the
  config-keyring adoption error, and adoption via `rotate --from=config`
  (`tests/test-aws-kms-keyring.php`), and a README with the adoption walkthrough. Everything else
  in this flight (phases 0–2, not touched by me — I picked up at P3-03) laid the groundwork: the
  keyring conformance suite itself, root-key request-scoped caching in `src/`
  (`WP_Secrets_Key_Manager`), and `wp secret rotate --from=<keyring>` in `cli/`.
- **Where I started:** this session began mid-flight, with 10 of 19 tasks already done (through
  `P3-02`). I did P3-03 through P5-04: the KMS example itself, its integration tests, its README,
  the four `docs/spec/` pages, the journal tracking pages and top-level READMEs, the new dev
  journal entry, and the three phase-end pushes.
- **Test volume:** the examples suite (`phpunit-examples.xml.dist`) is 30 tests (22 carried over
  from the AWS Secrets Manager example plus the P3-01 harness work, 8 new for the KMS example).
  The main suite is unchanged at 481 tests, single-site and multisite, both green throughout.
  `bin/ci-local.sh --keep` and `make reference-check` were run after every task and are green as
  of the head commit.
- **Constraint set:** `docs/foundry.json` carries 13 constraints (filters, plugin/cli/example
  symbol leakage into `src/`, self-guarding, text domain, persistent caching of key material, the
  KMS timeout being the named constant, no SDK in examples, phpcs-ignore reasons, incomplete
  tests, no publish/tag in tooling, `wp_remote_post()`-only in the KMS example, no debug output in
  key paths, and the KMS error code being `WP_SECRETS_ERROR_KEY_UNAVAILABLE`). All 13 passed on
  every `foundry_verify` call across this session, with zero fixture hits.
- **Nothing was blocked or skipped.** Every task's acceptance tests, as written, were satisfiable
  within its own Files-touched list.
- **The one thing this flight could not close:** the live-AWS-KMS manual run. Everything else
  named in `examples/aws-kms-keyring/SPEC.md` "Done when" is done; that one item needs a human
  with a throwaway AWS account, per the README's "IAM permissions" section.

## Round 1

Branch: `build/kms-keyring`. Base: `1209b5013018`. Head: `0ceb68b`.

Task counts (this round, `R1-*`): 2 total, 2 done, 0 todo, 0 in progress, 0 blocked, 0 skipped.
Whole-plan counts: 21 total, 21 done, 0 open.

This round fixed the two findings the reviewer queued in round 0's review.

- **R1-01 — restore the misconfigured-`WP_SECRETS_KEY` scenario.** P1-01 had replaced
  `test_key_unavailable_is_wp_error_not_null`'s original end-to-end scenario (an unusable
  `WP_SECRETS_KEY` constant defined after secrets exist) with a different one (a corrupted wrapped
  root-key option), losing coverage of the original finding. Restored the original scenario
  verbatim per the task text — writes through a hand-built
  `WP_Secrets_Libsodium_Provider( new WP_Secrets_Option_Store(), new WP_Secrets_Key_Manager( new
  WP_Secrets_Config_Key_Provider() ) )` so the write bypasses `_wp_secrets_get_key_manager()`'s
  request-scoped root-key cache (ADR 0009), then `define( 'WP_SECRETS_KEY', 424242 )`, then
  asserts `wp_get_secret()` is `WP_Error` with `WP_SECRETS_ERROR_KEY_UNAVAILABLE`, never null. The
  corrupted-option scenario moved, assertions unchanged, to a new
  `test_a_corrupted_wrapped_root_key_is_wp_error_not_null`. No interpretation needed — the task
  text fully specified the restored body. Verified 11/11 tests in
  `Tests_Secrets_ThreeStateContract` pass single-site and multisite (filtered run), plus the full
  `bin/ci-local.sh --keep` (482 tests, single-site and multisite) and `make reference-check`.
- **R1-02 — correct five published doc statements.** All were made false by earlier work in this
  flight: (1) the journal entry's `Mock_Keyring` paragraph claimed it was "weaker than the
  contract" for not being a network call and claimed the gap was "recorded in open-questions.md",
  which it was not — replaced with the real finding (it was deterministic and returned `false` on
  a failed decode, which P0-01 fixed with non-determinism and `WP_Error`); (2) three READMEs
  (`examples/README.md`, `examples/aws-kms-keyring/README.md`,
  `examples/aws-secrets-manager/README.md`) hard-coded `--env-cwd=wp-content/plugins/kms-keyring`,
  which breaks in any other checkout — replaced with
  `--env-cwd="wp-content/plugins/$(basename "$PWD")"`, run from the repository root, matching how
  `bin/ci-local.sh` derives `CONTAINER_CWD`; (3) `docs/reference/ci.md`'s Moto sentence named only
  the AWS Secrets Manager example, though the `examples` job's Moto container also serves the KMS
  keyring's conformance and integration tests — both are now named; (4)
  `examples/aws-kms-keyring/README.md`'s final paragraph claimed Moto was something "CI does not
  provide by default" though a separate `examples` CI job runs it against a pinned Moto service
  container — corrected. Verified with the exact greps the task specified (all pass, all zero or
  matching as required), `git diff --stat` touching only the five named files, plus
  `bin/ci-local.sh --keep` and `make reference-check`.

**Interpretation choices this round:** none beyond what each task text fully specified; both R1-01
and R1-02 named exact scenarios, wording, and file lists, leaving no open reading.

**⚠️ ASSUMPTION config keys this round:** none introduced or touched.

**What a human must check by hand this round:** nothing new. The unresolved live-AWS-KMS manual
run from round 0 (see "For the reviewer" above) is still the only outstanding manual item in this
flight; neither R1-01 nor R1-02 touched code that check depends on.

**For the reviewer:** both fixes are narrowly scoped to what the review flagged — one test file,
one test method restored plus one renamed, and five doc files with wording/command corrections.
No `src/`, `cli/`, or `examples/*.php` change. `foundry_verify` (all constraints plus
`bin/ci-local.sh --keep` and `make reference-check`) is green on both commits and on the full
tree with no files argument.
