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
