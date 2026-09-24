# AWS KMS keyring example, root-key caching, and rotate --from — Specification

Version: 1.0
Status: ready

This is the Foundry wrapper for this flight. **The design lives in `examples/aws-kms-keyring/SPEC.md`.** Read it in full.
It is authoritative for every behaviour, file name, and test it names. Where it and this file
disagree on process, this file wins. Where they disagree on design, `examples/aws-kms-keyring/SPEC.md` wins.

Background, read before planning: `docs/decisions/0008-the-trac-ticket-replaces-thread-confirmation.md`,
`docs/decisions/0007-fail-closed-on-a-broken-drop-in.md`, `docs/spec/providers-and-keyrings.md`,
`docs/spec/extension-points.md`, `docs/journal/test-coverage-gaps.md`, and
`docs/journal/2026-09-04-0-1-0-is-public.md` (the voice for the journal entry).

## 1. Overview

Build the AWS KMS keyring example and everything its spec says comes with it: request-scoped root-key caching in `WP_Secrets_Key_Manager` (a `src/` change that ships in the Trac patch), `wp secret rotate --from=<keyring>`, a `WP_Secrets_Keyring_Conformance` suite, and the shared examples test harness, including an automated conformance run for the existing AWS Secrets Manager example.

Done means the detailed spec's "Done when" section is met, except for the steps it marks as
human or live-cloud, which are left as `Manual check: NOT VERIFIED (human)`. The branch must also
carry the documentation and journal entry described in §2.

## 2. Goals and non-goals

- Goal: every deliverable in `examples/aws-kms-keyring/SPEC.md`, with tests written in the same task as the code.
- Goal: the documentation matches the code. Update every page under `docs/` whose statements
  this work changes: spec pages ("As built" and "Why"), the journal tracking pages
  (`open-questions.md`, `test-coverage-gaps.md`, `proposal-questions.md`), `examples/README.md`,
  `README.md`, and `docs/index.md` for any new page. Regenerate `docs/reference/` whenever a
  docblock changes.
- Goal: **one dev journal entry** for this piece of work, at
  `docs/journal/YYYY-MM-DD-a-kms-keyring.md`, dated the day it is written, with frontmatter
  `title`, `description`, and `date`. Write it in the voice of
  `docs/journal/2026-09-04-0-1-0-is-public.md`: first person, plain, specific. Cover what was
  built, what it found (especially anything that changed `src/` or an interface), what was
  deliberately left out, and what it means for the Trac patch. Link to the example or test and to
  ADR 0008. Do not use the `/journal-entry` skill, because it reads and clears the shared
  `_drafts/notes.md`.
- Non-goal: anything the detailed spec lists as out of scope.
- Non-goal: the Trac patch itself, a release, a tag, or publishing the docs site.

## 3. Engineering principles

- **Read first.** Before any task, read `CONTRIBUTING.md` and the "Working in this repository" section of `CLAUDE.md`. Both bind every task.
- **Keep the existing `CLAUDE.md` content.** When the plan stage writes `CLAUDE.md`, keep the current `# Working in this repository` section verbatim at the top and add the Foundry headings below it. Removing or rewording that section is a review failure.
- **`src/` is copy-ready for core.** Use core's coding standard, the `default` text domain, and `@since 7.2.0`, with no `function_exists()` guards. `tests/phpunit/test-architecture.php` enforces this. `plugin/` and `cli/` are never copied into core.
- **Errors, not exceptions.** Public API functions return `WP_Error` (or `false`) and never throw. A caller error is `_doing_it_wrong()` plus `WP_Error( WP_SECRETS_ERROR_INVALID_ARGUMENT )`.
- **No plaintext in output.** A plaintext secret or raw key material never appears in a log line, a `WP_Error` message, CLI output (except `get --reveal`), a test failure message, or a persistent cache. The reviewer checks this by reading.
- **Tests only get stronger.** Never delete or weaken an existing test. Never skip a test except for an environment gate, such as multisite-only. Every `phpcs:ignore` and every new `phpcs.xml.dist` exclusion carries a reason on the same line.
- **Generated reference.** `docs/reference/` is generated. When a docblock changes, run `make reference` and commit the result in the same task. `make reference-check` must pass.
- **Spec pages** under `docs/spec/` keep exactly three sections, in this order: **As proposed**, **As built**, **Why**. A behaviour change updates "As built", and "Why" if the code now departs from the proposal.
- **ADRs.** A new design decision gets an ADR under `docs/decisions/`, numbered `NNNN-slug.md` after the highest existing number, with number, title, date, status, context, decision, and consequences, in the existing ADRs' style. Two other flights may also add ADRs, so pick the next number and expect it to be renumbered at merge.
- **Nothing private in `docs/`.** Never mention employers, customers, or internal channels there.
- **Never publish.** Never run `sf publish`, never create or push a tag, and never touch Spacefast settings. Publishing happens after merge, by a human.
- **Commit style.** Commit messages follow `git log`: an imperative title, then a body explaining why, wrapped at 72 columns. Foundry's `<ID>: ` title prefix is fine.
- **Examples are single files.** Each example under `examples/` is a single-file drop-in with no Composer and no SDK, and stays excluded from `make ci`'s lint. It is written to be read from top to bottom.
- **Parallel flights.** Two other branches are being built from the same `main` at the same time: `build/vault-provider` (the Vault provider example, which also fixes site-scope naming in the AWS Secrets Manager example) and `build/cli-smoke` (the WP-CLI smoke test, which adds `make smoke` and a `smoke` CI job). Do not do their work. Keep edits to shared files (`Makefile`, `.github/workflows/ci.yml`, `examples/README.md`, the `docs/journal/*.md` tracking pages, `docs/index.md`) additive and confined to your own section or entry, to make the merge easy.

## 4. Architecture

- `src/wp-includes/`: the API as it will ship in core. Change it only where the detailed spec
  says to.
- `plugin/`: the plugin-only upgrade path from the prototype. Do not touch it.
- `cli/`: the WP-CLI commands, which are plugin-only.
- `tests/phpunit/` and `tests/includes/`: the PHPUnit suite and its shared base classes, mocks,
  and conformance suites.
- `examples/<name>/`: single-file platform drop-ins, plus their own `README.md` and `tests/`.
- `docs/`: the published documentation site's source (`site/` only renders it).
- `bin/`: developer and CI scripts.

## 5. Data and configuration

Every configuration constant and default is named in `examples/aws-kms-keyring/SPEC.md`. There are no tunables to invent.
If a timeout or limit is not given there, mark it `⚠️ ASSUMPTION`, give it a named constant in the
example file, and justify it in a comment.

## 6. Interfaces

`WP_Secrets_Provider`, `WP_Secrets_Keyring`, and `WP_Secrets_Store` in `src/wp-includes/`. The
provider contract is also documented in `docs/spec/extension-points.md`. The conformance suite
lives in `tests/includes/class-wp-secrets-provider-conformance.php`. Do not change an interface's
method signatures. A docblock clarification is allowed where the detailed spec calls for one.

## 7. Commands

- Full verification: `bin/ci-local.sh --keep`, which runs lint, compat, phpstan, and the
  single-site and multisite PHPUnit suites inside this worktree's own wp-env. Give it a 30-minute
  timeout. Then `make reference-check`.
- This worktree's wp-env ports are 8910 and 8911, set in the git-ignored
  `.wp-env.override.json`, which already exists. Never edit or commit it. Never run
  `wp-env destroy`.
- Run a single test file fast with
  `npx @wordpress/env run --env-cwd=wp-content/plugins/kms-keyring tests-cli vendor/bin/phpunit <file>`.
- Service containers: Moto server for KMS and Secrets Manager: run it as `docker run -d --name secrets-api-moto-kms -p 5051:5000 motoserver/moto@<digest>` (pin the digest you pull, and put the same digest in `ci.yml`). From inside wp-env containers it is reachable at `http://host.docker.internal:5051`. Remove the container when the flight's work is done.
- `docs/foundry.json` has been pre-seeded. The plan stage must keep `baseBranch: "main"`,
  `branchPrefix: "build/"`, `permissionMode: "auto"`, and the two `verify` commands with their
  timeouts exactly as they are. It may add `extraVerify` entries only for make targets that
  already exist when the entry is first exercised.

## 8. Phases

1. **Keyring contract.** `WP_Secrets_Keyring_Conformance` in `tests/includes/`, run against `WP_Secrets_Config_Key_Provider` and `Mock_Keyring`. Add the non-determinism sentence to the `WP_Secrets_Keyring::wrap()` docblock and regenerate `docs/reference/`. Manual check: none.
2. **Root-key caching** in `WP_Secrets_Key_Manager` (detailed spec, deliverable 2), with its tests. Correct `examples/README.md`'s once-per-request claim. Update the "As built" and "Why" sections of `docs/spec/providers-and-keyrings.md`.
3. **`wp secret rotate --from`** (deliverable 3), with PHPUnit tests. Remember the known gap: PHPUnit calls the method directly, so also run `wp help secret rotate` in the wp-env `cli` container and paste the output into the commit message.
4. **Examples harness** (deliverable 5): `phpunit-examples.xml.dist`, `make test-examples`, the `examples` CI job with Moto, `WP_SECRETS_AWS_ENDPOINT` on the AWS Secrets Manager example, and its conformance test class.
5. **The KMS keyring** (deliverable 1), its tests against Moto, and `examples/aws-kms-keyring/README.md`, which follows the AWS Secrets Manager README's structure and includes the adoption walkthrough.
6. **Documentation and journal.** See §2. The manual check for this phase is the live-KMS run in the detailed spec's "Done when", marked NOT VERIFIED (human).

Every phase ends by pushing the branch. Each phase's manual check is whatever the detailed spec
lists as human or live-cloud. Mark it `NOT VERIFIED (human)` and move on.

## 9. Open questions

- The shared examples harness (KMS spec §5) is built by `build/kms-keyring`. Where another
  flight also needs it, it builds a compatible subset with identical names, and the two are
  reconciled when the branches merge. Decision: accept that merge cost rather than serialise the
  flights.
- If a finding would change an interface's signature, stop and record it. Write an entry in
  `docs/journal/open-questions.md` and say so in the journal entry, rather than changing the
  signature. That is for the Trac ticket to decide.

## Appendix

Only this flight's detailed spec, `examples/aws-kms-keyring/SPEC.md`, is in scope. Everything else in `docs/` is
published documentation, to read for context and update as §2 requires.
