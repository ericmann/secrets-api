---
title: "Scope"
description: "The 7.2 target of API plus WP-CLI, the UI deferred to 7.3, why the plugin ships ahead of the core patch, and what 0.2.0 adds beyond the proposal's named surface."
---

# Scope

## As proposed

WordPress 7.2 gets the API and WP-CLI support; no new UI in 7.2, with a settings screen designed
in 7.3 once the API is stable, though the hooks and accessors a screen needs are in scope now. The
implementation ships as a plugin first so contributors can run it rather than read about it, with
a Trac patch matching the plugin's surface to follow. See "Clarifying priorities", "WP-CLI is in
scope", and "Timeline" in the [proposal][proposal].

## As built

**The plugin.** `secrets-api.php` declares version `0.2.0`, `Requires at least: 6.6`, and
`Requires PHP: 7.4`.

**Core-bound versus plugin-only.** Everything under `src/` is written to be copied into
`wordpress-develop` unchanged: `src/wp-includes/` holds the API and
`src/wp-admin/includes/secrets-site-health.php` holds Site Health. `plugin/` and `cli/` are never
copied. `tests/phpunit/test-architecture.php` enforces the boundary: no reference to `WP_CLI`,
`plugin/`, or `cli/` under `src/`; no self-guarding `function_exists()` or `class_exists()`; every
file carries `@since 7.2.0`; only the `default` text domain; no subdirectory other than the two
core paths; no prototype-compatibility symbol.

**Standing down.** `wp_secrets_api_bootstrap()` in `secrets-api.php` loads nothing and shows an
admin notice when `$wp_version` is at least `WP_SECRETS_API_CORE_VERSION` (`7.2`, overridable)
**and** `wp_get_secret()` already exists. If the symbol exists but the version test fails, the
plugin refuses to load and shows a conflict notice instead of deferring to an unknown
implementation. `tests/phpunit/test-secrets-noop-gate.php` covers the gate.

**API surface at 0.2.0.** The proposal's four functions (`wp_set_secret()`, `wp_get_secret()`,
`wp_delete_secret()`, `wp_import_option_as_secret()`), `WP_Secret`, and `WP_Secret_Version` are
present with the proposed signatures, except that `reveal()` returns `string|WP_Error`. Beyond
the named surface, all in `src/wp-includes/secrets.php` unless noted:

- `wp_retire_secret_version()` and `wp_list_secrets()`.
- Five network-scope functions; see [network.md](network.md).
- `wp_secrets_validate_name()`, `wp_secrets_memzero()`, `wp_using_secrets_dropin()`,
  `wp_secrets_provider_is_writable()`, `wp_secrets_provider_label()`.
- The `wp_secret_changed` action, fired from the shipped provider.
- `WP_Secret::get_name()` and `WP_Secret::withheld()`.
- `WP_Secrets_Provider`, `WP_Secrets_Store`, `WP_Secrets_Keyring`, and their shipped and
  fail-closed implementations; see [providers-and-keyrings.md](providers-and-keyrings.md).
- Ten `WP_SECRETS_ERROR_*` code constants, `WP_SECRETS_RECORD_VERSION`,
  `WP_SECRETS_MAX_NAME_LENGTH`, `WP_SECRETS_CAP_MANAGE`, `WP_SECRETS_CAP_MANAGE_NETWORK`.

**WP-CLI.** `WP_CLI_Secret_Command` in `cli/class-wp-cli-secret-command.php` registers
`wp secret` with `set`, `get`, `delete`, `list`, `retire`, `import-option`, `migrate-legacy`,
`rotate`, `generate-key`, `health`, and `dropin`. `WP_CLI_Secret_Network_Command` registers
`wp network-secret` with the same subcommands for network scope. Both load only when `WP_CLI` is
defined and true. Both are exercised end to end against a real `wp` binary by
`tests/smoke/smoke.sh`, on single site and multisite.

**Site Health.** Three tests (`secrets_api_key_source`, undecryptable secrets,
`secrets_api_needs_rotation`) and a debug-information section, in
`src/wp-admin/includes/secrets-site-health.php`.

**No UI.** There is no settings screen, no options page, and no admin menu entry.

## Why

**Additions beyond the named surface.** `wp_list_secrets()`, `wp_retire_secret_version()`, the
provider label and writability helpers, and the `wp_secret_changed` action are the "hooks and
accessors an admin screen would need", which the proposal places in scope for 7.2 even though the
screen is not. Site Health is named in the proposal as the place undecryptable secrets are
reported. The network functions implement a section of the proposal that names no functions.
`docs/journal/proposal-questions.md` tracks which additions the comment thread has confirmed.

**Why the plugin ships first.** The proposal's own reasoning: contributors can run the API, and
the awkward parts of the surface show up while they are still cheap to change. The plugin also
gives sites on current WordPress something usable before the core patch lands. Keeping `src/`
copy-ready is what makes the plugin and the eventual Trac patch the same code rather than two.

**Why the gate is ANDed.** A bare version check would silently disable the plugin if 7.2 shipped
without the API, stranding every site relying on it. The proposal's own timeline allows for
deferral to 7.3. `docs/decisions/` does not record this separately; the reasoning lives in the
bootstrap's comments.

**`migrate-legacy` is outside the proposal.** It, and the read-time upgrade behind it, exist for
sites that adopted an earlier prototype. Neither is core-bound. See [import.md](import.md).

[proposal]: https://make.wordpress.org/core/2026/08/25/proposal-a-secrets-api-for-wordpress-7-2/
