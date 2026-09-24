# HashiCorp Vault KV v2 provider example build progress
Branch: build/vault-provider
Started: 2026-09-24T20:46:46.009Z

## Tasks
- [ ] P1-01 Add the examples PHPUnit harness and the Vault test helper
- [ ] P1-02 Add the `examples` CI job with a Vault service container
- [ ] P1-03 Push phase 1 and record the manual checks
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
