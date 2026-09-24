# Build summary: HashiCorp Vault KV v2 provider example

**Merge line:** `build/vault-provider`, `1209b50` (main) → `e91a8bd`: 52 commits before this summary, 2 review rounds, verdict **APPROVED**. 20 of 20 tasks are done, with none blocked or skipped.

Before merge, remove the Foundry files: `docs/SPEC.md`, `docs/PLAN.md`, `docs/PROGRESS.md`, `docs/HANDOFF.md`, `docs/REVIEW.md`, `docs/SUMMARY.md`, `docs/foundry.json`, `.foundry/`, the Foundry section of `CLAUDE.md`, and the `.gitignore` change made by `chore: start implementation run`. `docs/foundry.json` holds the new `no-foundry-task-ids-in-shipped-files` constraint. Carry it into the main repo's config if you want to keep it.

## What was built

**Phase 1: examples harness, Vault half (P1-01 to P1-03).** Adds a PHPUnit harness for the examples: `phpunit-examples.xml.dist`, `tests/bootstrap-examples.php`, `make test-examples`, and a CI job named `examples` that runs a Vault dev server as a service container. The Vault image is pinned by digest (`sha256:47f14a6…`, Vault 2.1.1), and that digest is the same in the Makefile, `ci.yml`, and the README. The flight also adds a `Vault_Test_Server` helper that tests use to inspect Vault directly and to wipe everything under `wp/` between tests. This harness is the part the `build/kms-keyring` flight is expected to overlap with. The overlap gets reconciled at merge.

**Phase 2: Vault provider core (P2-01 to P2-03).** Adds `examples/vault-provider/secrets.php`, a single-file drop-in with no Composer dependencies and no SDK. It defines `Vault_KV2_Provider` and talks to the KV v2 HTTP API through `WP_Http`. It maps paths to `wp/site/<blog_id>/<name>` and `wp/network/<name>`. It covers every interface method in its simplest correct form, and the shared conformance suite passes against the live Vault.

**Phase 3: versions and retirement (P3-01 to P3-02).** Tests against the live server prove two rules. First, "previous" means strictly version N-1, even when older versions still exist. Second, `retire_previous()` destroys that version outright. A soft delete is not enough.

**Phase 4: metadata and listing (P4-01 to P4-03).** `needs_rotation` is stored in the secret's Vault `custom_metadata`, and `list_secrets()` now returns real metadata. Tests cover three more behaviours:
- Site scope is separate per blog, and network scope is shared across blogs.
- A sealed or unreachable Vault returns a `WP_Error` with code `WP_SECRETS_ERROR_STORE_UNAVAILABLE` from every method. It never reads as "absent".
- The request timeout was measured.

**Phase 5: AWS site-scope fix (P5-01 to P5-02).** The AWS Secrets Manager example now puts site-scoped secrets under `wp/site/<blog_id>/<name>`. It reads the blog id when each call runs, so the name stays correct after `switch_to_blog()`. The fix is tested offline by capturing the outgoing request through `pre_http_request`. The AWS README gains an upgrade walkthrough for renaming existing secrets.

**Phase 6: documentation and journal (P6-01 to P6-04).** This phase adds:
- The Vault example README, which answers the detailed spec's "four questions".
- ADR 0009, `docs/decisions/0009-cap-a-many-version-backend-to-two-slots.md`. Expect to renumber it at merge.
- "As built" sections for the affected spec pages.
- Updates to the journal tracking pages, plus a new journal entry, `docs/journal/2026-09-24-a-vault-provider.md`.
- Index updates.

Nothing under `src/`, `plugin/`, or `cli/` changed.

**Round 1 fixes (R1-01 to R1-03).**
- R1-01: writing the rotation flag now merges with the existing `custom_metadata` instead of replacing it. Before this fix, every set or clear erased every other key in that map.
- R1-02: the test helper now fails loudly when Vault is unreachable, instead of reporting the secret as absent.
- R1-03: doc claims that had drifted from the code were corrected. A new constraint stops Foundry task IDs from appearing in shipped files.

## Decisions that shaped it

- **Harness scope (plan).** Only the parts of the harness this flight needs are built: phpunit config, bootstrap, `make test-examples`, the `examples` CI job, and `examples/vault-provider/tests/`. There are no Moto or KMS tests. The KMS flight's copy is reconciled at merge.
- **No interface changes (plan).** No file changed under `src/`, `plugin/`, or `cli/`, not even a docblock. Two findings concern the interface: "previous is strictly N-1", and "a `BOUNDARY_PROVIDER` provider may still need local key material". Both are recorded in `docs/journal/open-questions.md` for the Trac ticket instead of being changed in code.
- **Phases map one to one to SPEC §8 (plan).**
- **Phase 2 lands every method in its simplest form (plan, P2-02).** This keeps the conformance suite passing before phases 3 and 4 finish the semantics. Nothing is stubbed and no test is skipped. Between P2-02 and P4-01, the provider accepted `needs_rotation` without writing it.
- **`REQUEST_TIMEOUT = 5` seconds (plan, P2-01, measured in P4-02).** It is a `⚠️ ASSUMPTION` constant and appears nowhere else as a literal.
- **`MAX_VERSIONS = 2` (plan, P2-01; ADR 0009).** The provider limits Vault to two versions per secret instead of widening the WordPress version model.
- **The flag is cleared by writing `"0"` (plan, P4-01).** The flag counts as set only when the value is exactly `"1"`. The plan's stated reason, that Vault rejects an empty map, turned out to be false; see Spec issues. The choice stands as an encoding. After R1-01, the write also merges with the existing keys.
- **Listing uses `GET …?list=true` (plan, P2-02).** Vault documents it as equivalent to the `LIST` verb. The provider avoids depending on `WP_Http` passing a custom verb through every transport.
- **`$name_prefix` in `list_secrets()` is a namespace (plan, P2-02/P4-01).** This matches the libsodium provider.
- **`wp_secret_changed` fingerprints are `''` (plan, P2-02).** The AWS example does the same. It avoids an extra read, and it is a known limit.
- **In `set()`, the action fires before the flag write (plan, P2-02/P4-01).** If the flag was requested and its write fails, `set()` returns a `WP_Error`, but the audit hook has already seen the change.
- **Fingerprint scope is `'network'`/`'site'` (plan).** This matches the shipped provider. The AWS example's use of `'site'` for network secrets is an existing inconsistency that this flight left alone.
- **The Vault image is pinned by digest (plan, P1-01).** It was pulled from `latest` once. The server reports version 2.1.1.
- **Test server configuration (plan, P1-01).** It comes from `VAULT_ADDR` and `VAULT_TOKEN`. If Vault is unreachable, the tests fail rather than skip.
- **Test isolation (plan, P1-01).** Every Vault test class wipes `wp/` in `set_up()`.
- **Multisite tests (plan, P4-02).** They live in one file that is skipped when the site isn't multisite, and `make test-examples` runs the suite twice.
- **No `extraVerify` (plan).** `make test-examples` can't run on the host, so each task ran the examples suite inside wp-env instead.
- **The AWS provider reads the blog id when each call runs (interpretation, P5-01).** The private `scope_prefix()` never caches it, so the name stays correct after `switch_to_blog()`.
- **The link to ADR 0009 was broken for one commit (interpretation, P6-01).** The plan put the link in the README before P6-02 created the ADR. It works at HEAD.
- **`write_flag()` reuses the `$meta` that `set()` already read (interpretation, R1-01).** It does not fetch the metadata again, because the data write in between does not touch `custom_metadata`. The sequence of reading and then writing is not atomic, and the docblock says so.

## Assumptions still in play

| Key | Final default | Status |
|---|---|---|
| `Vault_KV2_Provider::REQUEST_TIMEOUT` | `5` seconds | Measured in P4-02 but not tuned: a refused connection returns in about 0.005s, and a non-routable address takes about 4s, under the limit. The value itself is still a judgment call and has not been tested against a remote Vault over TLS. |
| `Vault_KV2_Provider::MAX_VERSIONS` | `2` | Not an assumption: the detailed spec sets it and ADR 0009 records it. It is listed here because a constraint keeps it defined in one place. |

## Spec issues

Suggested edits to `docs/SPEC.md` or the detailed spec (`examples/vault-provider/SPEC.md`):

- **SPEC §8, phase 2:** "conformance suite green" conflicts with the phase plan, because `retire_previous()` is phase 3 and `list_secrets()` is phase 4. State that phase 2 lands minimal forms of every method.
- **SPEC §7, `extraVerify`:** it is allowed only for existing make targets, but `make test-examples` can't run on the host. Allow a wp-env command, or add a make target that runs the suite inside wp-env. Without one, `foundry_mutate` can't see the examples suite; see Pipeline friction.
- **Detailed spec, "a set without the flag clears it":** say how the flag is cleared: write `"0"`, merge with the existing keys, and treat only `"1"` as set. Do not repeat "Vault rejects an empty map". Vault 2.1.1 accepts both `{}` and `[]`. Round 1 caught this false premise in PLAN.md.
- **Detailed spec, `LIST metadata/...`:** note that `GET …?list=true` is the accepted equivalent.
- **Detailed spec, Vault version:** name the version, or say "pin the digest". The pinned image is 2.1.1. PLAN P1-03's text asked for "a current 1.x release", which was wrong (round 1).
- **Detailed spec, fingerprints:** say what `wp_secret_changed` carries for this provider. It currently carries blank strings.
- **AWS example fingerprint scope:** it uses `'site'` for network secrets, while the shipped provider uses `'network'`. Decide whether a future spec should fix this.
- **`examples/README.md` "Dependencies":** it says each example has its own `composer.json`, but neither does. The text was already out of date, and it was left alone because the KMS flight may edit that page.
- **CI triggers:** the workflow runs only on pushes to `main` and on `pull_request`. Pushing the branch alone does not run CI, so the draft PR is what runs it.
- **`WP_Secrets_Provider::set()` contract:** the provider accepted `needs_rotation` without writing it, but only between P2-02 and P4-01 on this branch. No action needed. It is listed only because it is history.
- Round 2 found no new spec issues.

## Manual checks owed

**Phases 2 and 3**
1. On a real wp-env site, the drop-in reports `Provider: Vault_KV2_Provider`, and `wp secret set` followed by `wp secret get --reveal` returns the value.
2. After `set`, `set`, `retire`, `get --slot=previous` reports the secret as absent, and `vault kv metadata get` shows the old version as destroyed.

**Phase 4**
1. The `examples` job passes on GitHub Actions, on both single site and multisite.
2. Against a real sealed Vault (`vault operator seal` on a server not in dev mode), `wp secret get` reports an error, not absence.
3. After `wp secret import-option`, `wp secret health` on a real site shows the secret as flagged.

**Phase 5**
1. Against live AWS, a secret set on blog 1 appears in the console as `wp/site/1/<name>`.
2. The README's "Upgrading from an earlier copy of this example" rename steps work on a throwaway AWS account.

**Phase 6**
1. OpenBao check: start `openbao/openbao` in dev mode on another port, point `VAULT_ADDR` at it, run the examples suite, and record the result in a commit message. CI tests only Vault.
2. The `examples` CI job passes on a hosted runner. It has only run locally so far.
3. `npm run docs:build` in `site/` renders the README-linked pages, ADR 0009, and the new journal entry, and the journal sidebar sorts by `date`.
4. Read "The four questions" in `examples/vault-provider/README.md` against the detailed spec. Tighten the reasoning in question 3 as you go; see the round 2 notes below.

**Round 1**
1. The `examples` CI job passes on a hosted runner with the round 1 changes.
2. The next time a task edits a file covered by `no-foundry-task-ids-in-shipped-files`, test the rule against a real diff that contains a task ID. The regex `[PR][0-9]+-[0-9]{2}\b` is deliberately narrow.

## Review history

- **Round 1: CHANGES REQUESTED.** 4 findings, 3 fix tasks queued (R1-01 to R1-03), none unblocked, converging.
  1. The flag write erased other `custom_metadata` keys (P4-01).
  2. The test helper reported "unreachable" as "absent" (P1-01).
  3. The unreachable-Vault test did not check the error code (P4-02).
  4. A group of doc and code mismatches, plus task IDs leaking into shipped files (P6-01, P6-03, P1-01, P1-02, P2-02, P4-01).
- **Round 2: APPROVED.** 0 findings and 0 fix tasks. No finding came back from round 1. The approval came with notes that were not queued as work:
  - In README question 3, the claim that the flag is "never omitted, because Vault replaces `custom_metadata` wholesale" no longer follows now that the write merges. The real reason is the chosen encoding. The `write_flag()` docblock explanation is also thin. This is a wording issue; every factual claim is correct.
  - `Vault_Test_Server::wipe_recursive()` has a non-204 DELETE check with no dedicated test: a mutation that removed it survived. The `Assert::fail()` in `request()` is only covered indirectly, by status checks that catch the same condition.
  - The local examples suite still fails intermittently: 2 of 4 runs failed with `cURL error 28` to `host.docker.internal:8201`. This comes from Docker Desktop port forwarding, not the code, and failures now name the URL at the point they happen. CI should not be affected.
  - Round 1 notes that still apply:
    - The Foundry `.gitignore` change and the `CLAUDE.md`/`docs/SPEC.md` entries that hard-code `--env-cwd=wp-content/plugins/vault-provider` are all Foundry files. Strip them before merge.
    - The install block in `secrets.php` leaves `$mount` and `$namespace` as local variables. This is harmless.

## Pipeline friction

- **implement, environment-flakiness:** Other Foundry flights (kms-keyring, cli-smoke) ran Docker containers on the same host at the same time. That caused intermittent connection timeouts from wp-env's tests-cli container to `host.docker.internal:8201`, so PHPUnit failed for reasons unrelated to the code. Confirming the cause was environmental took one extra debug-and-rerun cycle.
- **review, mutate-blind-spot:** `foundry_mutate` on `examples/vault-provider/secrets.php` can run only the configured verify commands, and none of them runs the examples suite. So every mutation of the example "survives" after a ~4 minute ci-local run, whatever the tests cover. The reviewer had to apply mutations by hand to a copy inside the container to get a real killed/survived result.
- **summarize, tool-refusal:** The harness refused the `Write` tool for `docs/SUMMARY.md` ("subagents should return findings as text"). The file was written with a shell heredoc instead, as the skill directs.
