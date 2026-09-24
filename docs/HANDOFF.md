# Handoff: Vault provider example

**Branch:** `build/vault-provider`
**Base:** `1209b5013018` (main)
**Head at handoff:** `253640a` (progress: P6-04 done)
**Task counts:** 17 total — 17 done, 0 todo, 0 in progress, 0 blocked, 0 skipped.

## Blocked and skipped tasks

None. All 17 tasks in `docs/PLAN.md` completed.

## Interpretation choices

- **P5-01** (AWS site-scope naming): added a private `scope_prefix( $network )` on
  `AWS_Secrets_Manager_Provider`, delegated to by both `aws_name()` and `wp_name()`, reading
  `get_current_blog_id()` at call time rather than caching it, so it stays correct across a
  mid-request `switch_to_blog()` — matching the pattern the Vault provider already uses.
- **P6-01** (Vault example README): the README links
  [ADR 0009](decisions/0009-cap-a-many-version-backend-to-two-slots.md) from question 2, per the
  plan's instruction, even though that ADR is created later in the same phase by P6-02. The link
  was a dangling relative path for the one commit between P6-01 and P6-02 landing; it resolves in
  the final state. Creating the ADR was explicitly out of scope for P6-01.
- No other task required a judgment call beyond what the plan specified; each task's Design
  constraints and Files touched were followed as written.

## ⚠️ ASSUMPTION config keys

- `Vault_KV2_Provider::REQUEST_TIMEOUT = 5` (seconds) — set in an earlier phase (P1/P2), confirmed
  by measurement in P4-02: a refused connection returns in ~0.005s, a non-routable address is
  bounded near/under 5s. Not retuned in this phase.
- `Vault_KV2_Provider::MAX_VERSIONS = 2` — the two-slot cap this whole flight is about (see
  ADR 0009). Not a tunable in the "adjust me" sense; it is the deliberate translation this example
  makes, written down as a decision rather than a default that might need changing.

Neither constant was touched in this phase; both were already in place from earlier phases and are
noted here because they are the two `⚠️ ASSUMPTION`-tagged constants the `docs/foundry.json`
constraints (`vault-timeout-is-a-constant`, `vault-max-versions-is-a-constant`) enforce stay wired
to their single home.

## What a human must check by hand, per phase

**Phase 4** (multisite isolation, sealed/unreachable behaviour, `needs_rotation` metadata):
1. The `examples` job is green on GitHub Actions, single site and multisite.
2. Against a real sealed Vault (`vault operator seal` on a non-dev server), `wp secret get`
   reports an error rather than absence.
3. `wp secret health` on a real site shows the flagged secret after `wp secret import-option`.

**Phase 5** (AWS site-scope naming fix):
1. Against live AWS, a secret set on blog 1 appears in the console as `wp/site/1/<name>`.
2. The README's "Upgrading from an earlier copy of this example" rename walkthrough works on a
   throwaway AWS account.

**Phase 6** (Vault example README, ADR 0009, spec pages, journal, indexes):
1. **An OpenBao run**: start `openbao/openbao` in dev mode on another port, point `VAULT_ADDR` at
   it, run the examples suite (`make test-examples` or the two wp-env commands in
   `examples/vault-provider/README.md`), and record the result. CI tests Vault only; this is the
   one manual cross-check the README promises.
2. The `examples` CI job (`.github/workflows/ci.yml`) is green on GitHub Actions — it was verified
   locally via `bin/ci-local.sh --keep` and via direct wp-env runs against the pinned Vault dev
   container throughout this flight, but never on a hosted runner.
3. `npm run docs:build` in `site/` renders the new README-linked pages
   (`examples/vault-provider/README.md`, `examples/aws-secrets-manager/README.md`'s updated
   sections), ADR 0009, and the journal entry `docs/journal/2026-09-24-a-vault-provider.md`, with
   the journal sidebar sorted correctly by its `date` frontmatter.
4. A reviewer reads `examples/vault-provider/README.md`'s "The four questions" section against
   the detailed spec's four questions and confirms each is actually answered, not just labelled.

## Anything else a reviewer should know

- **Three separate, isolated commits** carry this flight's substance, matching the plan's
  intent that each stand alone: `f8ed035` (P5-01, the AWS site-scope fix, touches only the AWS
  example + its README + its new test + `phpunit-examples.xml.dist`), `ef92129` (P6-01, the Vault
  README and index updates), `d9ee7f3` (P6-02, ADR 0009 + four spec pages' "As built" sections),
  `14b05e7` (P6-03, the three journal tracking pages + the new journal entry + `docs/index.md`).
  Phase-end tasks (`P4-03`, `P5-02`, `P6-04`) are empty marker commits plus a `docs/PROGRESS.md`
  update each, since their only job was to push and record what needs a human — no code or docs
  changed in those.
- **`examples/aws-secrets-manager/secrets.php`'s `'site'` fingerprint scope for network secrets**
  (it fingerprints under `'site'` while the shipped provider and the Vault example both use
  `'network'`) is a pre-existing, small inconsistency that was deliberately left alone this
  flight — it's out of Deliverable 3's scope, and it's written down in both
  `docs/journal/2026-09-24-a-vault-provider.md` and this file rather than silently diverging
  further or silently fixed without spec authority.
- **The local `secrets-api-vault` Docker container was removed** at the end of P6-04
  (`docker rm -f secrets-api-vault`), per SPEC §7. Anyone re-running the examples suite locally
  needs to re-create it with the `docker run` command in
  `examples/vault-provider/README.md`'s "Run the tests" section (pinned digest
  `sha256:47f14a6acb98f48d798a07df7c83f23a6e636e1cf724c5f8ff165cb32667a1e2`, matching `Makefile`
  and `ci.yml` verbatim).
- **Every `bin/ci-local.sh --keep` and `make reference-check` run across all 17 tasks was green**;
  no constraint in `docs/foundry.json` was ever relaxed or worked around. The examples suite (both
  single-site and multisite wp-env passes) went from 62 tests before this flight to 67 after the
  P5-01 AWS naming tests landed, and stayed green through every subsequent docs-only task.
- **No `src/` change anywhere in this flight.** Everything here is example code
  (`examples/aws-secrets-manager/secrets.php`, `examples/vault-provider/secrets.php` — the latter
  untouched in this phase, only its docs), tests, and documentation. Two items were identified for
  the eventual Trac ticket description (documenting what "previous" means past two versions, and
  that a `BOUNDARY_PROVIDER` provider may still need local key material) — both are recorded in
  `docs/journal/open-questions.md` and the journal entry, not acted on in code.
