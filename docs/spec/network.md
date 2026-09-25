---
title: "Network secrets"
description: "Network-scope secrets, per-site master keys derived with sodium_crypto_kdf_derive_from_key(), and the separation between site and network scope."
---

# Network secrets

## As proposed

Multisite is in v0. Salts are network-wide, so separate option rows would give logical separation
without cryptographic separation; instead a network root key derives per-site subkeys via
`sodium_crypto_kdf_derive_from_key()`. Site secrets and network secrets are separate functions with
separate capabilities, `manage_secrets` and `manage_network_secrets`, and there is no implicit
fallback from one to the other. See "Multisite is in v0" in the [proposal][proposal]. The proposal
does not name the network-scope functions.

## As built

**Functions.** `wp_set_network_secret()`, `wp_get_network_secret()`, `wp_delete_network_secret()`,
`wp_retire_network_secret_version()`, and `wp_list_network_secrets()` in
`src/wp-includes/secrets.php`. Each shares its implementation with the site-scope function through
a `$network` boolean passed to the provider. A site-scope call never reads a network record and
the reverse; the scope is part of the storage prefix and of the AAD.

**Key derivation.** `WP_Secrets_Key_Manager::get_master_key( $scope, $site_id )` in
`src/wp-includes/class-wp-secrets-key-manager.php`:

| Scope | Subkey id | Context | Result |
|---|---|---|---|
| `site` | the blog id (`get_current_blog_id()` by default) | `wpsecsit` | distinct per blog |
| `network` | `0` (`NETWORK_SUBKEY_ID`) | `wpsecnet` | identical on every blog |

Both derive from the single root key with `sodium_crypto_kdf_derive_from_key( 32, ... )`. Blog ids
start at 1 and the context strings differ, so the network subkey cannot collide with a site
subkey. The root key is stored via `get_site_option()` / `add_site_option()`, which is
`wp_sitemeta` on multisite and `wp_options` on a single site. Master keys are never stored.
Converting a single site to a network leaves the wrapped root key in the former main site's
options row, since conversion does not move it; the first `get_site_option()` miss on the new
network falls back to that row, adopts it into `wp_sitemeta`, and removes the stranded copy, so
secrets written before conversion keep decrypting.

**Storage.** `WP_Secrets_Option_Store` in `src/wp-includes/class-wp-secrets-option-store.php` uses
the prefix `_wp_secret_` with `get_option()` for site scope and `_wp_network_secret_` with
`get_site_option()` for network scope. `list_names()` queries `wp_sitemeta` filtered by
`get_current_network_id()` when multisite and network scope, and `wp_options` otherwise.
`WP_SECRETS_MAX_NAME_LENGTH` (172) is 191 minus the longer prefix.

**AAD.** `WP_Secrets_Cipher::build_aad()` includes the scope and the site id (`0` for network), so
a site-scope record from one blog cannot be decrypted as another blog's, and a network record
cannot be presented as a site record.

**Capabilities.** The API functions perform no capability check, so cron, REST, and anonymous
requests can read secrets. `WP_SECRETS_CAP_MANAGE` (`manage_secrets`) and
`WP_SECRETS_CAP_MANAGE_NETWORK` (`manage_network_secrets`) are defined in
`src/wp-includes/secrets.php` and checked at the operator boundary. In the plugin,
`wp_secrets_api_activate()` in `secrets-api.php` adds `manage_secrets` to the administrator role
and `wp_secrets_api_grant_network_cap_to_super_admins()` grants `manage_network_secrets` to super
admins on multisite through `user_has_cap`. How the core patch grants both capabilities is not
yet designed; the activation hook has no equivalent in core.

**Single site.** The network functions work on a single-site install, because the root key and
the `*_site_option()` functions exist there too. `wp network-secret` refuses on a single-site
install: `WP_CLI_Secret_Command::__construct()` in `cli/class-wp-cli-secret-command.php` calls
`WP_CLI::error()` when `$network` is true and `is_multisite()` is false.

## Why

**One code path on every install.** The proposal describes the derivation in multisite terms. The
code derives master keys from a root key on single-site installs as well, so there is one key
hierarchy rather than two, and converting a single site into a network does not strand its
secrets. The network master is derived (subkey `0`) rather than using the root key directly, so
no key serves two purposes.

**Function names are this implementation's.** The proposal describes network scope and names the
two capabilities but no functions. The `wp_*_network_secret()` names follow core's
`*_site_option()` convention for scope-suffixed twins, and their docblocks say the names are this
implementation's rather than the proposal's.

**API permissive, CLI strict, on single site.** The functions do not refuse on a single site
because a plugin written for a network should not break when installed on one. The CLI refuses
because an operator typing `wp network-secret` on a single site almost certainly meant
`wp secret`, and the two prefixes would otherwise leave the value somewhere the operator will
not look.

[proposal]: https://make.wordpress.org/core/2026/08/25/proposal-a-secrets-api-for-wordpress-7-2/
