---
title: "Import"
description: "Import, don't migrate: wp_import_option_as_secret() moves one named option on the plugin author's schedule and flags it for rotation."
---

# Import

## As proposed

No automatic sweep of the options table, because core cannot reliably tell which options are
credentials. `wp_import_option_as_secret()` lets a plugin author move one known option
deliberately, on their own upgrade schedule, and flags the imported secret for rotation because a
value that sat in `wp_options` is already in backups and replicas. Fingerprints let an admin
confirm the re-entered value matches. See "Import, don't migrate" in the [proposal][proposal].

## As built

**The function.** `wp_import_option_as_secret( $option, $name )` in
`src/wp-includes/secrets.php`:

- Returns `WP_SECRETS_ERROR_INVALID_VALUE` when `$option` is not a non-empty string, when the
  option does not exist (`get_option( $option, null )` returns `null`), or when its value is not
  a string.
- Otherwise calls `_wp_secrets_set( $name, $value, false, true, 'imported' )`, which reaches the
  provider's `set()` with `$needs_rotation` true and the `wp_secret_changed` action reported as
  `imported`.
- Leaves the source option in place. Nothing in the function reads it destructively, moves it, or
  deletes it.

There is no network-scope variant, and none is planned.

**Operator surface.** `wp secret import-option <option> <name>` in
`cli/class-wp-cli-secret-command.php` wraps the function. The `needs_rotation` flag it sets is
visible in `wp_list_secrets()`, `wp secret list`, the `secrets_api_needs_rotation` Site Health
test, and `wp secret health`. Nothing clears the flag except writing a new value with
`wp_set_secret()`.

**Adjacent, plugin-only.** The plugin also upgrades records left by an earlier prototype of this
API the first time they are read, through `Secrets_API_Prototype_Fallback_Store` in
`plugin/class-secrets-api-prototype-fallback-store.php`, and offers `wp secret migrate-legacy` for
bulk copies. Both flag the result `needs_rotation`, both are additive, and neither touches the
prototype's rows. None of this is under `src/` and none of it is proposed for core. See
`docs/reference/migrating-from-displace.md`.

## Why

**Copy, not move.** The proposal's verb is "move". The code reads the option and writes a secret
without deleting the source. Deleting another plugin's option is a decision for that plugin's
author, made after the fingerprint has confirmed the import; the API does not make it on their
behalf. The README states the same rule for the prototype upgrade path.

**The prototype upgrade path is outside the proposal.** It exists so sites that adopted the
prototype are not stranded while its consumers move to `wp_get_secret()`. It is confined to
`plugin/` and one CLI subcommand, and an architectural test
(`test_no_prototype_compat_symbols_in_src()` in `tests/phpunit/test-architecture.php`) keeps it
out of core-bound code. `docs/decisions/0004-no-compat-shim-for-the-prototype.md` records why it is an upgrade rather
than a compatibility layer.

[proposal]: https://make.wordpress.org/core/2026/08/25/proposal-a-secrets-api-for-wordpress-7-2/
