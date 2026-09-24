# Review: HashiCorp Vault KV v2 provider example
Round: 1

**Branch:** `build/vault-provider` (base `1209b50`, head `3be654e` at review start)
**Verdict:** CHANGES REQUESTED

## How this was reviewed

- Read HANDOFF.md, PROGRESS.md, CLAUDE.md, docs/SPEC.md, `examples/vault-provider/SPEC.md`, and
  PLAN.md in full, then every task commit with `git show`.
- `foundry_verify`: all 12 constraints pass (no fixture failures, no hits). `bin/ci-local.sh --keep`
  and `make reference-check` are green.
- Checked the constraints CLAUDE.md leaves to a reader: `git diff main..HEAD --stat -- src plugin cli`
  is empty. `.wp-env.override.json` is not tracked. Every `docs/spec/*.md` still has exactly As
  proposed / As built / Why. `tests/` gained only `tests/bootstrap-examples.php`, and nothing was
  deleted. The tracking pages, `examples/README.md`, and `docs/index.md` changed by addition only
  (`--word-diff`). The Vault digest is identical in `Makefile`, `ci.yml`, and the README. `$value`
  does not reach any `WP_Error`, `error_log()`, or listing in either example.
- Started a Vault dev container from the pinned digest (it reports version 2.1.1) and ran the
  examples suite myself: single site 67 tests / 5 skipped, multisite 67 / 1 skipped, both green.
  I removed the container afterwards.
- Mutation sampling. `foundry_mutate` cannot see the examples suite, because no configured verify
  command runs it (feedback logged). A mutation to `previous_version()` "survived" for that reason
  alone. So I applied each mutation to a *copy* of the drop-in inside the tests-cli container
  (`/tmp`, not the mounted tree) and ran the real examples suite against it. All 14 mutations were
  killed by the test that names the mechanic: the deleted/destroyed N-1 check, `max_versions` on
  create, 403/503 read as absent, clear writing "1", a requested flag failure returning true,
  retire soft-deleting instead of destroying, `has_previous`, `flag_is_set()`, Vault site scope
  without the blog id (multisite), AWS flat `wp/` naming, AWS blog id fixed at 1 (multisite), the
  retire memo, the namespace header, and the list prefix.
- Probed Vault 2.1.1 directly with curl to check the `custom_metadata` claims in the code and
  README (see finding 1).

## Findings

### 1. Category 5 (behaviour the spec does not authorise; wrong docblock and README answer): writing the rotation flag erases every other `custom_metadata` key
`examples/vault-provider/secrets.php:613-621` (`write_flag()`), docblock at `:601-607`;
`examples/vault-provider/README.md:128-130`.

`write_flag()` POSTs `{"custom_metadata":{"needs_rotation":"0|1"}}`. A metadata POST replaces
`custom_metadata` wholesale. Against the pinned Vault 2.1.1, seeding `{"owner":"ops","needs_rotation":"1"}`
and then posting `{"needs_rotation":"0"}` leaves only `{"needs_rotation":"0"}`. The docblock claims the
"0" write exists to avoid "destroying every other custom_metadata key a different tool may have
set". It does the opposite: every set or clear destroys those keys. The stated premise is also
false. Vault accepts an empty map (`{}` and even `[]` both return 204), so it is not true that
Vault "rejects an empty map", as the docblock and README question 3 say.
**What breaks:** any operator tag, owner, or ticket reference in a secret's `custom_metadata` is
silently wiped the first time WordPress sets or clears the flag. The published answer to question 3
is wrong. `max_versions` is unaffected (verified).
**Minimal fix:** `set()` already holds `$meta` from its first read, so merge
`$meta['custom_metadata']` (when it is an array) with the flag key before posting. Correct the
docblock and README to say that the merge preserves other keys, and why "0" is still written
rather than removing the key. Mention the read-then-write race in a comment. It is the same
non-transaction the file header already names.
**Task:** P4-01 (fix R1-01).

### 2. Category 3 (tests): the Vault test helper turns "unreachable" into "absent", so the suite cascades and negative assertions can pass vacuously
`examples/vault-provider/tests/includes/class-vault-test-server.php:76-78, 101-109, 156-164, 183-193`.

`request()` maps a transport failure to `code 0, body null`. `metadata()` then returns `null`, which
is the same as a 404. `list_keys()` returns `array()`. `wipe()` ignores the result of every LIST
and DELETE. This is the three-state collapse CLAUDE.md forbids, moved into the test harness. I ran
the unmutated suite eight times against the local container, and it failed in about half of the
runs. The root cause is environmental: 1 in 300 TCP connects to `host.docker.internal:8201`
timed out. But the symptoms land far from that cause. A `wipe()` that silently failed left state
behind, so a later test saw `updated` instead of `created`, `custom_metadata` was null instead of
`'0'`, and `list_secrets()` was a `WP_Error` at an array index. The same collapse lets assertions
such as `assertNull( $this->server->metadata( "wp/site/{$blog}/acme/key" ) )` in
`Tests_Vault_Provider_Multisite::test_network_scope_is_shared_across_blogs` and
`Tests_Vault_Harness::test_wipe_removes_everything_under_wp` pass when Vault was never reached.
**What breaks:** CI or local failures that point at the wrong test, and negative assertions that
cannot tell "absent" from "never asked".
**Minimal fix:** the helper fails the running test loudly (`PHPUnit\Framework\Assert::fail()` with
the URL and transport error) on a transport failure. `metadata()` and `list_keys()` treat only 404
as absent and fail on any other non-2xx. `wipe()` fails if a LIST or DELETE does not succeed.
Add an optional `$addr` constructor argument (defaulting to the env var) so a harness test can
aim the helper at a closed port.
**Task:** P1-01 (fix R1-02).

### 3. Category 3 (tests): the unreachable-Vault test does not assert the error code the plan specifies
`examples/vault-provider/tests/test-vault-provider.php:497-503`.

P4-02 asks that `get( CURRENT )` on `http://127.0.0.1:1` be a `WP_Error` *with code
`WP_SECRETS_ERROR_STORE_UNAVAILABLE`*. The test asserts only `assertWPError()`, for `get()`,
`list_secrets()`, and `delete()`. If the transport branch of `request()` returned any other code,
it would still pass, and the detailed spec's "Errors" rule requires "reads as unreachable".
**Minimal fix:** assert the code on all three results. This strengthens the test and weakens
nothing.
**Task:** P4-02 (folded into fix R1-01, which already touches this file).

### 4. Category 5 (docs that do not match the code or the repository), grouped
- `examples/vault-provider/README.md:178-179` and `Makefile:62-63`: the test commands hard-code
  `--env-cwd=wp-content/plugins/vault-provider`, which is this worktree's directory name. On `main`
  the checkout is `secrets-management`, so the documented commands fail for anyone outside this
  worktree once the branch merges. `bin/ci-local.sh` already derives the name with `basename "$PWD"`.
  Use `--env-cwd="wp-content/plugins/$(basename "$PWD")"`.
- `examples/vault-provider/README.md:112-114`: question 1 says the proving test "destroy[s] the
  middle one directly against Vault". The test (`test-vault-provider.php:188-201`) sets
  `max_versions: 10` through the helper, writes three versions, retires through the *provider*,
  and then checks that version 1 still reads 200. The pruning-cannot-be-the-cause detail is the
  whole point of that test, and the README leaves it out.
- `examples/vault-provider/README.md:167` and `docs/journal/test-coverage-gaps.md:141`: both say the
  OpenBao run is "recorded ... in the phase-6 progress entry". `docs/PROGRESS.md` is a Foundry
  file that is stripped before merge, so this is a dangling reference in published docs. The
  detailed spec says the run is recorded in a commit message.
- `docs/journal/test-coverage-gaps.md:139-140`: "the test points the provider at a non-routable
  address". It uses `http://127.0.0.1:1`, a closed local port, so the connection is refused
  rather than timing out.
- `README.md:147-149`: "`make test-examples` runs both against live services". The AWS tests are
  offline through `pre_http_request`, and only Vault runs against a live server.
- Foundry task IDs are left in shipped files: `examples/vault-provider/secrets.php:48, 151, 269,
  309, 534` ("Measured in P4-02", "Completed in P2-02", "Isolated so P4-01's ..."),
  `Makefile:59` ("from P1-01"), `.github/workflows/ci.yml:193` ("(P1-01)"). They mean nothing
  once the flight's files are stripped. `secrets.php:18` also points at `../README.md` for the
  four questions. The README is beside the file, and `../README.md` is `examples/README.md`.

**Tasks:** P6-01, P6-03, P1-01, P1-02, P2-02, P4-01. The `secrets.php` items belong to fix R1-01
and the rest to fix R1-03, which adds a constraint so task IDs cannot come back.

## Interpretation choices (HANDOFF.md)

- **P5-01 `scope_prefix()` read at call time:** the reading most consistent with the detailed spec
  (Deliverable 3, "as Vault does"). Mutation-verified on multisite.
- **P6-01 ADR 0009 link dangling for one commit:** harmless, and it resolves at HEAD.

The PLAN decision that "clear writes '0' because Vault rejects `[]`" rests on a false premise (see
finding 1). Writing "0" is still a fine encoding. The problem is the wholesale replace, not the
"0".

## Blocked and skipped tasks

None.

## Spec issues

- None in `docs/SPEC.md` or the detailed spec that affect this verdict. Two PLAN-level inaccuracies,
  recorded here rather than as findings. First, P1-03's manual check asks that the pinned digest
  resolve "to a current 1.x release", but the pinned image is Vault 2.1.1. Second, PLAN
  Conventions state that Vault rejects an empty `custom_metadata` map, which Vault 2.1.1 does not.

## Manual checks still owed

From HANDOFF.md:

**Phase 4**
1. The `examples` job is green on GitHub Actions, single site and multisite.
2. Against a real sealed Vault (`vault operator seal` on a non-dev server), `wp secret get` reports
   an error rather than absence.
3. `wp secret health` on a real site shows the flagged secret after `wp secret import-option`.

**Phase 5**
1. Against live AWS, a secret set on blog 1 appears in the console as `wp/site/1/<name>`.
2. The README's "Upgrading from an earlier copy of this example" rename walkthrough works on a
   throwaway AWS account.

**Phase 6**
1. An OpenBao run: start `openbao/openbao` in dev mode on another port, point `VAULT_ADDR` at it,
   run the examples suite, and record the result.
2. The `examples` CI job is green on GitHub Actions (hosted runner, never verified).
3. `npm run docs:build` in `site/` renders the README-linked pages, ADR 0009, and the journal entry,
   with the journal sidebar sorted by `date`.
4. A reviewer reads `examples/vault-provider/README.md`'s "The four questions" against the detailed
   spec. Questions 1 and 3 need corrections first (findings 1 and 4).

Earlier phases, from PROGRESS.md: the drop-in on a real wp-env site reports
`Provider: Vault_KV2_Provider`, and `set`/`get --reveal` round-trip (P2-03). The
`set`/`set`/`retire`/`get --slot=previous` sequence reports absence, and `vault kv metadata get`
shows the destroyed version (P3-02).

## Notes

- `.gitignore` gained `.foundry/implement.lock` and lost its trailing blank line in Foundry's own
  `chore: start implementation run` commit, not in a task. Strip it with the other Foundry files
  before merge.
- The local examples suite is flaky against `host.docker.internal:8201` on this machine (about 1
  in 300 connects time out). That is an environment property, not a code defect. Finding 2 makes
  it fail where it happens rather than three tests later.
- `examples/vault-provider/secrets.php`'s install block leaves `$mount` and `$namespace` in
  whatever scope the drop-in is included from. The AWS example passes its constants straight to
  the constructor and creates no locals. This is harmless, and inlining the two ternaries would
  match the AWS example.
- `docs/journal/test-coverage-gaps.md:130` has a doubled blank line before the new `---`.
