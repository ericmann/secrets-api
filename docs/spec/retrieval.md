---
title: "Retrieval"
description: "The three-state return from wp_get_secret() and the absence of any filter on the retrieval path, as proposed and as built."
---

# Retrieval

## As proposed

`wp_get_secret()` returns a `WP_Secret` on success, `null` when the secret does not exist, and
`WP_Error` when it exists but could not be retrieved. No filter is applied to a secret on its way
out of storage, because such a filter would receive every credential on the site in plaintext.
`WP_Secret::reveal()` is the only way to the raw string and masks itself everywhere else. See
"Proposed API" and "Two extension points, independently replaceable" in the [proposal][proposal].

## As built

**Entry point.** `wp_get_secret( $name, $version = WP_Secret_Version::CURRENT )` in
`src/wp-includes/secrets.php` calls `_wp_secrets_get()`, which rejects any `$version` other than
the two constants with `WP_SECRETS_ERROR_INVALID_ARGUMENT` plus `_doing_it_wrong()`, then calls
`_wp_secrets_get_provider()->get()`. The provider is resolved once per request and cached in a
function-local static.

**The three states, in the shipped provider.** `WP_Secrets_Libsodium_Provider::get()` in
`src/wp-includes/class-wp-secrets-libsodium-provider.php`, in order:

- An invalid name returns `WP_Error` (`WP_SECRETS_ERROR_INVALID_NAME`). A caller mistake is never
  reported as absence.
- The store's `WP_Error` is returned as-is. A store that cannot tell whether a record exists
  returns `WP_SECRETS_ERROR_STORE_UNAVAILABLE`, never `null`.
- A `null` record returns `null`.
- A record failing `validate_record_shape()` returns `WP_SECRETS_ERROR_RECORD_MALFORMED` or
  `WP_SECRETS_ERROR_RECORD_UNSUPPORTED_VERSION`.
- A present record with the requested slot missing returns `null`. This is what
  `WP_Secret_Version::PREVIOUS` returns for a secret that has never been rotated.
- A key manager failure returns its `WP_Error` (`WP_SECRETS_ERROR_KEY_UNAVAILABLE` or
  `WP_SECRETS_ERROR_CRYPTO_UNAVAILABLE`).
- A decryption failure returns `WP_SECRETS_ERROR_DECRYPTION_FAILED`.
- Otherwise a `WP_Secret` is constructed with the plaintext and a fingerprint recomputed from it.

**Fail closed.** When `wp-content/secrets.php` throws, or sets any of the three globals to the
wrong type, `wp_secrets_api_load_dropin()` in `secrets-api.php` sets
`$GLOBALS['wp_secrets_dropin_broken']`, and `_wp_secrets_get_provider()` installs
`WP_Secrets_Broken_Provider`, whose every read and write returns
`WP_SECRETS_ERROR_STORE_UNAVAILABLE`.
There is no fallback to the default provider. `tests/phpunit/test-secrets-three-state-contract.php`
and `tests/phpunit/test-secrets-broken-dropin-fallbacks.php` cover this.

**No filter.** There is no `apply_filters()` call anywhere under `src/`, not only on the retrieval
path. `test_no_apply_filters_anywhere_in_src()` in `tests/phpunit/test-architecture.php` reads
every source file and fails the build if one appears. The only hook in core-bound code is the
`wp_secret_changed` action, fired from the provider's `set()`, `delete()`, and
`retire_previous()`, carrying fingerprints and never values.

**`WP_Secret`.** In `src/wp-includes/class-wp-secret.php`, the plaintext is never a declared
property. It lives in a private static array keyed by `spl_object_id()`, so `var_export()` and
`print_r()` cannot reach it. `__toString()`, `__debugInfo()`, and `jsonSerialize()` return
`[secret:{name}]`. `__sleep()`, `__wakeup()`, `__serialize()`, `__unserialize()`, and `__clone()`
throw `LogicException`. `__destruct()` zeroes the value with `wp_secrets_memzero()`.
`reveal()` returns `string|WP_Error`; `fingerprint()` and `get_name()` return strings.
`WP_Secret::withheld( $name, $fingerprint, $reason )`, which requires a `WP_Error` reason,
constructs a secret whose
`reveal()` returns the reason instead of a value.

## Why

**`reveal()` returns `string|WP_Error`, not `string`.** The proposal publishes
`WP_Secret::reveal(): string`. The code widens it so a provider can represent a credential it can
name and fingerprint but will not release to PHP, such as an HSM key that signs but never exports.
The shipped provider never produces this case. The change was made before adoption because a return
type cannot be widened afterwards. `docs/decisions/host-provider-model.md` records the reasoning
and agrees with the code.

**"No filter" is broader than proposed.** The proposal rules out a filter on retrieval. The code
and its architectural test rule out `apply_filters()` in any core-bound file. A filter on
provider selection, or on the record before decryption, would be one step from a filter on the
value, so the whole surface is closed rather than one path.

**An invalid name is `WP_Error`, not `null`.** The proposal does not say. Returning `null` for a
typo would tell a caller the credential is absent when the call was wrong.

[proposal]: https://make.wordpress.org/core/2026/08/25/proposal-a-secrets-api-for-wordpress-7-2/
