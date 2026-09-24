# HANDOFF

Branch: `build/cli-smoke`
Base: `1209b5013018`
Head: `860bd33`

Task counts: 11 done, 1 blocked, 8 skipped (dependents of the blocked task), 0 todo, 0 in progress. 20 total.

## Blocked

- **P5-01** — Convert to multisite and run the network pass. Implemented `convert_to_multisite`
  and `case_e_multisite` exactly as specified (130 of 131 new assertions passed). The one failure,
  `network-secret health --format=json exits 0`, is not a smoke-test defect: it surfaces a real,
  reproducible data-loss bug in the plugin core. `_wp_secrets_root_key` is stored via
  `get_site_option()` / `add_site_option()`, which read/write `wp_options` on a single site and
  `wp_sitemeta` on multisite. `wp core multisite-convert` never migrates that row between the two
  tables, so the first `get_root_key()` call after conversion finds nothing in `wp_sitemeta` and
  silently calls `generate_root_key()`, creating a brand-new root key. Every secret set before
  conversion becomes permanently undecryptable (verified directly: a second, different
  `_wp_secrets_root_key` row appears in `wp_sitemeta` after conversion, while the original
  `wp_options` row sits orphaned). This directly contradicts `docs/spec/network.md`'s own "Why"
  section: "converting a single site into a network does not strand its secrets." As built, it
  does.

  This task's Files touched is `tests/smoke/smoke.sh` only, so I could not fix the underlying
  `src/` defect here, and working around it in the test (e.g. retiring/deleting the pre-conversion
  secrets before the health check) would hide a real, spec-contradicting bug rather than surface
  it — the opposite of what this smoke suite exists to do. See the task's log entry in
  `docs/PROGRESS.md` for the full investigation. Suggested fix: on activation/upgrade, or as part
  of a `wp core multisite-convert` companion step, copy `_wp_secrets_root_key` from `wp_options`
  into `wp_sitemeta` (`site_id = 1`); or have `get_root_key()` fall back to `get_option()` before
  generating a new key when `is_multisite()` is true and `get_site_option()` returns `false`. That
  change belongs to `src/wp-includes/class-wp-secrets-key-manager.php`, owned by a different flight
  (kms-keyring or vault-provider per the parallel-flights split, or a PLAN update authorizing a
  `src/` fix inside this flight).

## Skipped (cascaded from the P5-01 block)

All of these `dependsOn` P5-01 directly or transitively, so `foundry_task_next` auto-skipped them
without attempting any work:

- **P5-02** — Wire smoke into `make ci`, `bin/ci-local.sh`, and a smoke CI job.
- **P5-03** — Push phase 5 and record the manual check.
- **P6-01** — Prove each historical bug fails the smoke test.
- **P6-02** — Push phase 6 and record the manual check.
- **P7-01** — Update the coverage gaps, the spec pages, and the detailed spec's status.
- **P7-02** — Document `make smoke` in the README and the CI reference.
- **P7-03** — Write the journal entry and link it from the index.
- **P7-04** — Push phase 7 and record the final manual checks.

None of these were started; no code exists for them yet.

## Interpretation choices

- **P4-01** — The task's prescribed negative check (skip setting `WP_SECRETS_KEY` to the new
  value, expect `rotate --yes` or the following `get` to fail) does not actually fail. Skipping
  that line leaves `WP_SECRETS_KEY_PREVIOUS` equal to `WP_SECRETS_KEY` (both the original key), and
  per `docs/spec/rotation.md`, site-key rotation has no requirement that the old and new keyrings
  differ — rotating to an unchanged key is a legitimate no-op that succeeds. Verified by hand
  (disabled the line, reran, 102/102 still passed, reverted). No test encodes this as an assertion
  since it isn't a defect; recorded in the P4-01 commit message and log entry instead.

No other task required an interpretation call; each was implemented literally against the PLAN.md
text and the cited SPEC sections.

## ⚠️ ASSUMPTION config keys

None were introduced in this round. No new config surface was added; the smoke test only drives
the existing CLI.

## What a human must check by hand, per phase

- **Phase 4 (P4-03 log)** — Run the uncatchable-fatal drop-in row (a class implementing
  `WP_Secrets_Keyring` with no methods) by hand on a PHP version newer than 8.3, and confirm it is
  still a fatal, not a catchable `TypeError`/`Error`. This run's own smoke pass already exercised
  that row under PHP 8.5.10 and it fataled as expected (assertion 116 in the P4-02 run), but the
  task calls for an explicit human check against whichever PHP versions the project targets going
  forward.
- **Phases 1–3 (earlier rounds, unchanged this round)** — Their own manual-check log entries in
  `docs/PROGRESS.md` still apply; nothing in this round touched that work.
- **P5-01's finding, once a fix lands** — After `src/wp-includes/class-wp-secrets-key-manager.php`
  is changed to preserve the root key across `wp core multisite-convert`, a human should re-run
  `bin/smoke-install.sh` + `tests/smoke/smoke.sh` from a fresh single-site install through
  conversion and confirm secrets set before conversion are still readable after it, then resume
  P5-01 in a new round.

## Notes for the reviewer

- All 117 assertions from phases 1–4 pass cleanly and repeatably against a real `wp` binary; I ran
  the suite from scratch (fresh `bin/smoke-install.sh`, using a wp-env-provisioned MariaDB
  container as `.smoke`'s database since no local MySQL/MariaDB was available on this host) several
  times across P4-01, P4-02, and again while investigating P5-01, and it was green every time
  before the multisite conversion step.
- `bin/ci-local.sh --keep` and `make reference-check` both pass on the final tree (see the last
  `foundry_verify` run in this round).
- The P5-01 finding is, I believe, the most important thing to read before continuing this PLAN:
  it means phases 5–7 as currently scoped (smoke test only, no `src/` changes) cannot reach a
  fully green multisite pass until the root-key migration gap is fixed elsewhere. Whoever resumes
  this PLAN should either get that fix landed first, or get the PLAN amended to authorize a `src/`
  change inside this flight, before re-attempting P5-01.
- `tests/smoke/smoke.sh`'s `case_c_rotation` and `case_d_dropin` are new this round (P4-01, P4-02);
  `convert_to_multisite`/`case_e_multisite` were left as stubs (`:`) after the P5-01 block reset,
  matching their state at the start of this round's P5-01 attempt.
