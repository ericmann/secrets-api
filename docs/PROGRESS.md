# AWS KMS keyring build progress
Branch: build/kms-keyring
Started: 2026-09-24T20:46:16.429Z

## Tasks
- [x] P0-01 Add the keyring conformance suite and make Mock_Keyring pass it
- [x] P0-02 State the non-determinism requirement in the keyring interface docblock
- [ ] P0-03 Push phase 0
- [ ] P1-01 Cache the unwrapped root key in WP_Secrets_Key_Manager for the request
- [ ] P1-02 Document root-key caching: examples README, spec page, ADR 0009
- [ ] P1-03 Push phase 1
- [ ] P2-01 Generalise wp secret rotate with --from and re-wrap under the active keyring
- [ ] P2-02 Push phase 2
- [ ] P3-01 Add the examples PHPUnit harness, Moto, and the AWS Secrets Manager conformance run
- [ ] P3-02 Add the examples CI job with a pinned Moto service container
- [ ] P3-03 Push phase 3
- [ ] P4-01 Write the AWS KMS keyring example and run the keyring conformance suite against Moto
- [ ] P4-02 Prove the KMS keyring end to end: round trip, one Decrypt per request, the adoption error, and adoption via rotate --from=config
- [ ] P4-03 Write the AWS KMS keyring README with the adoption walkthrough
- [ ] P4-04 Push phase 4
- [ ] P5-01 Bring the spec pages in line with the code
- [ ] P5-02 Update the journal tracking pages, the READMEs, and the index
- [ ] P5-03 Write the dev journal entry
- [ ] P5-04 Push phase 5, remove the Moto container, record the live-KMS check as not verified

## Log
(one entry per task, appended by implement)

### P0-01 — 62ec7e7
Added tests/includes/class-wp-secrets-keyring-conformance.php mirroring the
provider conformance shape: abstract keyring() + 6 tests (round trip,
non-determinism, garbage/truncated/flipped-byte rejection as WP_Error,
non-empty get_key_source()). Concrete classes
Tests_Secrets_ConfigKeyringConformance (WP_Secrets_Config_Key_Provider) and
Tests_Secrets_MockKeyringConformance (Mock_Keyring) both pass on
single-site and multisite.

Mock_Keyring rewritten to be non-deterministic with an integrity tag:
wrap() = MARKER + base64(8-byte nonce + key_material + sha256(nonce+key_material)).
unwrap() returns WP_SECRETS_ERROR_KEY_UNAVAILABLE for non-string, missing
marker, failed strict base64 decode, payload < 41 bytes, or hash_equals()
tag mismatch. configure_fail_wrap()/configure_fail_unwrap() unchanged, so
existing consumers (test-secrets-extension-points.php,
test-secrets-provider.php) are unaffected.

bootstrap.php requires the new conformance file after the provider one.

Fixed two phpcs findings post-write: doc-comment capitalization
("wrap()"/"unwrap()" -> "Wrap()"/"Unwrap()") and an alignment warning on
the flipped-byte test's assignments.

bin/ci-local.sh --keep and make reference-check both green (468 tests,
single-site + multisite).

### P0-02 — 90c4d53
Added the non-determinism requirement to WP_Secrets_Keyring::wrap()'s
docblock (exact sentence from the spec, naming
WP_Secrets_Key_Manager::rotate_site_key() and
WP_Secrets_Keyring_Conformance) and one sentence on the interface class
docblock pointing implementers at WP_Secrets_Keyring_Conformance by class
name only (no test path referenced from src/). No signature/@param/@return
change. Regenerated docs/reference/classes.md via make reference; diff
touched only that file. bin/ci-local.sh --keep and make reference-check
both green.
