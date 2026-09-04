# Open questions

Things this implementation deliberately did **not** decide. Each entry states the conservative
choice that was made, where the seam is, and who needs to resolve it.

**This file holds only what is still open.** Closed questions are deleted rather than archived —
the reasoning for a decision belongs next to the code that implements it, where someone reading
that code will actually find it, and a file of answered questions makes the unanswered ones harder
to see. Entries are referenced by heading rather than by number, so removing one never dangles a
reference elsewhere.

Status legend: 🔴 blocks a release · 🟡 needs an answer before the core patch · 🟢 tracking only

---

## 🟢 Host and platform providers — implemented, awaiting real implementations

Three platforms independently reported that the published extension contract could not express
what they need: Chris Reynolds (Pantheon), Ryan McCue and Rafael Meneses (Altis), and the VIP
dashboard/HSM model this project wants to support directly. Rafael Meneses supplied the reframe
that resolved it:

> A provider can be stronger than the default, never weaker. Plaintext storage stays banned… and
> the setups Ryan and Chris describe stop being banned with it.

**Done.** `WP_Secrets_Provider` is the outermost extension point and the public functions route
through it. `WP_Secrets_Libsodium_Provider` — the shipped default — is one implementation of that
interface rather than a privileged case, composed from a `WP_Secrets_Store` and a
`WP_Secrets_Keyring` so that a host wanting only their own key custody still swaps only the
keyring. A drop-in installs a platform provider by setting `$GLOBALS['wp_secrets_provider']`.
`supports()` is gone, replaced by `get_label()`, `get_protection_boundary()`, and `is_writable()`.
`reveal()` returns `string|WP_Error` and `WP_Secret::withheld()` exists for credentials a provider
will not release to PHP. Site Health and `wp secret dropin` report all of it.

**What has not happened:** nobody has written a real platform provider against this yet. The
interface is shaped by three descriptions of what hosts need, not by three working
implementations, and the first real one will find something. That is the useful next feedback to
seek in the comments thread — not "does this look right" but "build against it and tell us what
broke."

See [`host-provider-model.md`](host-provider-model.md) for the reasoning, including why a provider
declaration is documentation rather than enforcement.

---

## 🟢 Access control language — decided, keep the docs honest

**Namespacing is organisational, not access control, and was never intended as
anything else.** It groups secrets by owner so listings and a future admin screen can be sensible.
It is not a visibility or privilege boundary.

This matters because the prior proof-of-concept's README described namespace-based access control
in a way that led people to believe one plugin's secret was inaccessible to another. Darin Kotter
raised it in the comments. It was not true then, it is not true now, and the docs must not drift
back toward implying it.

**The phrasing to keep using:** there is no per-plugin isolation. Any plugin that can run PHP can
read any secret. Masking is hygiene against shoulder-surfing and accidental logging, not a
privilege boundary.

Tracking-only now: applied in `README.md`, in `wp_secrets_validate_name()`'s docblock, and in the
`_doing_it_wrong()` message an unnamespaced name produces. Anything new that describes namespaces
should match.

---

## 🟡 Record format version bump policy

`'v' => 1` exists so a future format change is detectable rather than presenting as a decryption
failure.

**Decided: v2 will not be read-compatible with v1.** The upgrade path is therefore either a
migration pass over existing records, or a version-switched read path that keeps v1 handling
alongside v2. Which of the two is a decision for when v2 actually exists — they differ mainly in
whether sites take a one-time cost or the code carries two decoders indefinitely.

What that rules out, usefully: nobody should design v2 assuming a v1 reader can make sense of it,
and no future change should quietly widen v1's shape rather than bumping the version.

Still true and worth keeping in view when that day comes: `v` sits outside the AAD, so it is
unauthenticated metadata. It must be treated as a routing hint validated *before* decryption, and
an unknown `v` must be rejected outright rather than attempted. Today it is:
`secret_record_unsupported_version` is returned rather than guessed at, and that is tested.

---

## 🟢 The five questions the proposal asked the community

These have a home here so answers from the comments thread land somewhere rather than being
absorbed into an assumption.

1. **Is the no-filter decision on retrieval sufficient, with providers as the substitution?**
   — **Answered, and the answer was "not as published."** Three hosts independently found the
   provider contract could not express their deployments. The no-filter decision itself was never
   challenged; what failed was the contract's shape. See "Host and platform providers" above.
2. **Are two version slots (`CURRENT`/`PREVIOUS`) adequate, or is a different rotation pattern
   necessary?** — no answers recorded yet. `'v' => 1` leaves room to change this, but see "Record
   format version bump policy".
3. **Does `wp_import_option_as_secret()` fit actual plugin migration workflows?**
   — no answers recorded yet.
4. **Which WP-CLI commands most need this surface, and in what priority order?** — the command set
   implemented here is a starting set, not a settled one. Track real answers rather than assuming.
5. **For hosts running secret stores or key backends: what is missing from the drop-in surface?**
   — answered at length by Pantheon and Altis; see "Host and platform providers".

---

## 🟢 `sodium_compat` is never exercised by the test suite

Core's bundled `sodium_compat` (`polyfill-1.0.8`) does implement
`sodium_crypto_kdf_derive_from_key()` — verified against the source in WordPress trunk, in
`lib/php72compat.php` behind an `is_callable()` guard, backed by a real implementation in
`ParagonIE_Sodium_Compat::crypto_kdf_derive_from_key()` rather than a "not implemented" stub. That
was the original worry and it is settled.

What remains is that **nothing in CI ever runs that path.** Every environment the suite runs in —
wp-env locally, `shivammathur/setup-php` in CI — has the libsodium extension loaded, so the
polyfill is never reached. A regression there, or a core downgrade of the bundled polyfill, would
be invisible to this project's tests while breaking exactly the hosts the fallback exists for.

Worth a CI leg with the extension disabled if that turns out to be arrangeable; `setup-php` can
build without it, but the WordPress test suite's own requirements have not been checked against
that.

Related and unchanged: `sodium_memzero()` really is a no-op under `sodium_compat`, because a
userland polyfill cannot reach a PHP string's memory. `wp_secrets_memzero()`'s docblock says so
rather than overclaiming.

---

## 🟢 Community requests not in scope

- **Two Factor plugin integration** (Brian Haas). Reasonable, out of scope for the API itself.
  Worth a note about whether the Two Factor plugin should be an early consumer.
- **Iterating UX/DX inside the AI plugin's Key Encryption experiment before core merge**
  (Jeffrey Paul). This plugin touches the AI plugin only through the prototype-format upgrade
  path. Whether the two efforts should coordinate is a project question.

---

## 🟢 Testability smells

Per the build brief: if something is hard to test, that is usually a design smell, and it gets
written down here rather than skipped.

- `var_export()` of a `WP_Secret` cannot be masked from userland — it ignores `__debugInfo()` and
  `__toString()` and emits private properties directly. Mitigated by not storing the plaintext as
  an object property at all. Documented as a known limitation regardless.
- The `options.php` all-settings screen reads the options table directly with no filter, so a
  plugin cannot exclude secrets from it. Surfaced as a Site Health warning and documented as a
  core-patch-only fix. There is an existing core ticket and pull request on plaintext display in
  `options.php` to reference.

---

## 🟢 Drop-in file loading is not directly covered by an automated test

`wp_secrets_api_load_dropin()` (in `secrets-api.php`) runs once, during
`wp_secrets_api_bootstrap()`, which itself runs once per PHP process via `muplugins_loaded`. Both
that function and `_wp_secrets_get_store()` / `_wp_secrets_get_key_manager()` cache their result
in a function-local `static` on first call, with no reset hook. By the time any test method's body
runs — even in a `@runInSeparateProcess` test — the process's one bootstrap pass has already
completed, so a drop-in file placed on disk from within a test body arrives too late to affect
that process's `wp_secrets_api_load_dropin()` call.

**What is covered:** the consumption side — `_wp_secrets_get_store()` and
`_wp_secrets_get_key_manager()` correctly using `$GLOBALS['wp_secrets_store']` /
`$GLOBALS['wp_secrets_keyring']`, and falling back to `WP_Secrets_Broken_Store` /
`WP_Secrets_Broken_Keyring` when `$GLOBALS['wp_secrets_dropin_broken']` is set — is tested
directly in `tests/phpunit/test-secrets-extension-points.php` by setting those same globals in an
isolated process, exactly as a real drop-in would, before the first call that would cache a
default.

**What is not covered:** `wp_secrets_api_load_dropin()`'s own `require`-and-`try`/`catch` around
an actual drop-in file. That behaviour was verified empirically instead, once, directly against
the PHP engine on both 7.4.33 and 8.5.7:

- A syntax error in the required file *is* caught as a `ParseError` by `catch ( \Throwable $e )`
  around the `require` — confirmed on both versions.
- A class that `implements` an interface but omits a required method is an **uncatchable fatal
  error**, even inside that same `try`/`catch` — also confirmed on both versions. This is a
  PHP-engine limitation, not a bug in the catching code: there is no userland way to intercept it.

So a malformed drop-in fails safely for syntax errors and thrown exceptions, but a drop-in whose
class silently fails to fully implement its interface can still produce a fatal error page. Worth
another look if a process-spawning integration test is judged worth the complexity later.

---

## 🟡 CLI dispatch is not covered by any test

Every WP-CLI test in this suite instantiates the command class and calls the method directly. That
covers the method bodies well and covers **nothing** about how WP-CLI actually reaches them, which
is a layer with its own rules: flag-name reservations, docblock synopsis parsing, and method-name
to subcommand-name mapping.

Three real bugs lived there undetected until the commands were run for the first time on
2026-09-04, all with green tests throughout:

- **`--version=previous` silently returned the current value.** WP-CLI consumes `--version` before
  a subcommand sees it; the synopsis default then filled in `current`. The flag is now `--slot`.
  This is the worst of the three: no error, just the wrong secret.
- **`--format` was rejected as an unknown parameter** on all four commands that declare it. WP-CLI
  will not register a parameter whose synopsis block has no `: description` line, and every
  `[--format=<format>]` went straight into its `---` YAML.
- **`wp secret migrate-legacy` did not exist**, despite every document saying it did. WP-CLI
  derives subcommand names from method names, so `migrate_legacy()` registered as
  `migrate_legacy`. Fixed with `@subcommand`.

**What would catch this:** a smoke test that shells out to a real `wp` binary against a real
install and asserts on exit codes and output — the WordPress test suite cannot do this, and
wp-env can. Not built. Until it is, treat any change to a command's docblock synopsis or method
name as untested, and run it by hand.

Cheap interim discipline: `wp help secret <subcommand>` shows the synopsis WP-CLI actually built.
If a flag is missing there, it is missing everywhere.

---

## 🟢 `wp secret set --stdin`'s own code path is not covered by an automated test

`WP_CLI_Secret_Command::set()` reads `--stdin` via `file_get_contents( 'php://stdin' )`. Faking
that stream meaningfully from inside a PHPUnit process would need a real pipe (`proc_open`), and
getting it wrong risks hanging the whole test run waiting on a stream nothing is writing to — not
worth it for one branch. Every other branch of `set()` (positional value, missing value, the
shell-history warning, success/porcelain/error reporting) is covered directly.

---

## 🟢 `make coverage`'s numbers cannot be trusted inside wp-env

`make coverage` runs and produces a report, but the percentages it reports from the wp-env
container are not reliable and should not be read as a statement about test thoroughness. This is
a tooling gap, not a test-coverage gap.

**What was verified before writing this down:** the wp-env tests-cli image ships no coverage
driver. Installing `pcov` via `pecl` works and produces a report, but every class in `plugin/` and
`cli/` reports exactly 0% line and method coverage, while every class in `src/` reports
partial-to-full coverage in the same run — despite dozens of passing, assertion-bearing,
non-isolated tests calling methods on the "0%" classes directly.

Three explanations were checked and ruled out rather than assumed: not a symlink/realpath mismatch
(both resolve identically inside the container); not `@runInSeparateProcess` coverage failing to
merge (the affected tests use no isolation); not pcov's initial table sizing (raised 8×, no
change — though that also revealed `--filter` degrading collection further, a second symptom of
the same unexplained cause). What is not known is why the split falls exactly along the
`plugin/`+`cli/` vs `src/` boundary when both are required through the same bootstrap.

Left as-is: no coverage threshold gates anything in `make ci`. Trustworthy numbers, if wanted,
should come from the non-Docker path against a host PHP with a coverage driver installed normally,
not via a `pecl install` into an already-running container.
