---
title: "Test coverage gaps"
description: "Code paths the automated suite does not reach, why, and what was verified by hand instead."
date: 2026-09-04
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

Three real bugs lived there undetected until the commands were run end to end for the first
time, all with green tests throughout:

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


---

## 🟢 The Vault example's failure paths are simulated

`Tests_Vault_Provider`'s sealed-Vault (503) and failed-flag-write cases are produced with
`pre_http_request`, not a real sealed server — sealing and unsealing a Vault dev container inside
the test run was judged not worth the added CI time. The unreachable case is real: the test points
the provider at a non-routable address and a real connection is refused or times out. OpenBao is
not run in CI at all; the README says it implements the same KV v2 API, and one manual run against
it is a human check recorded in the phase-6 progress entry rather than an automated one. Only the
pinned Vault digest named in the Makefile comment and `ci.yml` is tested — a different Vault
version, or a real OpenBao build, could behave differently and nothing here would catch it.
