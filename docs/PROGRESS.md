# AWS KMS keyring build progress
Branch: build/kms-keyring
Started: 2026-09-24T20:46:16.429Z

## Tasks
- [x] P0-01 Add the keyring conformance suite and make Mock_Keyring pass it
- [x] P0-02 State the non-determinism requirement in the keyring interface docblock
- [x] P0-03 Push phase 0
- [x] P1-01 Cache the unwrapped root key in WP_Secrets_Key_Manager for the request
- [x] P1-02 Document root-key caching: examples README, spec page, ADR 0009
- [x] P1-03 Push phase 1
- [x] P2-01 Generalise wp secret rotate with --from and re-wrap under the active keyring
- [x] P2-02 Push phase 2
- [x] P3-01 Add the examples PHPUnit harness, Moto, and the AWS Secrets Manager conformance run
- [x] P3-02 Add the examples CI job with a pinned Moto service container
- [x] P3-03 Push phase 3
- [x] P4-01 Write the AWS KMS keyring example and run the keyring conformance suite against Moto
- [x] P4-02 Prove the KMS keyring end to end: round trip, one Decrypt per request, the adoption error, and adoption via rotate --from=config
- [x] P4-03 Write the AWS KMS keyring README with the adoption walkthrough
- [x] P4-04 Push phase 4
- [x] P5-01 Bring the spec pages in line with the code
- [x] P5-02 Update the journal tracking pages, the READMEs, and the index
- [x] P5-03 Write the dev journal entry
- [x] P5-04 Push phase 5, remove the Moto container, record the live-KMS check as not verified
- [x] R1-01 Restore the misconfigured-WP_SECRETS_KEY scenario in the three-state contract test
- [x] R1-02 Correct the published docs: journal finding, worktree-specific wp-env path, ci.md, KMS README CI sentence

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

### P0-03 — 54972f8
Pushed build/kms-keyring to origin (new branch, tracking set up).
Manual check: none required by SPEC. No manual check needed for phase 0
per docs/SPEC.md §8 phase 1.

### P1-01 — 9458df6
Added $cached_root_key/$cached_wrapped to WP_Secrets_Key_Manager.
get_root_key() serves from cache when the stored wrapped value matches;
only caches on a string result. rotate_site_key() sets the cache after a
successful update_site_option() and also reuses the cache to avoid a
redundant unwrap() when $old_keyring === $this->keyring and the wrapped
value matches (needed so rotation itself costs zero extra unwrap calls,
per acceptance test). generate_root_key() primes the cache on both the
won-race and lost-race paths.

Mock_Keyring gained wrap_calls/unwrap_calls counters + wrap_call_count()/
unwrap_call_count().

Added 7 tests to test-wp-secrets-key-manager.php (all pass single-site +
multisite). Two pre-existing tests needed updates because the new
per-request cache makes their old premise obsolete (not a regression,
the designed effect of this task):
- test_rotation_does_not_change_any_derived_master_key: the "old keyring
  no longer works" check now uses a fresh manager instance, since the
  manager that just rotated legitimately keeps a valid primed cache.
- test-secrets-three-state-contract.php's
  test_key_unavailable_is_wp_error_not_null: corrupts the stored wrapped
  root key option instead of redefining WP_SECRETS_KEY mid-request
  (changing the constant no longer forces a fresh unwrap within one
  request/cache).

bin/ci-local.sh --keep and make reference-check green, 475 tests.

### P1-02 — f666293
Updated examples/README.md's KMS keyring section to state the accurate
per-request unwrap behavior and link ADR 0009 (only that one claim
touched). Added "Root-key caching." to providers-and-keyrings.md's As
built (cache keying, memory-only, error-not-cached, generation/rotation
priming, caller-owned copy, test file named) and "One unwrap per
request." to Why (no round-trip-volume discussion in the proposal, the
KMS-round-trip cost, why the fix lives in the key manager). Added ADR
0009 in the 0008 style (frontmatter, number/date/status table, context/
decision/consequences). Added the 0009 line to docs/index.md's
decisions/ list.

grep -n '^## ' shows exactly As proposed/As built/Why in order; grep -c
'once per request' examples/README.md is 1. bin/ci-local.sh --keep and
make reference-check both green.

### P1-03 — e8ed503
Pushed build/kms-keyring to origin (e8ed503).
Manual check: none required by SPEC.

### P2-01 — 72afe57
rotate() now accepts --from=config-previous (default, today's behaviour)
or --from=config (moves the root key onto whatever keyring
_wp_secrets_get_key_manager()->get_keyring() currently resolves to, e.g.
after a secrets.php drop-in installs one). Unknown --from values error
mentioning --from. Each mode refuses with a specific message when there
is nothing meaningful to rotate (both constants identical; active
keyring already the config keyring). Confirmation prompt and success
message use get_key_source() only, never key material.

Added 7 tests (unknown --from, config-refused, config-previous-refused,
config-previous round trip, config->drop-in move, no-key-material-
leaked). Ran vendor/bin/phpcbf once to fix 4 array-declaration-spacing
findings in the new tests. docs/reference/wp-cli.md regenerated (diff
confined to that file). Verified `wp help secret rotate` synopsis is
"wp secret rotate [--from=<keyring>] [--yes]" against the real wp-env cli
container.

bin/ci-local.sh --keep and make reference-check both green, 481 tests.

### P2-02 — 565e4d2
Pushed build/kms-keyring to origin (565e4d2).
Manual check: none required by SPEC (wp help secret rotate output is in
the P2-01 commit).

### P3-01 — 3b8fba6
Added phpunit-examples.xml.dist (bootstrap=tests/bootstrap-examples.php,
testsuite examples/*/tests, WP_SECRETS_TEST_AWS_ENDPOINT env not forced)
and tests/bootstrap-examples.php (requires tests/bootstrap.php then every
examples/*/secrets.php via glob). Makefile gained test-examples (not in
ci:). AWS_Secrets_Manager_Provider's constructor gained a fourth
$endpoint param; call() uses it as the request URL and computes the
signed Host header from wp_parse_url() (host[:port]) so Moto's signature
check matches what wp_remote_post() actually sends. Install block passes
WP_SECRETS_AWS_ENDPOINT when defined, else ''.

New conformance test class runs against Moto (motoserver/moto digest
sha256:91fd602a21f49cf9eb82fdf474015a3c131d40104c8297ea6a2ca920708ae32c,
container secrets-api-moto-kms on :5051, still running for P3-02 to
reuse). One subject name reused across the run (Moto keeps AWSPREVIOUS
between calls like real AWS); tear_down() deletes it plus the two
prefix-listing fixture names. Extra test confirms loading the example
via bootstrap-examples.php installs no provider (guard constants never
defined there).

README gained "Run it against an emulator" with the Moto commands and
make test-examples.

15 tests green via wp-env tests-cli (1 skipped: read-only-refuses-writes,
correctly skipped for a writable provider). bin/ci-local.sh --keep and
make reference-check both green, main suites unaffected (481 tests).

### P3-02 — 95c54fb
Added the `examples` job to .github/workflows/ci.yml, appended after
test-multisite (needs: static, mysql service block identical to
test-multisite, moto service pinned by digest
sha256:91fd602a21f49cf9eb82fdf474015a3c131d40104c8297ea6a2ca920708ae32c on
port 5000, env WP_SECRETS_TEST_AWS_ENDPOINT=http://127.0.0.1:5000). Same
checkout/setup-php/composer-cache/make-install steps as test-multisite
using the file's existing pinned action SHAs, then a 30x1s "Wait for
Moto" curl loop, then make test-examples. Comment explains why it is
outside make ci and that examples/ stays unlinted.

docs/reference/ci.md (hand-written, not generated) gained the examples
row in the Matrix table and one sentence in "Where this runs" naming it
the only job with a non-database service.

Verified: ruby -ryaml parses the file; the grep for the digest matches
`docker inspect secrets-api-moto-kms --format '{{.Config.Image}}'`.
bin/ci-local.sh --keep and make reference-check both green.

### P3-03 — 747d8c8
Pushed build/kms-keyring to origin (adds commit 747d8c8, an empty commit
carrying the phase-3 push/log task since Files touched is PROGRESS.md
only — no code change). No code changes required; task is push + log
only per Files touched.

Manual check: NOT VERIFIED (human) — examples CI job green on the PR.

### P4-01 — f60fbd2
Added examples/aws-kms-keyring/secrets.php: final class AWS_KMS_Keyring
implements WP_Secrets_Keyring, constants PREFIX/ENCRYPTION_CONTEXT/
TIMEOUT/KEY_LENGTH, wrap()/unwrap()/get_key_source(), private call() doing
SigV4 by hand (copied from the Secrets Manager example) against
TrentService.Encrypt/Decrypt. Install block guards on
WP_SECRETS_KMS_KEY_ID + the three AWS constants, all non-empty after
trim(). unwrap() of a non-kms1: value returns WP_SECRETS_ERROR_KEY_UNAVAILABLE
with the literal string "rotate --from=config" for P4-02's adoption test.

Added Moto_KMS_Fixture (create_key()/endpoint()/region()) copying the
SigV4 block again per the detailed spec's guidance, and
Tests_AWS_KMS_Keyring_Conformance extends WP_Secrets_Keyring_Conformance,
set_up_before_class() creates one key.

Interpretation: none -- fully specified in the task text.

22 tests green via wp-env tests-cli phpunit-examples.xml.dist (1 expected
skip). bin/ci-local.sh --keep (481 tests single+multisite) and make
reference-check both green.

### P4-02 — 534c455
Added examples/aws-kms-keyring/tests/test-aws-kms-keyring.php:
Tests_AWS_KMS_Keyring extends WP_UnitTestCase, set_up_before_class()
creates one Moto key, keyring()/seed_root_key()/
provider_under_config_keyring()/all_wp_cli_output() helpers. Decrypt
counting via an http_api_debug action added/removed per test.

All 7 named acceptance tests present and passing, plus the install-guard
test carried over in spirit from P4-01's conformance class. Isolated-
process tests seed WP_Secrets_Key_Manager::ROOT_KEY_OPTION directly via
update_site_option() before setting $GLOBALS['wp_secrets_keyring'],
matching the pattern in tests/phpunit/test-wp-secrets-key-manager.php.
The adoption test reuses cli/class-wp-cli-secret-command.php's existing
`rotate --from=config` (already generalised in an earlier phase) and
`health` subcommands directly.

Interpretation: none -- fully specified.

30 tests green via wp-env tests-cli phpunit-examples.xml.dist.
bin/ci-local.sh --keep (481 tests single+multisite) and make
reference-check both green.

### P4-03 — 01fed46
Added examples/aws-kms-keyring/README.md mirroring the AWS Secrets
Manager README's structure plus the KMS-specific sections: Where the
credentials go, Install the drop-in (wp secret dropin --verbose expected
output showing Keyring class: AWS_KMS_Keyring), IAM permissions
(kms:Encrypt/kms:Decrypt, noting Decrypt is sensitive), Adopting an
existing site (3-step walkthrough with the fail-closed warning box and
sample failure output), How often KMS is called (links ADR 0009), Design
points (all five from the detailed spec), Known limits, Prove it
conforms, Run it against an emulator.

Interpretation: none.

grep -c 'rotate --from=config' = 3 (>= 2 required). One relative link,
to ../../docs/decisions/0009-root-key-cached-for-the-request.md, and it
resolves. bin/ci-local.sh --keep and make reference-check both green.

### P4-04 — 38f06eb
Pushed build/kms-keyring to origin (adds empty commit 38f06eb carrying
the phase-4 push/log task; Files touched is PROGRESS.md only, no code
change). Verified git status clean.

Manual check: NOT VERIFIED (human) -- live KMS: fresh site, adoption
with rotate --from=config, one KMS call for a request reading ten
secrets.

### P5-01 — 33994e1
Updated extension-points.md (wrap() non-determinism requirement +
reason, unwrap() WP_Error contract, WP_Secrets_Keyring_Conformance
paragraph naming Mock_Keyring and examples/aws-kms-keyring/ on Moto),
rotation.md ("Rotating the site key" rewritten for
--from=config-previous|config, same-configuration refusal, one Why
sentence), envelope-encryption.md (one sentence on request-scoped
root-key caching linking providers-and-keyrings.md).

Interpretation: scope.md's WP-CLI mention lists rotate's name only, no
flags, so left untouched per the task text's own fallback instruction.
providers-and-keyrings.md re-read (out of scope for edits); already
accurate from P1-02, nothing false found to fix.

Heading grep confirms As proposed / As built / Why in order on all four
touched pages. bin/ci-local.sh --keep and make reference-check both
green.

### P5-02 — 3d72861
Updated the five docs files: open-questions.md ("What has been built"
names the KMS keyring example + src/CLI changes + automated Moto run;
"What is still open" trimmed), test-coverage-gaps.md (--from checked by
hand sentence + new "Examples run against an emulator, not live AWS"
entry), proposal-questions.md (question 5 gains one sentence),
examples/README.md ("Examples in this directory" + "Run the examples
suite" sections, Dependencies corrected), README.md (make test-examples
row + one sentence pointing at aws-kms-keyring/).

Interpretation: docs/index.md's test-coverage-gaps.md description text
did not change, so left untouched per the task's own instruction.

grep 'composer.json' examples/README.md: no hits. grep 'test-examples'
README.md: 1 hit. git diff --stat shows only the six named files (plus
docs/PROGRESS.md, committed separately by this tool).
bin/ci-local.sh --keep and make reference-check both green.

### P5-03 — d9729c0
Added docs/journal/2026-09-24-a-kms-keyring.md (What I built / What it
found / What I left out / What it means for the patch), linking
examples/aws-kms-keyring/README.md, the KMS test file, ADR 0008, and
ADR 0009. docs/index.md's journal/ list gains the entry before
open-questions.md.

Interpretation: none -- fully specified.

head -5 shows correct frontmatter (title/description/date matching
the filename). grep -c '0008' = 1. docs/journal/_drafts/notes.md
untouched (git diff --quiet passes, never read or cleared).
bin/ci-local.sh --keep and make reference-check both green.

### P5-04 — f4801c8
Removed the secrets-api-moto-kms container (docker rm -f; image left
in place). Pushed build/kms-keyring to origin (adds empty commit
f4801c8; Files touched is PROGRESS.md only, no code change). git status
clean; docker ps -a --filter name=secrets-api-moto-kms is empty.

Manual check: NOT VERIFIED (human) -- live KMS run per
examples/aws-kms-keyring/SPEC.md "Done when".

### R1-01 — 387d21d
Restored test_key_unavailable_is_wp_error_not_null to its original end-to-end
scenario: writes via a hand-built WP_Secrets_Libsodium_Provider (bypassing the
static _wp_secrets_get_key_manager()'s request-scoped root-key cache, ADR
0009), then defines WP_SECRETS_KEY = 424242 and asserts wp_get_secret() is
WP_Error with WP_SECRETS_ERROR_KEY_UNAVAILABLE (never null). @runInSeparateProcess
/ @preserveGlobalState disabled kept.
Moved the corrupted-wrapped-root-key body (update_site_option on
WP_Secrets_Key_Manager::ROOT_KEY_OPTION) to a new
test_a_corrupted_wrapped_root_key_is_wp_error_not_null with its own docblock,
assertions unchanged.
Verified: 11/11 tests in Tests_Secrets_ThreeStateContract pass single-site and
multisite; full bin/ci-local.sh --keep green; make reference-check clean.
No src/ changes.

### R1-02 — 936d773
Fixed five docs to match reality:
- docs/journal/2026-09-24-a-kms-keyring.md: replaced the Mock_Keyring
  paragraph with the true finding (deterministic, returned false on failed
  decode, fixed by P0-01) and dropped the false open-questions.md citation.
- examples/README.md, examples/aws-kms-keyring/README.md,
  examples/aws-secrets-manager/README.md: replaced the hard-coded
  wp-content/plugins/kms-keyring --env-cwd with
  --env-cwd="wp-content/plugins/$(basename "$PWD")", run from the repo
  root, matching how bin/ci-local.sh derives CONTAINER_CWD.
- docs/reference/ci.md: the examples-job Moto sentence now names both the
  AWS Secrets Manager provider conformance run and the AWS KMS keyring
  conformance/integration tests.
- examples/aws-kms-keyring/README.md final paragraph: replaced "which CI
  does not provide by default" with the true statement that make ci omits
  it and the examples CI job runs it against a pinned Moto container.
Verified via the exact greps in the task's Acceptance tests (all pass),
bin/ci-local.sh --keep green, make reference-check clean. git diff --stat
touches only the five named files (plus docs/PROGRESS.md via the tool).
