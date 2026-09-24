# HashiCorp Vault KV v2 provider example build plan
Derived from docs/SPEC.md v1.0 on 2026-09-24. SPEC.md wins over this file. Where docs/SPEC.md
and `examples/vault-provider/SPEC.md` (the "detailed spec") disagree on design, the detailed
spec wins; on process, docs/SPEC.md wins.

## Decisions
- Shared examples harness (SPEC §9, first question) → build the compatible subset with exactly the
  KMS spec §5 names: `phpunit-examples.xml.dist`, `tests/bootstrap-examples.php`,
  `make test-examples`, a CI job named `examples`, and `examples/vault-provider/tests/`. No Moto,
  no KMS tests, no AWS conformance class. The merge with `build/kms-keyring` reconciles the two
  copies; SPEC accepts that cost.
- Interface-level findings (SPEC §9, second question) → no file under `src/`, `plugin/`, or `cli/`
  changes in this flight, not even a docblock. The detailed spec's "Done when" routes any answer
  that points at the interface to `docs/journal/open-questions.md` and the Trac ticket, so that
  is where "previous is strictly N-1" and "a BOUNDARY_PROVIDER provider still needs a root key"
  go. `docs/reference/` therefore needs no regeneration; `make reference-check` must still pass.
- Phase numbering → P1 to P6, matching SPEC §8's six phases one for one. No phase is merged.
- SPEC §8 phase 2 wants the conformance suite green, but `retire_previous()` is phase 3 and
  `list_secrets()` is phase 4 → phase 2 lands every interface method in its simplest correct form
  (`retire_previous()` destroys N-1 when one exists; `list_secrets()` returns names with blank
  metadata), and phases 3 and 4 complete the semantics and add their tests. Nothing is stubbed to
  throw, and no test is skipped to get there.
- Request timeout (detailed spec gives none; SPEC §5) → `⚠️ ASSUMPTION` class constant
  `Vault_KV2_Provider::REQUEST_TIMEOUT = 5` seconds, justified in a comment, never repeated as a
  literal. Measured in P4-02.
- `max_versions` → class constant `Vault_KV2_Provider::MAX_VERSIONS = 2`. Given by the detailed
  spec, so not an assumption, but still never a literal elsewhere.
- "A set without the flag clears it" → clearing writes `custom_metadata.needs_rotation = "0"`.
  Vault replaces the whole `custom_metadata` map on `POST metadata`, and an empty PHP array
  JSON-encodes as `[]`, which Vault rejects, so "0" is the unambiguous clear. The flag reads as
  set only when the string is exactly `"1"`.
- Vault `LIST` → `GET <path>?list=true`, which Vault documents as equivalent. `WP_Http`'s support
  for a custom `LIST` verb is not worth depending on in a drop-in.
- `list_secrets( $name_prefix )` → `$name_prefix` is a namespace, exactly as
  `WP_Secrets_Libsodium_Provider::list_secrets()` treats it: with a prefix, only
  `wp/<scope>/<prefix>/` is listed. That is also the cheapest shape for question 4.
- `wp_secret_changed` fingerprints → `''` for old and new, as the AWS example does. Fired with
  `created`/`updated` from `set()`, `deleted` from `delete()`, and `retired` from
  `retire_previous()` when a version was actually destroyed, matching the shipped provider.
- Order of action and flag error in `set()` → the action fires as soon as the value write
  succeeds; a failed flag write with the flag requested then returns `WP_Error`. The value did
  change, and an audit hook that misses a change is worse than one that sees a change whose flag
  failed.
- Fingerprint scope → `$network ? 'network' : 'site'` and `$network ? null : get_current_blog_id()`,
  as the shipped provider does. The AWS example uses `'site'` for both; that inconsistency is
  pre-existing, out of scope, and noted in the journal entry.
- Vault image → pull `hashicorp/vault:latest` once in P1-01, record the digest and the version
  `GET /v1/sys/health` reports, and pin that digest everywhere it appears (Makefile comment,
  `ci.yml`, README). The detailed spec names no version.
- Test server configuration → environment variables `VAULT_ADDR` (default `http://127.0.0.1:8200`)
  and `VAULT_TOKEN` (default `dev-root`), the names the Vault CLI uses. Locally, inside wp-env,
  `VAULT_ADDR=http://host.docker.internal:8201`. Tests fail, never skip, when Vault is unreachable:
  `make test-examples` exists to run against a server.
- Test isolation on a persistent backend → every Vault test class wipes everything under
  `wp/` on the mount in `set_up()` through the test helper, because `WP_UnitTestCase`'s database
  rollback does not reach Vault and the conformance suite reuses `conformance/subject`.
- Multisite tests → one file gated with `markTestSkipped()` when `! is_multisite()`. That is the
  environment gate SPEC §3 allows. `make test-examples` runs the suite twice, once with
  `WP_MULTISITE=1`.
- A new ADR → yes, one: `docs/decisions/0009-cap-a-many-version-backend-to-two-slots.md`, for the
  decision that the provider sets `max_versions: 2` rather than widen the version model. Number
  0009 expects renumbering at merge (SPEC §3).
- `extraVerify` → none. SPEC §7 allows only existing make targets, and `make test-examples` runs
  PHPUnit on the host, which locally has no WordPress test suite. Every task that touches the
  example runs the wp-env command in its Verification instead.
- The Vault dev container → started in P1-01, removed in P6-04, the last task. CLAUDE.md records
  how to start it again.
- Journal entry date → the day P6-03 runs (`date +%Y-%m-%d`), per SPEC §2.

## Conventions
- Branch: `build/vault-provider` (already checked out; do not create another).
- Commit title: `<ID>: <imperative title>` (for example `P2-01: Add the Vault KV v2 provider
  skeleton`). Body wrapped at 72 columns, explaining why, then the labelled lines
  `Goal:`, `Tests:`, `Interpretation:`, `Measurement:` (tuning tasks only), `Manual check:`.
- Before every task: read `CONTRIBUTING.md`, the whole of `CLAUDE.md`, the task, and the SPEC
  sections it cites. Also read `examples/aws-secrets-manager/secrets.php` before touching any
  example: it is the house style for a drop-in.
- Every task's Verification includes the two `verify` commands from `docs/foundry.json`
  (`bin/ci-local.sh --keep` and `make reference-check`). Tasks that touch `examples/` also run
  the examples suite inside wp-env:
  ```
  npx @wordpress/env run --env-cwd=wp-content/plugins/vault-provider tests-cli env VAULT_ADDR=http://host.docker.internal:8201 VAULT_TOKEN=dev-root vendor/bin/phpunit -c phpunit-examples.xml.dist
  npx @wordpress/env run --env-cwd=wp-content/plugins/vault-provider tests-cli env WP_MULTISITE=1 VAULT_ADDR=http://host.docker.internal:8201 VAULT_TOKEN=dev-root vendor/bin/phpunit -c phpunit-examples.xml.dist
  ```
  Below these two commands are called "the examples suite, both passes". If wp-env is not
  running, `npx @wordpress/env start` first. If the Vault container is not running, start it
  with the `docker run` line recorded in the Makefile comment above `test-examples`.
- Never edit or commit `.wp-env.override.json`. Never run `wp-env destroy`. Never run
  `sf publish`, never tag.
- Every `phpcs:ignore` carries ` -- <reason>` on the same line. No `phpcs.xml.dist` change is
  expected in this flight; if one is unavoidable it carries a reason in an XML comment.
- Nothing about employers, customers, or internal channels in `docs/`, READMEs, or commit bodies.
- Vault HTTP shapes used throughout (mount `secret`, all under `/v1/`):
  `GET secret/data/<p>[?version=N]` (200 with `data.data.value`; 404 when absent, soft-deleted,
  or destroyed), `POST secret/data/<p>` body `{"data":{"value":...}}`,
  `GET secret/metadata/<p>` (200 with `data.current_version`, `data.versions.{N:{created_time,
  deletion_time,destroyed}}`, `data.custom_metadata`, `data.created_time`, `data.oldest_version`,
  `data.max_versions`; 404 when absent), `POST secret/metadata/<p>` body `{"max_versions":N}` or
  `{"custom_metadata":{...}}` (204), `DELETE secret/metadata/<p>` (204 whether or not it existed),
  `POST secret/destroy/<p>` body `{"versions":[N]}` (204), `POST secret/delete/<p>` body
  `{"versions":[N]}` (204, soft delete), `GET secret/metadata/<p>?list=true` (200 with
  `data.keys`, directories end in `/`; 404 when nothing is there), `GET sys/health` (200 with
  `initialized`, `sealed`, `version`; 503 when sealed). Headers: `X-Vault-Token`,
  `X-Vault-Request: true`, `Content-Type: application/json`, and `X-Vault-Namespace` only when a
  namespace is configured. Vault error bodies are `{"errors":["..."]}`.

## Phase 1 — Examples harness, Vault half
### P1-01: Add the examples PHPUnit harness and the Vault test helper
**Goal:** Create the shared-harness subset this example needs (config, bootstrap, make target, test helper) and prove the suite can reach a real Vault dev server.
**Files touched:** `phpunit-examples.xml.dist` (new), `tests/bootstrap-examples.php` (new), `Makefile`, `.gitignore`, `examples/vault-provider/tests/includes/class-vault-test-server.php` (new), `examples/vault-provider/tests/test-vault-harness.php` (new).
**Design constraints:** SPEC §8 phase 1 and §3 "Parallel flights": use exactly the KMS spec §5 names; keep the Makefile edit additive (new target, new `.PHONY` entry, not in `ci`). SPEC §7: this worktree's wp-env is on ports 8920/8921; never touch `.wp-env.override.json`. Detailed spec, Deliverable 2: the harness bootstraps through `tests/bootstrap.php` and loads each example's `secrets.php` without its install block, which happens naturally because the `WP_SECRETS_VAULT_*` constants are undefined under test.
- `phpunit-examples.xml.dist`: copy the attributes of `phpunit.xml.dist` (bootstrap becomes `tests/bootstrap-examples.php`), one testsuite `examples` with `<directory prefix="test-" suffix=".php">examples/vault-provider/tests</directory>`, no `<coverage>` block, and **no** `<php>` block: multisite is selected by the `WP_MULTISITE=1` environment variable, which the WordPress test bootstrap honours, so one config file serves both passes.
- `tests/bootstrap-examples.php`: `require_once __DIR__ . '/bootstrap.php';` then `require_once` every `examples/*/tests/includes/*.php` (glob, sorted) and then every `examples/*/secrets.php` (glob, sorted). File docblock explains that install blocks are inert under test because their constants are undefined. Must pass `phpcs` (it is under `tests/`, which `phpcs.xml.dist` lints with the test relaxations).
- `Makefile`: target `test-examples` with help text `## Run the examples suite against live service containers (not part of ci).` running `$(VENDOR_BIN)/phpunit -c phpunit-examples.xml.dist` and then `WP_MULTISITE=1 $(VENDOR_BIN)/phpunit -c phpunit-examples.xml.dist`. Above it, a comment block with the exact local Vault command, digest filled in:
  `docker run -d --name secrets-api-vault -p 8201:8200 -e VAULT_DEV_ROOT_TOKEN_ID=dev-root --cap-add=IPC_LOCK hashicorp/vault@sha256:<digest>` and the wp-env invocation from Conventions. Add `test-examples` to `.PHONY`. Do not add it to `ci`.
- `.gitignore`: add `/phpunit-examples.xml` beside the other local phpunit overrides.
- Pull the image first: `docker pull hashicorp/vault:latest`, then
  `docker image inspect hashicorp/vault:latest --format '{{index .RepoDigests 0}}'` gives `hashicorp/vault@sha256:<digest>`. Start the container with the pinned digest, wait for `curl -s http://127.0.0.1:8201/v1/sys/health` to return `"sealed":false`, and record the digest and the `version` field in the commit body and the progress log. P1-02 and P6-01 copy the digest from the Makefile comment.
- `class-vault-test-server.php` declares `final class Vault_Test_Server` (not a test case), constructed with no arguments from `getenv( 'VAULT_ADDR' )` (default `http://127.0.0.1:8200`), `getenv( 'VAULT_TOKEN' )` (default `dev-root`), mount `secret`. Public methods: `addr()`, `token()`, `mount()`, `provider()` (returns `new Vault_KV2_Provider( addr, token, mount )` — this method is added in P2-01; in P1-01 leave it out), `request( $method, $path, $body = null )` returning `array( 'code' => int, 'body' => array|null )` via `wp_remote_request()` with the Vault headers and a 10 s timeout (a literal is fine here: `tests/` is excluded from the timeout constraint), `health()` (decoded `sys/health`), `metadata( $vault_path )` (decoded `data` of `GET secret/metadata/<path>`, or `null` on 404), `read_version( $vault_path, $version )` (HTTP code of `GET secret/data/<path>?version=N`), `create_metadata( $vault_path, $max_versions )` (`POST secret/metadata/<path>`), `soft_delete_versions( $vault_path, array $versions )` (`POST secret/delete/<path>`), `list_keys( $vault_path )` (keys array, or `array()` on 404), and `wipe()` which lists recursively from `secret/metadata/wp/` and `DELETE`s `secret/metadata/<full path>` for every non-directory key. `$vault_path` arguments are paths under the mount, such as `wp/site/1/acme/key`.
**Acceptance tests:** `examples/vault-provider/tests/test-vault-harness.php`, class `Tests_Vault_Harness extends WP_UnitTestCase`:
- `test_the_dev_server_is_reachable_and_unsealed` — `health()` has `initialized === true` and `sealed === false`.
- `test_kv_v2_is_mounted_at_secret` — `GET sys/mounts` reports `secret/` with `options.version === "2"` (check both the top-level key and `data['secret/']`, Vault returns both).
- `test_wipe_removes_everything_under_wp` — write `wp/site/1/harness/one` and `wp/network/harness/two` with `request( 'POST', 'secret/data/...' )`, call `wipe()`, then `list_keys( 'wp/' )` is `array()` and `metadata( 'wp/site/1/harness/one' )` is `null`.
**Out of scope:** `Vault_KV2_Provider` itself, the CI job, Moto, any KMS or AWS test, any edit to `phpunit.xml.dist`, `phpunit-multisite.xml.dist`, `phpcs.xml.dist`, or `.wp-env.json`.
**Verification:** `docker ps` shows `secrets-api-vault`; the examples suite, both passes (3 tests green each); `bin/ci-local.sh --keep`; `make reference-check`.
**Depends on:** none

### P1-02: Add the `examples` CI job with a Vault service container
**Goal:** Give `make test-examples` a hosted run against a real Vault, pinned by digest, without touching the existing jobs.
**Files touched:** `.github/workflows/ci.yml`.
**Design constraints:** SPEC §3 "Parallel flights": additive only, one new job appended after `test-multisite`, no edit to any existing job. `ci.yml`'s own rule: every action pinned by full commit SHA (copy the exact `uses:` lines and SHAs from the `test-multisite` job) and the Vault image pinned by the digest recorded in the Makefile comment from P1-01. Detailed spec, Deliverable 2: dev mode, root token through `VAULT_DEV_ROOT_TOKEN_ID`, KV v2 at `secret/` by default.
- Job `examples`, `name: Examples`, `needs: static`, `runs-on: ubuntu-latest`, PHP 8.3 with `sodium, mysqli`, the same `mysql` service block as `test-multisite`, plus a `vault` service: `image: hashicorp/vault@sha256:<digest>`, `env: VAULT_DEV_ROOT_TOKEN_ID: dev-root`, `ports: ['8200:8200']`, `options: >-` with `--cap-add=IPC_LOCK --health-cmd="wget -qO- http://127.0.0.1:8200/v1/sys/health" --health-interval=5s --health-timeout=3s --health-retries=10`.
- Steps: checkout, setup-php, composer cache (key `composer-${{ runner.os }}-php8.3-...`), `make install WP_VERSION=latest DB_HOST=127.0.0.1`, then `make test-examples` with `env: VAULT_ADDR: http://127.0.0.1:8200` and `VAULT_TOKEN: dev-root`.
- A comment above the job says why it is outside `make ci` (needs a service container `make ci`'s environments do not provide, as `examples/README.md` and the KMS spec §5 say) and that the digest is the one the Makefile comment names.
**Acceptance tests:** none executable locally beyond YAML validity: `python3 -c "import yaml,sys; yaml.safe_load(open('.github/workflows/ci.yml'))"` (or `npx --yes js-yaml .github/workflows/ci.yml >/dev/null` if Python has no PyYAML) exits 0. The job's run is a manual check in P1-03.
**Out of scope:** Moto, any change to `static`, `test`, `test-multisite`, or `reference-docs`, `docs/reference/ci.md` (P6-01), the smoke job (`build/cli-smoke`'s work).
**Verification:** YAML parses; `grep -c 'hashicorp/vault@sha256:' .github/workflows/ci.yml Makefile` is 1 each with identical digests; `bin/ci-local.sh --keep`; `make reference-check`.
**Depends on:** P1-01

### P1-03: Push phase 1 and record the manual checks
**Goal:** Push the branch and record what only a human can confirm about the harness.
**Files touched:** `docs/PROGRESS.md` only.
**Design constraints:** SPEC §8: every phase ends by pushing. SPEC §3: never tag, never publish.
**Acceptance tests:** none.
**Out of scope:** any code or docs change.
**Verification:** `git push -u origin build/vault-provider` succeeds; progress log entry reads `Manual check: NOT VERIFIED (human)` and lists: (1) the `examples` job is green on GitHub Actions for this branch's draft PR, including the Vault service health check; (2) the pinned digest resolves on Docker Hub to a current 1.x release.
**Depends on:** P1-02

## Phase 2 — Vault provider core
### P2-01: Add the Vault KV v2 provider skeleton with path mapping, HTTP client, `get()`, and `delete()`
**Goal:** Create `examples/vault-provider/secrets.php` with every interface method present, the HTTP client and error mapping, path mapping, `get()` for both slots, `delete()`, and the declarations, tested offline through `pre_http_request`.
**Files touched:** `examples/vault-provider/secrets.php` (new), `examples/vault-provider/tests/includes/class-vault-test-server.php` (add `provider()`), `examples/vault-provider/tests/test-vault-provider-paths.php` (new).
**Design constraints:** Detailed spec, Deliverable 1 (constants, path mapping, method mapping rows for `get`, `delete`, `get_label`, `get_protection_boundary`, `is_writable`, "Errors", "Caching", "Fingerprints"). SPEC §3: single file, no Composer, no SDK, readable top to bottom; errors not exceptions (never `throw`); no plaintext in any `WP_Error` message or log line; request-scoped memo only, never `wp_cache_set()`/transients/options; three states never collapse (absent is `null`, unreachable is `WP_Error`). SPEC §5: the timeout is `⚠️ ASSUMPTION`.
- File header docblock in the AWS example's voice: what it is, why a provider, "the part that is a translation" (integer versions versus two slots, pointing at the README for the four answers), that the data write and metadata write are two requests and not a transaction, and that fingerprints still need the site's own key material.
- `defined( 'ABSPATH' ) || exit;` then `final class Vault_KV2_Provider implements WP_Secrets_Provider`.
- Class constants: `MAX_VERSIONS = 2` (comment: makes Vault a two-slot store; see README question 2), `REQUEST_TIMEOUT = 5` (comment starting `⚠️ ASSUMPTION:` — every secret read waits on this; long enough for a cold TLS handshake to a remote Vault, short enough that an outage fails a page in seconds rather than tying up PHP workers; measured in P4-02), `ROTATION_FLAG = 'needs_rotation'`.
- Constructor `__construct( $addr, $token, $mount = 'secret', $namespace = '' )`; `$addr` stored with trailing slashes trimmed, `$mount` with slashes trimmed. Private properties `$addr`, `$token`, `$mount`, `$namespace`, `$memo = array()`.
- Private `scope_prefix( $network )` → `'wp/network/'` or `'wp/site/' . get_current_blog_id() . '/'` (read at call time so `switch_to_blog()` is honoured). Private `vault_path( $name, $network )` → `scope_prefix() . $name`. Private `url( $kind, $vault_path, array $query = array() )` → `{addr}/v1/{mount}/{kind}/{vault_path}` plus `?query`, where `$kind` is `data`, `metadata`, `destroy`, or `delete`.
- Private `request( $method, $url, $body = null )` → decoded `data` array on 2xx (an empty array for 204), `null` on 404, otherwise `WP_Error( WP_SECRETS_ERROR_STORE_UNAVAILABLE, ... )`. Transport failure: message `Vault unreachable: <wp error message>`. Non-2xx: message `Vault error (HTTP <code>): <errors joined by '; '>`, falling back to the raw body when `errors` is absent. 403 and 503 both go through this branch, so permission denied and sealed both read as unreachable. Body is `wp_json_encode( $body )` when non-null. Uses `wp_remote_request()` with `'method'`, `'timeout' => self::REQUEST_TIMEOUT`, and the headers listed in Conventions; `X-Vault-Namespace` only when `'' !== $this->namespace`.
- Private `read_metadata( $name, $network )` → `request( 'GET', url( 'metadata', ... ) )`.
- Private `previous_version( $meta )` → `int|null`: `$n = (int) $meta['current_version']`; `null` when `$n < 2`; `$v = $meta['versions'][ (string) ( $n - 1 ) ]` or `null` when missing; `null` when `! empty( $v['deletion_time'] )` or `! empty( $v['destroyed'] )`; otherwise `$n - 1`. Docblock states the rule: strictly N-1, never the newest survivor below N, and why (retiring must never resurrect an older version).
- `get( $name, $version, $network = false )`: memo key `vault_path . '#' . $version`. Anything other than `WP_Secret_Version::PREVIOUS` reads CURRENT: `GET data/<path>`; `null` → `null`; `WP_Error` → return it; missing or non-string `data.data.value` → `WP_Error( WP_SECRETS_ERROR_RECORD_MALFORMED, 'Vault returned a secret without a string "value" field.' )`. PREVIOUS: `read_metadata()`; `WP_Error` → return; `null` → `null`; `previous_version()` `null` → `null`; else `GET data/<path>?version=N-1`, same handling. On success memoise the value and return `build_secret()`.
- Private `build_secret( $name, $value, $network )`: `_wp_secrets_get_key_manager()->get_master_key( $network ? 'network' : 'site', $network ? null : get_current_blog_id() )`, `( new WP_Secrets_Cipher() )->fingerprint( $master_key, $value )`, `wp_secrets_memzero( $master_key )`, `new WP_Secret( $name, $value, $fingerprint )`, returning any `WP_Error` on the way.
- `delete( $name, $network = false )`: `DELETE metadata/<path>`; `WP_Error` → return; otherwise clear the memo, `do_action( 'wp_secret_changed', $name, 'deleted', get_current_user_id(), time(), '', '' )`, return `true`. 404 cannot occur (Vault answers 204) but is treated as success too.
- `get_label()` → `sprintf( 'HashiCorp Vault (%s, mount %s)', $this->addr, $this->mount )`; `get_protection_boundary()` → `self::BOUNDARY_PROVIDER`; `is_writable()` → `true` with a docblock saying a token without write policy surfaces as `WP_Error` from `set()` and is not detected in advance.
- `set()`, `retire_previous()`, `list_secrets()` exist with their final signatures and docblocks and return `new WP_Error( WP_SECRETS_ERROR_STORE_UNAVAILABLE, 'Not implemented.' )`. They are completed in P2-02. Do not leave a `TODO` marker; the docblocks describe the final behaviour.
- Install block at the bottom, modelled on the AWS example's guard: only when `WP_SECRETS_VAULT_ADDR` and `WP_SECRETS_VAULT_TOKEN` are defined and non-empty after `trim()`; mount from `WP_SECRETS_VAULT_MOUNT` when defined and non-empty else `'secret'`; namespace from `WP_SECRETS_VAULT_NAMESPACE` when defined else `''`.
- `Vault_Test_Server::provider()` returns `new Vault_KV2_Provider( $this->addr(), $this->token(), $this->mount() )`.
**Acceptance tests:** `examples/vault-provider/tests/test-vault-provider-paths.php`, class `Tests_Vault_Provider_Paths extends WP_UnitTestCase`, entirely offline: `set_up()` installs a `pre_http_request` filter (priority 10, 3 args) that records `$url`, `$parsed_args`, and returns a queued fake response; `tear_down()` removes it. A helper `fake_response( $code, $body_array )` builds `array( 'headers' => array(), 'body' => wp_json_encode( $body_array ), 'response' => array( 'code' => $code, 'message' => '' ), 'cookies' => array(), 'filename' => null )`. Provider under test: `new Vault_KV2_Provider( 'http://vault.test:8200', 'test-token' )` unless stated.
- `test_site_scope_maps_to_wp_site_blog_id_namespace_key` — `get( 'acme/key', CURRENT )` requests `http://vault.test:8200/v1/secret/data/wp/site/1/acme/key`.
- `test_network_scope_maps_to_wp_network_namespace_key` — `get( 'acme/key', CURRENT, true )` requests `.../v1/secret/data/wp/network/acme/key`.
- `test_a_custom_mount_and_namespace_are_used` — provider with mount `kv` and namespace `team-a`: URL contains `/v1/kv/data/` and headers contain `X-Vault-Namespace: team-a`; the default provider sends no `X-Vault-Namespace` header.
- `test_the_token_header_is_sent_and_the_timeout_is_the_constant` — `X-Vault-Token === 'test-token'`, `$parsed_args['timeout'] === Vault_KV2_Provider::REQUEST_TIMEOUT`.
- `test_a_404_on_current_is_null` — fake 404 with `{"errors":[]}` → `null`.
- `test_a_403_is_store_unavailable_with_vaults_message` — fake 403 `{"errors":["permission denied"]}` → `WP_Error`, code `WP_SECRETS_ERROR_STORE_UNAVAILABLE`, message contains `permission denied`.
- `test_a_sealed_vault_is_store_unavailable_not_null` — fake 503 `{"errors":["Vault is sealed"]}` → `WP_Error` with that text.
- `test_a_transport_failure_is_store_unavailable` — filter returns `new WP_Error( 'http_request_failed', 'cURL error 7' )` → `WP_Error`, code `WP_SECRETS_ERROR_STORE_UNAVAILABLE`.
- `test_a_missing_value_field_is_record_malformed` — fake 200 with `data.data = {}` → code `WP_SECRETS_ERROR_RECORD_MALFORMED`.
- `test_current_reveals_the_value_and_is_memoised` — fake 200 with `data.data.value = 'sk_live_x'` → `WP_Secret` revealing `sk_live_x`; a second `get()` makes no further request (count recorded requests).
- `test_previous_with_one_version_is_null_without_a_data_read` — metadata fake with `current_version 1` → `null`, exactly one request made.
- `test_previous_skips_a_destroyed_n_minus_1_rather_than_falling_back` — metadata `current_version 3`, `versions["2"].destroyed = true`, `versions["1"]` clean → `null`, exactly one request made (no `?version=1` read).
- `test_previous_reads_exactly_n_minus_1` — metadata `current_version 3` with clean `versions["2"]`, then data fake → second request URL ends with `?version=2` and the secret reveals the faked value.
- `test_delete_returns_true_on_204_and_fires_deleted` — fake 204 → `true`; `wp_secret_changed` observed once with action `deleted`.
- `test_delete_on_a_sealed_vault_is_an_error_not_success` — fake 503 → `WP_Error`.
- `test_declarations` — label is `HashiCorp Vault (http://vault.test:8200, mount secret)`, boundary is `BOUNDARY_PROVIDER`, `is_writable()` is `true`.
**Out of scope:** the bodies of `set()`, `retire_previous()`, `list_secrets()` (P2-02); the `needs_rotation` flag (P4-01); any live-server test; README.
**Verification:** `php -l examples/vault-provider/secrets.php`; `wc -l examples/vault-provider/secrets.php` under 450 including docblocks; the examples suite, both passes; `bin/ci-local.sh --keep`; `make reference-check`.
**Depends on:** P1-01

### P2-02: Implement `set()`, `retire_previous()`, and a minimal `list_secrets()`; run the conformance suite against Vault
**Goal:** Complete the write path with `max_versions: 2` on create, make every interface method behave, and get `WP_Secrets_Provider_Conformance` green against the dev server.
**Files touched:** `examples/vault-provider/secrets.php`, `examples/vault-provider/tests/test-vault-provider-conformance.php` (new), `examples/vault-provider/tests/test-vault-provider.php` (new).
**Design constraints:** Detailed spec, Deliverable 1 method mapping rows for `set()`, `retire_previous()`, `list_secrets()`; SPEC §8 phase 2 ("conformance suite green", "`max_versions: 2` on create"). SPEC §3 constraints as in P2-01 (single file, no throw, no plaintext in messages, request-scoped memo, three states). Decisions above: `list_secrets` prefix is a namespace; `wp_secret_changed` fingerprints are `''`; `retired` fires only when a version was destroyed. Never skip a conformance test.
- `set( $name, $value, $network = false, $needs_rotation = false, $action = null )`: `$meta = read_metadata()`; `WP_Error` → return. `$created = ( null === $meta )`. When created: `POST metadata/<path>` with `array( 'max_versions' => self::MAX_VERSIONS )`; `WP_Error` → return. Then `POST data/<path>` with `array( 'data' => array( 'value' => $value ) )`; `WP_Error` → return. Clear `$this->memo`. `do_action( 'wp_secret_changed', $name, null !== $action ? $action : ( $created ? 'created' : 'updated' ), get_current_user_id(), time(), '', '' )`. Return `true`. `$needs_rotation` is accepted and not yet written; P4-01 adds the flag write after the action. The docblock already describes the final flag behaviour from the detailed spec.
- `retire_previous( $name, $network = false )`: `read_metadata()`; `WP_Error` → return; `null` → `true`; `$prev = previous_version( $meta )`; `null` → `true`; `POST destroy/<path>` with `array( 'versions' => array( $prev ) )`; `WP_Error` → return; clear memo; fire `wp_secret_changed` with `retired`; return `true`. Docblock: destroy rather than soft delete because a soft-deleted version can be undeleted and retire means gone.
- `list_secrets( $name_prefix = '', $network = false )`: `$base = scope_prefix( $network )`. Namespaces: when `'' !== $name_prefix`, `array( $name_prefix )`; otherwise `GET metadata/<base>?list=true`, `null` → return `array()`, `WP_Error` → return it, keep keys ending in `/` with the slash removed. For each namespace: `GET metadata/<base><ns>/?list=true`; `null` → skip; `WP_Error` → return it; for each key not ending in `/`, append `array( 'name' => "$ns/$key", 'fingerprint' => '', 'created' => 0, 'has_previous' => false, 'needs_rotation' => false )`. Docblock says the fingerprint is `''` as in the AWS example and that P4-01's per-secret metadata read fills the other fields. Write a private `list_keys( $url )` helper returning `array|null|WP_Error` so P4-01 does not restructure this.
**Acceptance tests:**
- `examples/vault-provider/tests/test-vault-provider-conformance.php`, class `Tests_Vault_Provider_Conformance extends WP_Secrets_Provider_Conformance`: `set_up()` calls `parent::set_up()`, constructs `Vault_Test_Server`, calls `wipe()`; `provider()` returns `$this->server->provider()`. No overridden or skipped conformance test. All 13 inherited tests pass (two are skipped by the base class itself because the provider is writable; that is the base class's behaviour, not this class's).
- `examples/vault-provider/tests/test-vault-provider.php`, class `Tests_Vault_Provider extends WP_UnitTestCase`, `set_up()` wipes, `$this->server` and `$this->provider` fields:
  - `test_create_sets_max_versions_to_two_in_vault_itself` — `set( 'acme/key', 'v1' )`, then `server->metadata( 'wp/site/1/acme/key' )['max_versions'] === Vault_KV2_Provider::MAX_VERSIONS`.
  - `test_first_write_fires_created_and_second_fires_updated` — capture `wp_secret_changed` args; actions are `created` then `updated`, and the captured argument list never contains the plaintext.
  - `test_an_explicit_action_overrides_created_or_updated` — `set( ..., false, false, 'imported' )` fires `imported`.
  - `test_update_does_not_reassert_max_versions` — `server->create_metadata( 'wp/site/1/acme/key', 10 )`, then `set()` twice; `max_versions` in Vault is still `10`. (Documents the consequence recorded in ADR 0009: a secret created outside the provider keeps its own policy.)
  - `test_retire_destroys_exactly_n_minus_1_and_fires_retired` — writes `v1`, `v2`; `retire_previous()` → `true`; `server->metadata()['versions']['1']['destroyed'] === true`; `read_version( path, 2 )` is 200; action `retired` fired once.
  - `test_retire_with_nothing_to_retire_fires_nothing` — one write, `retire_previous()` → `true`, no `retired` action.
  - `test_list_returns_names_across_namespaces_and_never_a_value` — set `alpha/one`, `alpha/two`, `beta/three` with a canary value; `list_secrets()` names are exactly those three (sorted for comparison); `wp_json_encode()` of the result does not contain the canary; `list_secrets( 'beta' )` is exactly `beta/three`.
  - `test_list_on_an_empty_mount_is_an_empty_array` — after `wipe()`, `list_secrets()` is `array()`, not `WP_Error`.
**Out of scope:** the `needs_rotation` flag (P4-01); `created`/`has_previous` in listings (P4-01); the "retiring does not resurrect" and "only two versions" tests (P3-01); README.
**Verification:** the examples suite, both passes, with `Tests_Vault_Provider_Conformance` reporting 11 passed and 2 skipped (the base class's read-only skips) and `Tests_Vault_Provider` all green; `bin/ci-local.sh --keep`; `make reference-check`.
**Depends on:** P2-01

### P2-03: Push phase 2 and record the manual checks
**Goal:** Push the branch with the provider core in place.
**Files touched:** `docs/PROGRESS.md` only.
**Design constraints:** SPEC §8. Never tag, never publish.
**Acceptance tests:** none.
**Out of scope:** any code or docs change.
**Verification:** `git push origin build/vault-provider`; progress log entry reads `Manual check: NOT VERIFIED (human)` and lists: (1) the `examples` job is green on GitHub Actions; (2) the drop-in installed on a real wp-env site with the four constants set makes `wp secret dropin` report `Provider: Vault_KV2_Provider` and `Protected by: HashiCorp Vault (...)`, and `wp secret set`/`get --reveal` round-trip through the dev server. Both steps need a human because the wp-env drop-in sits in front of PHPUnit (see the AWS README's gotcha).
**Depends on:** P2-02

## Phase 3 — Versions and retirement
### P3-01: Prove strict N-1 and destroy-on-retire against the live server
**Goal:** Test the version translation end to end: retiring never resurrects, only two versions survive, N-1 is strict even when older versions exist, and a soft-deleted N-1 reads as absent.
**Files touched:** `examples/vault-provider/tests/test-vault-provider.php`, `examples/vault-provider/secrets.php` only if a test exposes a defect in `previous_version()` or `retire_previous()`.
**Design constraints:** Detailed spec, question 1 and Deliverable 2's first two provider-specific tests; SPEC §8 phase 3. The rule under test is the one already in `Vault_KV2_Provider::previous_version()` (P2-01): strictly N-1, `null` when N-1 is missing, soft-deleted, or destroyed, never an older version. If a test fails, fix the provider, never the test. Tests read Vault directly through `Vault_Test_Server` where the detailed spec says "read directly rather than through the provider".
**Acceptance tests:** added to `Tests_Vault_Provider`:
- `test_retiring_does_not_resurrect_an_older_version` — write `v1`, `v2`, `v3`; `retire_previous()`; `get( PREVIOUS )` is `null` (and `assertNotWPError`); write `v4`; `get( PREVIOUS )` reveals `v3`; `get( CURRENT )` reveals `v4`.
- `test_only_two_versions_are_kept_in_vault_itself` — write `v1`, `v2`, `v3`; `server->read_version( path, 1 )` is 404; `server->metadata( path )['versions']` has no key `"1"` and `oldest_version` is `2`; version `2` and `3` read 200.
- `test_previous_is_strictly_n_minus_1_even_when_older_versions_survive` — `server->create_metadata( path, 10 )` first so the provider's `MAX_VERSIONS` does not prune; write `v1`, `v2`, `v3`; `retire_previous()`; `get( PREVIOUS )` is `null`; `server->read_version( path, 1 )` is still 200, proving the `null` came from the rule and not from pruning. This is the test that answers question 1.
- `test_a_soft_deleted_n_minus_1_reads_as_absent` — write `v1`, `v2`; `server->soft_delete_versions( path, array( 1 ) )`; `get( PREVIOUS )` is `null`; `get( CURRENT )` reveals `v2`.
- `test_a_soft_deleted_current_reads_as_absent_not_error` — write `v1`; `soft_delete_versions( path, array( 1 ) )`; `get( CURRENT )` is `null` and not `WP_Error`.
- `test_retire_clears_the_memo` — write `v1`, `v2`; `get( PREVIOUS )` reveals `v1`; `retire_previous()`; `get( PREVIOUS )` is `null` on the same provider instance.
- `test_retire_is_idempotent` — write `v1`, `v2`; `retire_previous()` twice, both `true`; `retired` fired exactly once.
**Out of scope:** `needs_rotation`, listings, multisite, sealed-server tests, README.
**Verification:** the examples suite, both passes; `bin/ci-local.sh --keep`; `make reference-check`.
**Depends on:** P2-02

### P3-02: Push phase 3 and record the manual checks
**Goal:** Push the branch with the version semantics proven.
**Files touched:** `docs/PROGRESS.md` only.
**Design constraints:** SPEC §8. Never tag, never publish.
**Acceptance tests:** none.
**Out of scope:** any code or docs change.
**Verification:** `git push origin build/vault-provider`; progress log entry reads `Manual check: NOT VERIFIED (human)` and lists: (1) the `examples` job is green; (2) on a real site with the drop-in installed, `wp secret set`, `wp secret set` again, `wp secret retire --yes`, then `wp secret get --slot=previous` reports absence, and `vault kv metadata get` shows the retired version destroyed.
**Depends on:** P3-01

## Phase 4 — Metadata and listing
### P4-01: Store `needs_rotation` in `custom_metadata` and fill in listing metadata
**Goal:** Write and read the rotation flag with the detailed spec's failure rule, and make `list_secrets()` report `created`, `has_previous`, and `needs_rotation` from metadata.
**Files touched:** `examples/vault-provider/secrets.php`, `examples/vault-provider/tests/test-vault-provider.php`.
**Design constraints:** Detailed spec, "`needs_rotation`" paragraph and the `list_secrets()` row; SPEC §8 phase 4. Decisions above: clear writes `"0"`; the flag reads as set only when exactly `"1"`; the action fires before a flag failure is returned. SPEC §3: no plaintext in any log line; the log message names the path and Vault's error, never the value. Requires Vault 1.9+ for `custom_metadata`; the class docblock says so.
- Private `flag_is_set( $meta )` → `isset( $meta['custom_metadata'][ self::ROTATION_FLAG ] ) && '1' === $meta['custom_metadata'][ self::ROTATION_FLAG ]`; `false` for `null` metadata.
- Private `write_flag( $vault_path, $set )` → `POST metadata/<path>` with `array( 'custom_metadata' => array( self::ROTATION_FLAG => $set ? '1' : '0' ) )`, returning `true|WP_Error`.
- In `set()`, after the data write and after the `wp_secret_changed` action: `$wanted = (bool) $needs_rotation; $had = flag_is_set( $meta );` (`$meta` is the metadata read at the top; `null` on create). When `$wanted !== $had`: `$flag = write_flag( ... )`. If `is_wp_error( $flag )` and `$wanted`: return `new WP_Error( WP_SECRETS_ERROR_STORE_UNAVAILABLE, sprintf( 'The value was stored but Vault refused to record the rotation flag: %s', $flag->get_error_message() ) )`. If `is_wp_error( $flag )` and `! $wanted`: `error_log( sprintf( 'Vault_KV2_Provider: could not clear %s on %s: %s', self::ROTATION_FLAG, $vault_path, $flag->get_error_message() ) )` and continue to return `true`. A comment above says this is two requests, not a transaction, and points at the docblock paragraph that says the same.
- `list_secrets()`: for each key, `GET metadata/<base><ns>/<key>`; `WP_Error` → return it; `null` (deleted between LIST and GET) → skip; otherwise `created` = `strtotime( preg_replace( '/\.\d+Z$/', 'Z', $meta['created_time'] ) )` cast to int (0 when missing or false), `has_previous` = `null !== previous_version( $meta )`, `needs_rotation` = `flag_is_set( $meta )`. Fingerprint stays `''`. The docblock states the cost: one LIST for namespaces, one LIST per namespace, one GET per secret (question 4).
**Acceptance tests:** added to `Tests_Vault_Provider` (each `set_up()` wipes):
- `test_needs_rotation_round_trips_through_custom_metadata` — `set( name, 'v', false, true )` → `true`; `server->metadata( path )['custom_metadata']['needs_rotation'] === '1'`; `list_secrets()[0]['needs_rotation'] === true`.
- `test_a_set_without_the_flag_clears_it` — set with flag, then `set( name, 'v2' )`; metadata shows `'0'`; listing shows `false`.
- `test_the_flag_is_written_on_create_when_requested` — fresh name, flag requested: exactly one `custom_metadata` POST observed via a recording `pre_http_request` filter that passes every request through by returning `false`. (Recording only; the request still goes to Vault.)
- `test_a_set_with_an_unchanged_flag_makes_no_metadata_write` — set twice without the flag; the recording filter sees no request whose body contains `custom_metadata`.
- `test_a_failed_flag_write_that_was_requested_is_an_error_after_the_value_landed` — install a `pre_http_request` filter that returns a fake 503 `{"errors":["Vault is sealed"]}` only when `'POST' === $args['method']`, the URL contains `/metadata/`, and `$args['body']` contains `custom_metadata`; `set( name, 'v', false, true )` → `WP_Error` with code `WP_SECRETS_ERROR_STORE_UNAVAILABLE` whose message does not contain `v`'s plaintext (use a distinctive canary value); remove the filter; `get( CURRENT )` reveals the canary; the `wp_secret_changed` action was fired once with `created`.
- `test_a_failed_clear_is_logged_without_the_value_and_ignored` — set with flag; `ini_set( 'error_log', <tmp file in get_temp_dir()> )`; same selective 503 filter; `set( name, 'CANARY-clear-9c1d' )` → `true`; the log file contains `could not clear needs_rotation` and does not contain `CANARY-clear-9c1d`; restore `error_log` in `tear_down()`; metadata flag is still `'1'`.
- `test_list_reports_created_and_has_previous` — write `v1`: `created` is within 300 s of `time()` and `has_previous === false`; write `v2`: `has_previous === true`; `retire_previous()`: `has_previous === false`.
- `test_list_omits_a_secret_deleted_between_list_and_metadata_read` — set two names; a `pre_http_request` filter returns a fake 404 for the metadata GET of one of them; `list_secrets()` returns only the other, not `WP_Error`.
**Out of scope:** multisite and sealed-server tests (P4-02); README; any `src/` change.
**Verification:** the examples suite, both passes; `bin/ci-local.sh --keep`; `make reference-check`; `grep -c "'1'" examples/vault-provider/secrets.php` shows the flag string only inside `flag_is_set()` and `write_flag()`.
**Depends on:** P3-01

### P4-02: Multisite isolation, sealed-or-unreachable behaviour, and the timeout measurement
**Goal:** Prove site scope is per blog and network scope is shared, prove a sealed or unreachable Vault is `WP_Error` from every method, and measure the `⚠️ ASSUMPTION` timeout.
**Files touched:** `examples/vault-provider/tests/test-vault-provider-multisite.php` (new), `examples/vault-provider/tests/test-vault-provider.php`, `examples/vault-provider/secrets.php` only if the measurement changes `REQUEST_TIMEOUT` or its comment.
**Design constraints:** Detailed spec, Deliverable 2 ("Site scope is isolated per blog", "A sealed or unreachable Vault reads as `WP_Error`, never `null`"); detailed spec "Errors" (403 and 503 → `WP_SECRETS_ERROR_STORE_UNAVAILABLE`); SPEC §5 (assumption gets measured); SPEC §3 (skip only on the multisite environment gate; three states never collapse). This is the tuning task for `REQUEST_TIMEOUT`.
- Measurement, done once by hand and not committed as a test: with wp-env running, `npx @wordpress/env run --env-cwd=wp-content/plugins/vault-provider tests-cli wp eval '<snippet>'` where the snippet requires `examples/vault-provider/secrets.php`, constructs `new Vault_KV2_Provider( $addr, 'x' )`, times one `get( 'a/b', WP_Secret_Version::CURRENT )` with `microtime( true )`, and prints elapsed seconds and the error code. Run it for (a) `http://127.0.0.1:1` (connection refused, expect well under 1 s), (b) `http://10.255.255.1:8200` (non-routable, expect close to `REQUEST_TIMEOUT`), and (c) record the wall-clock of the examples suite single-site pass from PHPUnit's summary line. Record all three in the commit body under `Measurement:` and in the progress log. Keep `5` unless (b) shows the timeout is not honoured or (c) shows the suite spends most of its time waiting; if you change it, change only the constant and its comment and state the new value in the commit body.
**Acceptance tests:**
- `examples/vault-provider/tests/test-vault-provider-multisite.php`, class `Tests_Vault_Provider_Multisite extends WP_UnitTestCase`: `set_up()` calls `parent::set_up()`, then `if ( ! is_multisite() ) { $this->markTestSkipped( 'Multisite only.' ); }`, then wipes. `tear_down()` calls `restore_current_blog()` guarded by `ms_is_switched()`.
  - `test_site_scope_is_isolated_per_blog` — `set( 'acme/key', 'blog-one' )`; `$blog = self::factory()->blog->create(); switch_to_blog( $blog );` `get( 'acme/key', CURRENT )` is `null`; `set( 'acme/key', 'blog-two' )`; `get()` reveals `blog-two`; `list_secrets()` has exactly one `acme/key`; `restore_current_blog()`; `get()` reveals `blog-one`; `server->metadata( 'wp/site/1/acme/key' )` and `server->metadata( "wp/site/{$blog}/acme/key" )` are both non-null.
  - `test_network_scope_is_shared_across_blogs` — `set( 'acme/key', 'net', true )`; switch to a new blog; `get( 'acme/key', CURRENT, true )` reveals `net`; `server->metadata( 'wp/network/acme/key' )` non-null and no `wp/site/<blog>/acme/key` exists.
  - `test_deleting_on_one_blog_leaves_the_other` — set on blog 1 and blog 2; delete on blog 2; blog 1 still reads.
- added to `Tests_Vault_Provider`:
  - `test_a_sealed_vault_is_an_error_from_every_method` — `pre_http_request` filter returns a fake 503 `{"errors":["Vault is sealed"]}` for every request; `get( CURRENT )`, `get( PREVIOUS )`, `set()`, `delete()`, `retire_previous()`, and `list_secrets()` each return `WP_Error` with code `WP_SECRETS_ERROR_STORE_UNAVAILABLE` and a message containing `Vault is sealed`; none returns `null`, `true`, or an array.
  - `test_an_unreachable_vault_is_an_error_not_absence` — real provider at `http://127.0.0.1:1` with token `x`: `get( CURRENT )` is `WP_Error` (code `WP_SECRETS_ERROR_STORE_UNAVAILABLE`), `list_secrets()` is `WP_Error`, `delete()` is `WP_Error`.
  - `test_a_permission_denied_write_is_an_error_from_set` — provider constructed with the real address and token `not-a-real-token`: `set()` is `WP_Error` with code `WP_SECRETS_ERROR_STORE_UNAVAILABLE` and message containing `permission denied`; `get()` on a name written with the good token is also `WP_Error`, never `null`.
**Out of scope:** README; `src/`; changing any constant other than `REQUEST_TIMEOUT`, and that only if the measurement says so.
**Verification:** the examples suite, both passes (`Tests_Vault_Provider_Multisite` skipped in the single-site pass, green in the multisite pass); `bin/ci-local.sh --keep`; `make reference-check`; the `Measurement:` block is present in the commit body and the progress log.
**Depends on:** P4-01

### P4-03: Push phase 4 and record the manual checks
**Goal:** Push the branch with the provider functionally complete.
**Files touched:** `docs/PROGRESS.md` only.
**Design constraints:** SPEC §8. Never tag, never publish.
**Acceptance tests:** none.
**Out of scope:** any code or docs change.
**Verification:** `git push origin build/vault-provider`; progress log entry reads `Manual check: NOT VERIFIED (human)` and lists: (1) the `examples` job is green on single site and multisite; (2) against a real sealed Vault (`vault operator seal` on a non-dev server), `wp secret get` reports an error rather than absence; (3) `wp secret health` on a real site shows the flagged secret after `wp secret import-option`.
**Depends on:** P4-02

## Phase 5 — AWS Secrets Manager site-scope fix
### P5-01: Map AWS site scope to `wp/site/<blog_id>/<name>` and test it by capturing the request
**Goal:** Fix `AWS_Secrets_Manager_Provider::aws_name()` so every blog on a network has its own AWS secrets, with a README note about the rename and a request-capturing test.
**Files touched:** `examples/aws-secrets-manager/secrets.php`, `examples/aws-secrets-manager/README.md`, `examples/aws-secrets-manager/tests/test-aws-secrets-manager-naming.php` (new), `phpunit-examples.xml.dist`.
**Design constraints:** Detailed spec, Deliverable 3 (its own commit; `wp/site/<blog_id>/<name>`; README note; no compatibility read before 1.0); SPEC §7 (test with `pre_http_request`, no Moto); SPEC §8 phase 5. SPEC §3: single file; no plaintext in messages; do not change anything else about the example (its `'site'` fingerprint scope for network secrets stays, noted in the journal in P6-03, not fixed here). Do not add a conformance class for this example (that is `build/kms-keyring`'s work).
- In `secrets.php`: add private `scope_prefix( $network )` returning `'wp-network/'` or `'wp/site/' . get_current_blog_id() . '/'`; `aws_name()` returns `scope_prefix( $network ) . $name`; `wp_name()` uses the same prefix. Update the docblocks: site scope is per site because the shipped provider's option store is per site, and the old flat `wp/<name>` shape made every blog on a network share one secret. Leave the file header, SigV4 code, and every other method untouched.
- README: rewrite the "Naming" section (`acme/stripe-key` becomes `wp/site/1/acme/stripe-key` on a single site or blog 1; `wp/site/<blog_id>/...` on other blogs; `wp-network/` unchanged), keep the IAM resource `secret:wp/*` note valid (it still matches), and add a short section "Upgrading from an earlier copy of this example": before this change site secrets lived at `wp/<name>`; they now live at `wp/site/1/<name>`; this is a rename on AWS's side (create the new secret from the old value, then delete the old); the example ships no compatibility read before 1.0 and says why in one sentence (a read that fell back to the flat name would silently share secrets across blogs again).
- `phpunit-examples.xml.dist`: add `<directory prefix="test-" suffix=".php">examples/aws-secrets-manager/tests</directory>` to the `examples` testsuite.
**Acceptance tests:** `examples/aws-secrets-manager/tests/test-aws-secrets-manager-naming.php`, class `Tests_AWS_Secrets_Manager_Naming extends WP_UnitTestCase`, offline via `pre_http_request` recording `$url`, `$parsed_args` and returning a fake 200 response (same `fake_response()` shape as the Vault paths test; the AWS file expects a JSON body):
- `test_site_scope_names_include_the_blog_id` — `get( 'acme/key', WP_Secret_Version::CURRENT )` sends a body whose decoded `SecretId` is `wp/site/1/acme/key`, with `X-Amz-Target` `secretsmanager.GetSecretValue`.
- `test_network_scope_names_are_unchanged` — `get( 'acme/key', CURRENT, true )` sends `SecretId` `wp-network/acme/key`.
- `test_set_uses_the_same_site_scoped_name` — fake 200 for `PutSecretValue`; `set( 'acme/key', 'v' )` sends `SecretId` `wp/site/1/acme/key`.
- `test_listing_maps_site_scoped_names_back_and_ignores_the_rest` — fake `ListSecrets` response with `SecretList` names `wp/site/1/acme/key`, `wp/site/2/acme/key`, `wp/acme/legacy`, `wp-network/acme/key`; `list_secrets()` returns exactly `acme/key`; `list_secrets( '', true )` returns exactly `acme/key` too (from the network name).
- `test_the_blog_id_is_read_at_call_time_on_multisite` — skipped with `markTestSkipped( 'Multisite only.' )` when `! is_multisite()`; otherwise create a blog, `switch_to_blog()`, `get()` sends `SecretId` `wp/site/<that id>/acme/key`, `restore_current_blog()`.
**Out of scope:** a compatibility read; the `'site'` fingerprint scope; `list_secrets` pagination; any Vault file; a conformance run against AWS or Moto.
**Verification:** `php -l examples/aws-secrets-manager/secrets.php`; the examples suite, both passes (the new AWS tests run in both); `bin/ci-local.sh --keep`; `make reference-check`.
**Depends on:** P1-01

### P5-02: Push phase 5 and record the manual checks
**Goal:** Push the branch with the AWS fix isolated in its own commit.
**Files touched:** `docs/PROGRESS.md` only.
**Design constraints:** SPEC §8. Never tag, never publish.
**Acceptance tests:** none.
**Out of scope:** any code or docs change.
**Verification:** `git push origin build/vault-provider`; `git log --oneline -3` shows P5-01 as a single commit touching only the AWS example, its README, its tests, and `phpunit-examples.xml.dist`; progress log entry reads `Manual check: NOT VERIFIED (human)` and lists: (1) against live AWS, a secret set on blog 1 appears in the console as `wp/site/1/<name>`; (2) the rename walkthrough in the README works on a throwaway account.
**Depends on:** P5-01

## Phase 6 — Documentation and journal
### P6-01: Write the Vault example README and update the example index, root README, and CI reference
**Goal:** Give the example a README that answers the four questions and tells an operator how to install, configure, and test it, and add it to every index that lists examples.
**Files touched:** `examples/vault-provider/README.md` (new), `examples/README.md`, `README.md`, `docs/reference/ci.md`.
**Design constraints:** Detailed spec "The questions it has to answer", "Out of scope", "Done when"; SPEC §2 (update every page whose statements this work changes) and §3 (additive edits to shared files, confined to your own section; nothing private in docs). Write in the register of `examples/aws-secrets-manager/README.md`: plain, specific, second person where it addresses the operator. `docs/reference/ci.md` is hand-written (not generated by `bin/gen-reference.php`), so editing it is allowed.
- `examples/vault-provider/README.md` sections, in order: title and one-paragraph summary (`wp secret dropin` reports the provider boundary); **Where the credentials go** (`.wp-env.override.json` example with `WP_SECRETS_VAULT_ADDR`, `WP_SECRETS_VAULT_TOKEN`, `WP_SECRETS_VAULT_MOUNT`, `WP_SECRETS_VAULT_NAMESPACE`; from inside wp-env the dev server is `http://host.docker.internal:8201`); **Install the drop-in** (the same `docker cp` / `wp secret dropin` / removal loop as the AWS README, with the expected `wp secret dropin` output including `Protected by: HashiCorp Vault (http://..., mount secret)` and the same gotcha about PHPUnit); **Vault policy** (the smallest policy: `create`, `update`, `read`, `delete`, `list` on `secret/data/wp/*`, `secret/metadata/wp/*`, `secret/destroy/wp/*`; note that `is_writable()` returns `true` regardless and a token without write policy surfaces as `WP_Error` on `set()`); **Naming** (the path table from the detailed spec); **The four questions** with a subsection each: 1. what "previous" is (strictly N-1; retiring destroys N-1 and never promotes N-2; the test that proves it, `test_previous_is_strictly_n_minus_1_even_when_older_versions_survive`); 2. the versions the API cannot see (`max_versions: 2` on create makes Vault a two-slot store; a secret created outside the provider keeps its own `max_versions`, so pre-existing Vault secrets can still hold versions WordPress cannot see; if this turns out wrong in practice it is a finding about the version model for the Trac ticket; link ADR 0009); 3. where `needs_rotation` lives (`custom_metadata.needs_rotation = "1"`, cleared to `"0"`, Vault 1.9+, two requests not a transaction, the failure rule); 4. what `list_secrets()` costs (one LIST for namespaces, one LIST per namespace, one metadata GET per secret; no data reads; fingerprints blank as in the AWS example); **Known limits** (static token only, with AppRole and Kubernetes auth named as the production path; no `cas`, one sentence on it as the answer to concurrent writers; KV v1 and dynamic engines out of scope; request-scoped caching only; fingerprints still need the site's own root key even though the boundary is the provider, as a question rather than a promise; `wp_secret_changed` carries blank fingerprints); **OpenBao** (implements the same KV v2 API; CI tests Vault, the name hosts search for, and one manual OpenBao run is recorded in the P6-04 progress entry as a human check); **Run the tests** (the `docker run` line with the pinned digest, the two wp-env commands, `make test-examples` for a host with its own test suite, and what `VAULT_ADDR`/`VAULT_TOKEN` do).
- `examples/README.md`: add a row `| **HashiCorp Vault KV v2** | secrets | \`WP_Secrets_Provider\` | 8 methods |` to the table, and a new section `## In this directory` (placed after "Which interface do you need?") listing `aws-secrets-manager/` and `vault-provider/` with one line each. Do not edit any other sentence, including the KMS advice and the "Dependencies" section.
- `README.md`: in "Platform bindings", add one sentence naming the two shipped examples (AWS Secrets Manager, HashiCorp Vault KV v2) and that `make test-examples` runs them against live services; in "Contributing", extend the CI sentence with "plus an `examples` job that runs the platform bindings against a Vault service container". No other change.
- `docs/reference/ci.md`: add a row to the Matrix table: `| \`examples\` | 8.3 | latest | \`make test-examples\` against a Vault dev-mode service container, single site and multisite. Outside \`make ci\` because it needs the container. |`, and one sentence under "Where this runs" saying the same. No other change.
**Acceptance tests:** none executable; `bin/ci-local.sh --keep` still passes (docs are not linted). Reviewer reads the README against the detailed spec's four questions.
**Out of scope:** spec pages, ADR, journal (P6-02, P6-03); any code.
**Verification:** every relative link in the new README resolves (`grep -o '](\.\./[^)]*)' examples/vault-provider/README.md` and check each path exists); the digest in the README equals the one in `Makefile` and `ci.yml`; `bin/ci-local.sh --keep`; `make reference-check`.
**Depends on:** P4-02, P5-01

### P6-02: Add ADR 0009 and update the spec pages' "As built" sections
**Goal:** Record the two-slot cap as a decision and bring the four affected spec pages in line with what the example showed.
**Files touched:** `docs/decisions/0009-cap-a-many-version-backend-to-two-slots.md` (new), `docs/spec/versioning.md`, `docs/spec/rotation.md`, `docs/spec/providers-and-keyrings.md`, `docs/spec/extension-points.md`.
**Design constraints:** SPEC §3 "ADRs" (next number after 0008, same table format and sections as `docs/decisions/0008-...md`: frontmatter `title` and `description`, then Number/Date/Status table, Context, Decision, Consequences; expect renumbering at merge) and "Spec pages" (exactly three sections in order; only "As built" changes here because the code still matches the proposal, so "Why" gets nothing new). Nothing private. Date the ADR the day it is written.
- ADR 0009 title: "Cap a many-version backend to two slots". Context: KV v2 keeps up to 10 versions; the API exposes two; versions WordPress cannot see stay readable to any Vault token; "previous" needs a definition on a backend with more than two versions. Decision: the provider sets `max_versions: 2` on every secret it creates; `PREVIOUS` is strictly version N-1 and `null` when N-1 is missing, soft-deleted, or destroyed; retirement destroys rather than soft-deletes. Consequences: a secret created outside the provider keeps its own policy and may hold hidden versions; retiring can leave no previous version at all, by design; the interface docblock does not yet say what "previous" means, which goes to the Trac ticket via open-questions.md; if two slots prove wrong in practice this is the record to amend. Link the detailed spec, the README, and ADR 0008.
- `docs/spec/versioning.md` "As built": append a paragraph `**A backend with more than two versions.**` describing the Vault example's translation (strict N-1, `max_versions: 2`, destroy on retire), pointing at `examples/vault-provider/secrets.php`, `previous_version()`, and ADR 0009.
- `docs/spec/rotation.md` "As built", "Retiring the previous value": append one sentence that the Vault example implements `retire_previous()` as a destroy of exactly version N-1, since a soft-deleted version can be undeleted.
- `docs/spec/providers-and-keyrings.md` "As built", "Supporting surface": append a sentence that two provider examples exist, `examples/aws-secrets-manager/` and `examples/vault-provider/`, and that `make test-examples` runs the conformance suite against the Vault one on a real server.
- `docs/spec/extension-points.md` "As built", the conformance-suite paragraph: append a sentence that the suite also runs against the Vault provider example on a real dev server in `make test-examples`, so there is a second known-good subject whose backend does not share the two-slot shape.
**Acceptance tests:** none executable. Reviewer checks each spec page still has exactly `## As proposed`, `## As built`, `## Why` in that order (`grep -n '^## ' docs/spec/<file>.md`).
**Out of scope:** `docs/index.md` (P6-03); journal pages; "Why" sections; any code.
**Verification:** `grep -c '^## ' docs/spec/versioning.md docs/spec/rotation.md docs/spec/providers-and-keyrings.md docs/spec/extension-points.md` reports 3 each; `ls docs/decisions/` shows 0009 as the only new file; `bin/ci-local.sh --keep`; `make reference-check`.
**Depends on:** P4-02

### P6-03: Update the journal tracking pages, write the journal entry, and index both
**Goal:** Record what the Vault example found in the three tracking pages, write the one dev journal entry, and list the new pages in `docs/index.md`.
**Files touched:** `docs/journal/open-questions.md`, `docs/journal/test-coverage-gaps.md`, `docs/journal/proposal-questions.md`, `docs/journal/YYYY-MM-DD-a-vault-provider.md` (new, dated today), `docs/index.md`.
**Design constraints:** SPEC §2 (one entry; frontmatter `title`, `description`, `date`; voice of `docs/journal/2026-09-04-0-1-0-is-public.md`: first person, plain, specific; cover what was built, what it found, what was left out, what it means for the Trac patch; link the example or a test and ADR 0008; do not use the `/journal-entry` skill and do not touch `docs/journal/_drafts/`). SPEC §3 "Parallel flights": additive edits to the tracking pages and `docs/index.md`, confined to your own paragraphs; do not reword any existing sentence, in particular the sentence in open-questions.md about a KMS keyring having no example, which `build/kms-keyring` will edit. SPEC §9 second bullet: interface-level findings are recorded here, not changed in `src/`. Nothing private.
- `open-questions.md`: under "Host and platform providers", append a paragraph beginning `**What the Vault example added:**` (a second provider, this time against a backend whose versioning does not match; the conformance suite now runs against it automatically in `make test-examples`; what it found). Then add two new sections before "Testability smells": `## What "previous" means on a backend with more than two versions` marked 🟡 (conservative choice: strictly N-1, in `Vault_KV2_Provider::previous_version()`; the interface docblock for `get()` and `retire_previous()` does not define it; resolution belongs on the Trac ticket description as a docblock clarification) and `## A provider outside the WordPress boundary still needs a root key` marked 🟢 (fingerprints derive from the site master key, so a `BOUNDARY_PROVIDER` provider still depends on a working keyring for one feature; inherited from the AWS example; where the code is).
- `test-coverage-gaps.md`: append a section `## 🟢 The Vault example's failure paths are simulated` (the sealed 503 and the failed flag write are produced with `pre_http_request`, not a real sealed server; the unreachable case is a real refused connection; OpenBao is not in CI, one manual run is a human check; only the pinned Vault digest is tested).
- `proposal-questions.md`, question 2: append two or three sentences: the Vault example needed a translation rather than a match; `max_versions: 2` on create made it a two-slot store and the conformance suite passed unchanged; the one thing the model did not define was what "previous" means when more than two versions exist, and the strict N-1 answer is recorded in open-questions.md for the Trac ticket. Do not alter the AWS sentence or the paragraph about silence.
- Journal entry `docs/journal/<today>-a-vault-provider.md`, title `A Vault provider`, sections of your choosing but covering: what was built (the provider, the harness half, the CI job, the AWS fix); what it found (the AWS site-scope bug; that the conformance suite passed without changes; that the interface leaves "previous" undefined; that a provider outside the boundary still needs local key material; the AWS example's `'site'` fingerprint scope for network secrets as a small inconsistency left alone); what was left out (auth methods, `cas`, KV v1, a compatibility read for the AWS rename, any `src/` change); what it means for the Trac patch (two items for the ticket description, no signature change). Link `examples/vault-provider/README.md`, one test by name, and ADR 0008 and ADR 0009.
- `docs/index.md`: add the ADR 0009 line to "decisions/" and the journal entry line to "journal/", matching the existing line format.
**Acceptance tests:** none executable. Reviewer checks frontmatter (`head -5` of the entry shows `title`, `description`, `date` with today's date) and that no existing sentence in the three tracking pages changed (`git diff --word-diff main..HEAD -- docs/journal/open-questions.md docs/journal/test-coverage-gaps.md docs/journal/proposal-questions.md` shows only additions).
**Out of scope:** `_drafts/`; any spec page; any code; a changelog entry (no release).
**Verification:** `git diff main..HEAD --stat -- docs/journal` shows three modified files and one new file; `grep -c 'a-vault-provider' docs/index.md` is 1; `bin/ci-local.sh --keep`; `make reference-check`.
**Depends on:** P6-02

### P6-04: Push phase 6, remove the Vault container, and record the manual checks
**Goal:** Push the finished branch, clean up the local service container, and record every check that needs a human.
**Files touched:** `docs/PROGRESS.md` only.
**Design constraints:** SPEC §7 (remove the container when the flight's work is done); SPEC §8 (phase 6's manual check is an OpenBao run); SPEC §3 (never tag, never publish, never run `sf publish`).
**Acceptance tests:** none.
**Out of scope:** any code or docs change; a release; a tag; publishing the site.
**Verification:** `git push origin build/vault-provider`; `docker rm -f secrets-api-vault` then `docker ps -a | grep -c secrets-api-vault` is 0; progress log entry reads `Manual check: NOT VERIFIED (human)` and lists: (1) an OpenBao run: start `openbao/openbao` in dev mode on another port, point `VAULT_ADDR` at it, run the examples suite, and record the result; (2) the `examples` job is green on GitHub Actions; (3) `npm run docs:build` in `site/` renders the new README-linked pages, the ADR, and the journal entry with the sidebar sorted by date; (4) a reviewer has read `examples/vault-provider/README.md` against the four questions.
**Depends on:** P6-01, P6-03

## Spec issues
- SPEC §8 phase 2 requires "the conformance suite green" while `retire_previous()` is phase 3 and `list_secrets()` is phase 4, and the suite exercises both. Resolved by landing every method in its simplest correct form in P2-02 and completing semantics and tests in P3-01 and P4-01. No test is stubbed or skipped.
- SPEC §7 permits `extraVerify` only for existing make targets, but `make test-examples` runs PHPUnit on the host and this machine's WordPress test suite lives only inside wp-env, so the target cannot pass locally. No `extraVerify` is set; each task's Verification runs the wp-env command instead. The reviewer must run it by hand.
- The detailed spec says "a set without the flag clears it" without saying how. Vault replaces `custom_metadata` wholesale and rejects `[]`, so clearing writes `"0"` and the flag reads as set only for `"1"`. Recorded under Decisions and in the README.
- The detailed spec writes `LIST metadata/...`. WordPress's HTTP API is not guaranteed to pass a custom `LIST` verb through every transport, so the provider uses `GET ...?list=true`, which Vault documents as equivalent.
- The detailed spec does not say which Vault version or tag to pull. P1-01 pulls `hashicorp/vault:latest` once and pins the digest it gets; the version is recorded in the commit body.
- The detailed spec does not say what `wp_secret_changed` should carry for fingerprints from this provider. Blank strings, as in the AWS example, keep the example free of an extra read; noted as a known limit.
- The AWS example fingerprints network secrets under the `'site'` master key while the shipped provider uses `'network'`. Pre-existing, outside Deliverable 3, and left alone; the Vault provider follows the shipped provider and the journal entry notes the difference.
- `examples/README.md`'s "Dependencies" section says each binding has its own `composer.json`; neither shipped example does. Already stale before this flight, and `build/kms-keyring` may touch that page too, so it is left alone here.
- CI runs on `push` to `main` and on `pull_request`. A push to `build/vault-provider` alone does not trigger the workflow; the draft PR Foundry opens does. The phase-end manual checks say "for this branch's draft PR" for that reason.
- `WP_Secrets_Provider::set()` says a provider "must not report [`needs_rotation`] as honored". Between P2-02 and P4-01 the provider accepts the argument without writing it. That window is inside one branch and closed by P4-01; it is called out in P2-02's task text so the reviewer does not read it as a defect.
- No file under `src/`, `plugin/`, or `cli/` changes, so `docs/reference/` needs no regeneration. `make reference-check` stays in `verify` to prove it.

## Review fixes (round 1)

### R1-01: Preserve other custom_metadata keys when writing the rotation flag, and tighten the Vault provider's docblocks and unreachable test
**Goal:** write_flag() merges the secret's existing custom_metadata with the needs_rotation key instead of replacing the whole map, the docblocks state what Vault actually does, no Foundry task IDs remain in the drop-in, and the unreachable-Vault test asserts the error code.
**Files touched:** examples/vault-provider/secrets.php, examples/vault-provider/tests/test-vault-provider.php
**Design constraints:** Detailed spec, needs_rotation paragraph: stored as custom_metadata.needs_rotation = "1", cleared by a set without the flag; the flag-failure rule is unchanged. A POST to metadata/<path> replaces custom_metadata wholesale, as confirmed against the pinned Vault 2.1.1: seeding {owner:ops,needs_rotation:1} and then posting {needs_rotation:0} leaves only needs_rotation. Vault accepts an empty map, so the existing docblock claim that it 'rejects an empty map' is false. Change write_flag( $vault_path, $set ) to take the metadata set() already read, e.g. write_flag( $vault_path, $set, $meta ). Post array_merge( existing custom_metadata when it is an array, else array(), array( self::ROTATION_FLAG => $set ? '1' : '0' ) ). Keep writing "0" to clear, and keep flag_is_set() reading exactly "1". Rewrite the write_flag() docblock to say that the merge preserves keys other tools set and that reading then writing is not atomic (the same two-requests-not-a-transaction caveat as the file header). Do not add a PATCH request. In the same file, replace every Foundry task-ID reference in docblocks (lines ~48 'Measured in P4-02', ~151 '(P4-01)', ~269 'Completed in P2-02.', ~309 'Completed in P2-02 and P4-01.', ~534 'Isolated so P4-01's ...') with plain statements of behaviour. REQUEST_TIMEOUT's comment should state the measurement: refused connection about 0.005 s, non-routable address about 4 s. Change line 18's '../README.md' to 'README.md' (the README beside the file). No new literal 'max_versions' or 'timeout' numbers; no plaintext in any message. Tests only get stronger.
**Acceptance tests:** Add to Tests_Vault_Provider: test_setting_and_clearing_the_flag_preserves_other_custom_metadata. set( 'acme/key', 'v1' ). Then $this->server->request( 'POST', 'secret/metadata/wp/site/1/acme/key', array( 'custom_metadata' => array( 'owner' => 'ops' ) ) ). Then set( 'acme/key', 'v2', false, true ): metadata custom_metadata has owner === 'ops' and needs_rotation === '1'. Then set( 'acme/key', 'v3' ): owner === 'ops' and needs_rotation === '0', and max_versions is still Vault_KV2_Provider::MAX_VERSIONS. This fails on the current code, where owner disappears. Strengthen test_an_unreachable_vault_is_an_error_not_absence to also assertSame( WP_SECRETS_ERROR_STORE_UNAVAILABLE, ...->get_error_code() ) for get( CURRENT ), list_secrets(), and delete(). All existing tests stay unchanged and green.
**Out of scope:** README and journal wording (R1-03); the test helper's error handling (R1-02); any src/, plugin/, cli/ change; a PATCH-based metadata write; cas.
**Verification:** php -l examples/vault-provider/secrets.php. grep -nE '[PR][0-9]-[0-9][0-9]' examples/vault-provider/secrets.php is empty. Start the pinned Vault dev container and run the examples suite, both passes, inside wp-env (single site and WP_MULTISITE=1), with the new test green. bin/ci-local.sh --keep. make reference-check.
**Depends on:** none

### R1-02: Make Vault_Test_Server fail loudly when Vault is unreachable instead of reporting absence
**Goal:** The test helper never turns a transport failure or unexpected status into 'absent' or 'wiped'. It fails the running test with the URL and error, so a flaky connection fails where it happens, and negative assertions cannot pass when Vault was never reached.
**Files touched:** examples/vault-provider/tests/includes/class-vault-test-server.php, examples/vault-provider/tests/test-vault-harness.php
**Design constraints:** CLAUDE.md: three states never collapse; tests only get stronger. request() currently maps a WP_Error from wp_remote_request() to code 0 / body null. metadata() then returns null (the same as 404), list_keys() returns array(), and wipe() ignores every LIST and DELETE result. Change it so that request() calls PHPUnit\Framework\Assert::fail() with the method, URL, and transport error message when wp_remote_request() returns WP_Error. metadata() returns null only on 404 and data on 200, and fails on anything else. list_keys() returns array() only on 404 and keys on 200, and fails otherwise. wipe_recursive() fails if a DELETE does not return 204. read_version(), create_metadata(), soft_delete_versions(), and request()'s return shape for non-failure codes stay as they are, so no existing test changes. Add an optional constructor argument $addr = null that, when non-null, overrides VAULT_ADDR (the token still comes from the env). Existing callers pass nothing. Test-helper code may use PHPUnit assertions; nothing here touches examples/*/secrets.php.
**Acceptance tests:** Add to Tests_Vault_Harness: test_the_helper_fails_loudly_when_vault_is_unreachable. $helper = new Vault_Test_Server( 'http://127.0.0.1:1' ). expectException( PHPUnit\Framework\AssertionFailedError::class ), then $helper->metadata( 'wp/site/1/acme/key' ). A second test, test_wipe_fails_loudly_when_vault_is_unreachable, does the same with $helper->wipe(). Both fail on the current code, where metadata() returns null and wipe() returns silently. Every existing examples test stays green against a reachable server.
**Out of scope:** Retries or longer timeouts; the provider itself (R1-01); any CI change; skipping tests when Vault is down (the plan says the suite fails, never skips).
**Verification:** Start the pinned Vault dev container and run the examples suite, both passes, inside wp-env, with the two new harness tests green. bin/ci-local.sh --keep. make reference-check.
**Depends on:** none

### R1-03: Correct the Vault README, tracking page, root README, Makefile and ci.yml comments, and guard against leaked task IDs
**Goal:** Every published statement about the Vault example matches the code and works on any checkout. No Foundry task ID or PROGRESS.md reference remains in a shipped file, and a constraint keeps it that way.
**Files touched:** examples/vault-provider/README.md, docs/journal/test-coverage-gaps.md, README.md, Makefile, docs/foundry.json, .github/workflows/ci.yml
**Design constraints:** Additive or in-place edits confined to this flight's own sentences and sections in shared files (SPEC §3 'Parallel flights'). Do not touch any other job in ci.yml or any other Makefile target, and change comments only. Nothing private in docs. Items: (a) README 'Run the tests' and the Makefile comment above test-examples use --env-cwd="wp-content/plugins/$(basename "$PWD")" instead of the worktree-specific wp-content/plugins/vault-provider, and the README says to run them from the repository root. (b) README question 1 describes test_previous_is_strictly_n_minus_1_even_when_older_versions_survive accurately: max_versions is raised to 10 through the helper so pruning cannot be the cause, three versions are written, retire_previous() runs through the provider, PREVIOUS is null, and version 1 still reads 200. (c) README question 3 drops the false claim that Vault rejects an empty map and says the flag write merges the existing custom_metadata so other keys survive, matching R1-01's code. (d) README OpenBao section and test-coverage-gaps.md's Vault section no longer point at 'the phase-6 progress entry'. Say instead that one manual OpenBao run is a human check whose result is recorded in a commit message, as the detailed spec says. (e) test-coverage-gaps.md: the unreachable test uses a closed local port (http://127.0.0.1:1, a real refused connection), not a non-routable address. Also remove the doubled blank line before that section's '---'. (f) README.md Platform bindings sentence: make test-examples runs the Vault example against a live Vault dev server and the AWS naming tests offline through pre_http_request, not 'both against live services'. (g) Remove '(pinned digest, from P1-01)' from the Makefile comment and '(P1-01)' from the ci.yml comment, keeping the rest of each comment's meaning. (h) Add a docs/foundry.json constraint 'no-foundry-task-ids-in-shipped-files' with pattern [PR][0-9]+-[0-9]{2}\b. paths: examples/, Makefile, .github/, README.md, docs/journal/, docs/decisions/, docs/spec/, docs/reference/, src/, plugin/, cli/, tests/, bin/. shouldMatch includes the missed lines verbatim: '# Local Vault dev server for the vault-provider example (pinned digest, from P1-01):' and '	 * un-deleted. Completed in P2-02.'. shouldNotMatch includes 'hashicorp/vault@sha256:47f14a6acb98f48d798a07df7c83f23a6e636e1cf724c5f8ff165cb32667a1e2' and 'PHP 7.4-8.3'. Keep baseBranch, branchPrefix, permissionMode, and the verify commands exactly as they are.
**Acceptance tests:** The new constraint is the mechanical test for the task-ID items. foundry_verify must report it ok with no fixture failure and no hits (it would have hit Makefile:59, ci.yml:193, and secrets.php before R1-01). Check by hand: grep -n 'plugins/vault-provider' examples/vault-provider/README.md Makefile is empty; grep -rn 'progress entry' examples docs/journal is empty; grep -n 'rejects an empty map' examples/vault-provider/README.md is empty.
**Out of scope:** Any code under examples/*/secrets.php (R1-01); ADR 0009 and spec pages (they are accurate); any other flight's sections of the shared files; publishing the site.
**Verification:** foundry_verify (constraints including the new one, bin/ci-local.sh --keep, make reference-check). The digest in README, Makefile, and ci.yml is still identical. Every relative link in examples/vault-provider/README.md still resolves.
**Depends on:** R1-01
