---
title: "Test coverage gaps"
description: "Code paths the automated suite does not reach, why, and what was verified by hand instead."
date: 2026-09-24
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

## 🟢 Drop-in file loading's uncatchable fatal remains outside automated coverage

`wp_secrets_api_load_dropin()` (in `secrets-api.php`) runs once, during
`wp_secrets_api_bootstrap()`, which itself runs once per PHP process via `muplugins_loaded`. Both
that function and `_wp_secrets_get_store()` / `_wp_secrets_get_key_manager()` cache their result
in a function-local `static` on first call, with no reset hook. By the time any test method's body
runs — even in a `@runInSeparateProcess` test — the process's one bootstrap pass has already
completed, so a drop-in file placed on disk from within a test body arrives too late to affect
that process's `wp_secrets_api_load_dropin()` call. `tests/smoke/smoke.sh` case D works around this
the same way a real install does: it writes the drop-in to disk and then invokes a fresh `wp`
process, so `wp_secrets_api_load_dropin()`'s own `require`-and-`try`/`catch` runs for real.

**What is covered:** the consumption side — `_wp_secrets_get_store()` and
`_wp_secrets_get_key_manager()` correctly using `$GLOBALS['wp_secrets_store']` /
`$GLOBALS['wp_secrets_keyring']`, and falling back to `WP_Secrets_Broken_Store` /
`WP_Secrets_Broken_Keyring` when `$GLOBALS['wp_secrets_dropin_broken']` is set — is tested
directly in `tests/phpunit/test-secrets-extension-points.php` by setting those same globals in an
isolated process, exactly as a real drop-in would, before the first call that would cache a
default. `wp_secrets_api_load_dropin()`'s own `require`-and-`try`/`catch` around an actual drop-in
file is now exercised through the real loader by `tests/smoke/smoke.sh` case D, which writes each
of a syntax-error drop-in, a throw-on-load drop-in, a wrong-type provider-global drop-in, and a
sets-nothing drop-in, and asserts on `wp secret get`'s exit code and `wp secret dropin`'s report
for each.

**What is not covered:** the one case case D records rather than asserts as desired — a class that
`implements` an interface but omits a required method is an **uncatchable fatal error**, even
inside that same `try`/`catch`. This is a PHP-engine limitation, not a bug in the catching code:
there is no userland way to intercept it, so there is nothing case D's assertions could turn green
for. Verified empirically, once, directly against the PHP engine on both 7.4.33 and 8.5.7 (before
case D existed), and confirmed again end to end by case D against the smoke suite's own PHP 7.4.33:
the run exits non-zero with a PHP fatal on stderr, recorded as expected behaviour rather than
desired. Worth another look if a future PHP makes this catchable.


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
