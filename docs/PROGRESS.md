# HashiCorp Vault KV v2 provider example build progress
Branch: build/vault-provider
Started: 2026-09-24T20:46:46.009Z

## Tasks
- [x] P1-01 Add the examples PHPUnit harness and the Vault test helper
- [x] P1-02 Add the `examples` CI job with a Vault service container
- [x] P1-03 Push phase 1 and record the manual checks
- [ ] P2-01 Add the Vault KV v2 provider skeleton with path mapping, HTTP client, `get()`, and `delete()`
- [ ] P2-02 Implement `set()`, `retire_previous()`, and a minimal `list_secrets()`; run the conformance suite against Vault
- [ ] P2-03 Push phase 2 and record the manual checks
- [ ] P3-01 Prove strict N-1 and destroy-on-retire against the live server
- [ ] P3-02 Push phase 3 and record the manual checks
- [ ] P4-01 Store `needs_rotation` in `custom_metadata` and fill in listing metadata
- [ ] P4-02 Multisite isolation, sealed-or-unreachable behaviour, and the timeout measurement
- [ ] P4-03 Push phase 4 and record the manual checks
- [ ] P5-01 Map AWS site scope to `wp/site/<blog_id>/<name>` and test it by capturing the request
- [ ] P5-02 Push phase 5 and record the manual checks
- [ ] P6-01 Write the Vault example README and update the example index, root README, and CI reference
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
