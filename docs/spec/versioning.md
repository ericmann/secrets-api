---
title: "Versioning"
description: "Two version slots addressed by WP_Secret_Version string constants, and why the PHP 7.4 floor rules out a native enum."
---

# Versioning

## As proposed

A secret keeps two versions, `CURRENT` and `PREVIOUS`, addressed by string constants on a
`final class WP_Secret_Version`. Native enums need PHP 8.1 and core's minimum is 7.4, so string
constants on a final class are the portable equivalent. See "Two version slots, not unbounded
history" in the [proposal][proposal].

## As built

**The constants.** `WP_Secret_Version` in `src/wp-includes/class-wp-secret-version.php` is a
`final class` with `const CURRENT = 'current'` and `const PREVIOUS = 'previous'`. The constant
values double as the record's array keys.

**Validation.** `_wp_secrets_get()` in `src/wp-includes/secrets.php` accepts only those two values
and returns `WP_SECRETS_ERROR_INVALID_ARGUMENT` with a `_doing_it_wrong()` notice for anything
else. `WP_Secrets_Cipher::validate_common()` in `src/wp-includes/class-wp-secrets-cipher.php`
checks the same set before any cryptographic operation.

**Two slots, one previous.** `WP_Secrets_Libsodium_Provider::set()` in
`src/wp-includes/class-wp-secrets-libsodium-provider.php` writes the new value to `current` and
demotes the outgoing `current` to `previous`. Whatever was already in `previous` is not carried
forward, so a third write discards the oldest value.

**Slot is part of the ciphertext binding.** The slot name is one of the fields in the AAD
`WP_Secrets_Cipher::build_aad()` computes, so a slot's ciphertext is bound to its position.
Demotion is therefore a decrypt-and-re-encrypt in
`WP_Secrets_Libsodium_Provider::demote_slot()`, not a copy.

**Reading `PREVIOUS`.** `wp_get_secret( $name, WP_Secret_Version::PREVIOUS )` returns `null` when
the secret exists but has never been rotated. See [retrieval.md](retrieval.md).

**The floor is enforced.** `test_phpcompatibility_ruleset_is_wired_at_the_7_4_floor()` in
`tests/phpunit/test-architecture.php` asserts `phpcs.xml.dist` runs PHPCompatibilityWP at
`testVersion 7.4-`, and `make compat` runs it. CI includes a PHP 7.4 leg.

**A separate version.** `WP_SECRETS_RECORD_VERSION` (`1`) in `src/wp-includes/secrets.php` is the
record format version, stored as `v` in every record and checked by
`WP_Secrets_Libsodium_Provider::validate_record_shape()`. An unknown `v` returns
`WP_SECRETS_ERROR_RECORD_UNSUPPORTED_VERSION` rather than being attempted. This is unrelated to the
two value slots. `docs/decisions/0006-record-format-v2-not-read-compatible.md` covers what a future
bump would mean.

**A backend with more than two versions.** `examples/vault-provider/secrets.php` is the first
provider whose backend keeps more than two versions of its own: Vault's KV v2 engine numbers
versions 1, 2, 3, and so on. `Vault_KV2_Provider` translates that into the two-slot shape by
setting `max_versions: 2` on every secret it creates and by defining `PREVIOUS` as strictly
version N-1 in `previous_version()`, never the newest surviving version below N. See
[ADR 0009](../decisions/0009-cap-a-many-version-backend-to-two-slots.md).

## Why

[proposal]: https://make.wordpress.org/core/2026/08/25/proposal-a-secrets-api-for-wordpress-7-2/
