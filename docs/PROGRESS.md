# HashiCorp Vault KV v2 provider example build progress
Branch: build/vault-provider
Started: 2026-09-24T20:46:46.009Z

## Tasks
- [x] P1-01 Add the examples PHPUnit harness and the Vault test helper
- [x] P1-02 Add the `examples` CI job with a Vault service container
- [x] P1-03 Push phase 1 and record the manual checks
- [x] P2-01 Add the Vault KV v2 provider skeleton with path mapping, HTTP client, `get()`, and `delete()`
- [x] P2-02 Implement `set()`, `retire_previous()`, and a minimal `list_secrets()`; run the conformance suite against Vault
- [x] P2-03 Push phase 2 and record the manual checks
- [x] P3-01 Prove strict N-1 and destroy-on-retire against the live server
- [x] P3-02 Push phase 3 and record the manual checks
- [x] P4-01 Store `needs_rotation` in `custom_metadata` and fill in listing metadata
- [x] P4-02 Multisite isolation, sealed-or-unreachable behaviour, and the timeout measurement
- [x] P4-03 Push phase 4 and record the manual checks
- [x] P5-01 Map AWS site scope to `wp/site/<blog_id>/<name>` and test it by capturing the request
- [x] P5-02 Push phase 5 and record the manual checks
- [x] P6-01 Write the Vault example README and update the example index, root README, and CI reference
- [ ] P6-02 Add ADR 0009 and update the spec pages' "As built" sections
- [ ] P6-03 Update the journal tracking pages, write the journal entry, and index both
- [ ] P6-04 Push phase 6, remove the Vault container, and record the manual checks

## Log
(one entry per task, appended by implement)

### P1-01 — 0e770f5
Vault dev container "secrets-api-vault" started, pinned digest
hashicorp/vault@sha256:47f14a6acb98f48d798a07df7c83f23a6e636e1cf724c5f8ff165cb32667a1e2
(version 2.1.1 per sys/health). Digest recorded in Makefile comment;
P1-02 and P6-01 must copy it verbatim.
Vault_Test_Server: addr/token from VAULT_ADDR/VAULT_TOKEN env (defaults
127.0.0.1:8200 / dev-root), mount 'secret'. request() returns
{code, body} where body is the full decoded JSON (callers pick 'data'
out themselves). provider() deliberately not added yet (P2-01).
Examples suite (both passes) green: 3/3 tests each.
wp-env for this worktree started on ports 8920/8921 (via existing
.wp-env.override.json, untouched).
bin/ci-local.sh --keep and make reference-check both pass.

### P1-02 — 314a8a6
Job `examples`: needs: static, mysql + vault service containers, PHP
8.3, make install then make test-examples with VAULT_ADDR=
http://127.0.0.1:8200 and VAULT_TOKEN=dev-root. Vault service image
pinned to the same digest as the Makefile comment (P1-01):
sha256:47f14a6acb98f48d798a07df7c83f23a6e636e1cf724c5f8ff165cb32667a1e2.
Comment above the job avoids repeating the digest as a literal string
so grep -c 'hashicorp/vault@sha256:' stays 1 per file (Verification
requirement); it instead points at the image: line.
YAML validated with js-yaml (python3 had no PyYAML available).
No existing job touched.

### P1-03 — 6e34962
Push: git push -u origin build/vault-provider succeeded (new branch,
tracking origin/build/vault-provider).
Manual check: NOT VERIFIED (human)
1. The `examples` job is green on GitHub Actions for this branch's
   draft PR, including the Vault service health check.
2. The pinned digest
   sha256:47f14a6acb98f48d798a07df7c83f23a6e636e1cf724c5f8ff165cb32667a1e2
   resolves on Docker Hub to a current 1.x release.

### P2-01 — 9224e40
Vault_KV2_Provider added (445 lines). request() returns decoded 'data'
array on 2xx (empty array for 204), null on 404, WP_Error otherwise
(403/503 both map to WP_SECRETS_ERROR_STORE_UNAVAILABLE). get()
handles CURRENT and PREVIOUS via previous_version() (strict N-1, null
when missing/soft-deleted/destroyed). delete() fires wp_secret_changed
with 'deleted'. set()/retire_previous()/list_secrets() return
WP_Error('Not implemented.') -- deliberate per task text, closed in
P2-02; called out here so the reviewer doesn't read it as a defect.
Vault_Test_Server::provider() added.
19 offline tests green, both wp-env passes. bin/ci-local.sh --keep
and make reference-check pass.

### P2-02 — edb78d0
set() reads metadata first, POSTs max_versions only when created, then
POSTs the value, then fires wp_secret_changed. retire_previous()
destroys strictly N-1 (no-op true when nothing to retire), fires
'retired' only when a version was destroyed. list_secrets() via new
private list_keys($url) helper: 1 LIST for namespaces (or the literal
prefix when given), 1 LIST per namespace; fingerprint/created/
has_previous/needs_rotation are placeholders, filled by P4-01.
Conformance suite: 14 tests, 13 pass, 1 skipped by the base class
itself (not overridden/skipped by this class). Tests_Vault_Provider:
8 tests, all green, both wp-env passes (41 tests total each).
bin/ci-local.sh --keep and make reference-check pass.

### P2-03 — c6931ac
Push: git push origin build/vault-provider succeeded.
Manual check: NOT VERIFIED (human)
1. The `examples` job is green on GitHub Actions.
2. The drop-in installed on a real wp-env site with the four
   constants set makes `wp secret dropin` report
   `Provider: Vault_KV2_Provider` and
   `Protected by: HashiCorp Vault (...)`, and `wp secret set`/
   `get --reveal` round-trip through the dev server.

### P3-01 — df7e762
7 tests added to Tests_Vault_Provider, all pass against the live
server: retiring never resurrects an older version;
max_versions: 2 prunes to exactly 2 versions; N-1 is strict even with
create_metadata(path, 10) removing pruning as a cause (the test that
answers question 1); soft-deleted N-1 and CURRENT both read as null,
not WP_Error; retire clears the memo; retire is idempotent (fires
'retired' once).
No defect found; no change to secrets.php.
Noted environment flakiness: host.docker.internal:8201 connectivity
from the wp-env tests-cli container occasionally times out under
heavy concurrent docker load from sibling flights (kms-keyring,
cli-smoke running in parallel worktrees) -- not a code defect,
confirmed by an immediate clean rerun passing. Both wp-env passes
green (48 tests, 1 skipped by conformance base class).
bin/ci-local.sh --keep and make reference-check pass.

### P3-02 — e68982c
Push: git push origin build/vault-provider succeeded.
Manual check: NOT VERIFIED (human)
1. The `examples` job is green.
2. On a real site with the drop-in installed, `wp secret set`,
   `wp secret set` again, `wp secret retire --yes`, then
   `wp secret get --slot=previous` reports absence, and
   `vault kv metadata get` shows the retired version destroyed.

### P4-01 — b58fe16
flag_is_set()/write_flag() added; the literal '1'/'0' string appears
only inside those two methods (grep-verified). set() compares wanted
vs. had (from the metadata read at the top of set()) and writes the
flag only on change, after the wp_secret_changed action fires; a
failed write while requested returns WP_Error (value already landed);
a failed clear is error_log()'d (path + Vault's message, never the
value) and ignored. list_secrets() now does one GET metadata per
listed key; a 404 between LIST and GET is skipped, not an error.
9 tests added to Tests_Vault_Provider, all green, both wp-env passes
(56 tests, 1 skipped by conformance base class).
bin/ci-local.sh --keep and make reference-check pass.

### P4-02 — 8e8e2ae
Tests_Vault_Provider_Multisite (3 tests, multisite-gated: site scope
isolated per blog, network scope shared, deleting on one blog leaves
the other). 3 tests added to Tests_Vault_Provider: sealed vault is
WP_Error from get/set/delete/retire_previous/list_secrets; unreachable
Vault (127.0.0.1:1) is WP_Error not absence; a bad token's set() is
WP_Error with "permission denied" and its get() is WP_Error (never
null).
Measurement (REQUEST_TIMEOUT kept at 5): connection refused 0.0051s;
non-routable address 4.035s (bounded near/under 5s, timeout honoured);
examples suite single-site pass ~1.5s for 62 tests. No change to
secrets.php.
Both wp-env passes green (62 tests each; single-site skips the 3
multisite tests + 1 conformance skip = 4; multisite skips only the 1
conformance skip).
bin/ci-local.sh --keep and make reference-check pass.
Note: activated the vault-provider plugin in wp-env (was inactive)
to run the wp eval timeout measurements; left active.

### P4-03 — 78c104f
Pushed build/vault-provider to origin (dfdeb21..f14a32f, then 78c104f marker commit).
No code/docs changes beyond the push; phase 4 (needs_rotation metadata, multisite
isolation, sealed/unreachable vault handling, timeout measurement) is complete and
green locally.
Manual check: NOT VERIFIED (human)
(1) confirm the `examples` job is green on single site and multisite in CI
(2) against a real sealed Vault (`vault operator seal` on a non-dev server), confirm
    `wp secret get` reports an error rather than absence
(3) on a real site, confirm `wp secret health` shows the flagged secret after
    `wp secret import-option`
Push: done (origin/build/vault-provider updated)

### P5-01 — f8ed035
Added private scope_prefix( $network ) on AWS_Secrets_Manager_Provider:
'wp-network/' or 'wp/site/' . get_current_blog_id() . '/'; both aws_name()
and wp_name() delegate to it so mapping stays symmetric. Read at call time
(not cached) so mid-request switch_to_blog() is honoured, matching Vault.
Tests: examples/aws-secrets-manager/tests/test-aws-secrets-manager-naming.php
(new), offline via pre_http_request, same fake_response shape as the Vault
paths test: site-scope includes blog id, network-scope unchanged, set() uses
the same site-scoped name, listing maps site-scoped names back and ignores
flat/foreign names, multisite-gated blog-id-at-call-time test (skipped off
multisite). phpunit-examples.xml.dist gained the new tests dir.
README: rewrote Naming section; added "Upgrading from an earlier copy of
this example" (AWS-side rename wp/<name> -> wp/site/1/<name>, no
compat read before 1.0, one-sentence why).
Left the 'site' fingerprint scope for network secrets alone (P6-03's
concern, not this task) and did not touch anything else in the file.
Both examples-suite passes green (67 tests each vs 62 before: +5 new,
1 skipped off multisite). bin/ci-local.sh --keep and make
reference-check pass. phpcs clean.

### P5-02 — 7e24067
Pushed build/vault-provider to origin (f14a32f..bd5782d, then 7e24067 marker
commit). Confirmed git log shows P5-01 (f8ed035) as a single commit touching
only examples/aws-secrets-manager/secrets.php, its README.md, its new tests
file, and phpunit-examples.xml.dist.
Manual check: NOT VERIFIED (human)
(1) against live AWS, confirm a secret set on blog 1 appears in the console
    as `wp/site/1/<name>`
(2) confirm the rename walkthrough in the README works on a throwaway account
Push: done (origin/build/vault-provider updated)

### P6-01 — ef92129
examples/vault-provider/README.md (new): title/summary, credentials
(.wp-env.override.json with the four WP_SECRETS_VAULT_* constants, noting
host.docker.internal:8201 for wp-env vs 127.0.0.1:8200), install/remove loop
matching the AWS README's register and PHPUnit gotcha, Vault policy (the
three secret/data|metadata|destroy path patterns), the Naming table from the
detailed spec, the four questions each as its own subsection (previous is
strictly N-1 and the test that proves it; max_versions:2 and secrets created
outside the provider; custom_metadata.needs_rotation "1"/"0" and the failure
rule; list_secrets cost), Known limits, OpenBao, and Run the tests (pinned
digest docker run line + two wp-env commands + make test-examples).
Interpretation: linked ADR 0009 from question 2 per the plan even though
that ADR is created later in P6-02 of this same phase; the link resolves
once that task lands, and creating the ADR is explicitly out of scope here.
examples/README.md: added the Vault row to the interface table and a new
"In this directory" section right after "Which interface do you need?";
left the KMS advice and Dependencies section untouched.
README.md: one sentence in Platform bindings naming both examples; extended
the Contributing CI sentence with the examples job.
docs/reference/ci.md: added the examples row to Matrix and one sentence
under "Where this runs".
Verified: every ../ relative link resolves except the by-design ADR 0009
one; the pinned digest matches Makefile and ci.yml verbatim; bin/ci-local.sh
--keep and make reference-check both pass.
