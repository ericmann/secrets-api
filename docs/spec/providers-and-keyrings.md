---
title: "Providers and keyrings"
description: "WP_Secrets_Provider as the outermost extension point, the shipped libsodium provider and its swappable keyring, and how this relates to the proposal's two secrets.php axes."
---

# Providers and keyrings

## As proposed

A `secrets.php` drop-in exposes two independently replaceable extension points: where ciphertext is
stored (a store) and what wraps the master key (a keyring). Neither is ever handed a plaintext
secret, and neither can turn encryption off. See "Two extension points, independently
replaceable" in the [proposal][proposal]. The proposal uses "provider" informally in its feedback
questions, asking whether providers could stand in for a retrieval filter, but names no interface.

## As built

**Three interfaces, outermost first.**

| Interface | File | Methods |
|---|---|---|
| `WP_Secrets_Provider` | `src/wp-includes/interface-wp-secrets-provider.php` | `get()`, `set()`, `delete()`, `retire_previous()`, `list_secrets()`, `get_label()`, `get_protection_boundary()`, `is_writable()` |
| `WP_Secrets_Store` | `src/wp-includes/interface-wp-secrets-store.php` | `get()`, `set()`, `delete()`, `list_names()` |
| `WP_Secrets_Keyring` | `src/wp-includes/interface-wp-secrets-keyring.php` | `wrap()`, `unwrap()`, `get_key_source()` |

`WP_Secrets_Provider` also declares `BOUNDARY_WORDPRESS` and `BOUNDARY_PROVIDER` for
`get_protection_boundary()`.

**Resolution.** `_wp_secrets_get_provider()` in `src/wp-includes/secrets.php`: if the drop-in is
flagged broken, `WP_Secrets_Broken_Provider`; else if `$GLOBALS['wp_secrets_provider']` is set, it
is used when it implements the interface and replaced with `WP_Secrets_Broken_Provider` when it
does not; else `new WP_Secrets_Libsodium_Provider( _wp_secrets_get_store(), _wp_secrets_get_key_manager() )`.

`_wp_secrets_get_store()` returns `$GLOBALS['wp_secrets_store']` when set to a `WP_Secrets_Store`,
otherwise `WP_Secrets_Option_Store`. `_wp_secrets_get_key_manager()` returns a
`WP_Secrets_Key_Manager` wrapping `$GLOBALS['wp_secrets_keyring']` when set, otherwise
`WP_Secrets_Config_Key_Provider`. Both fail closed to `WP_Secrets_Broken_Store` and
`WP_Secrets_Broken_Keyring` when the drop-in is broken.

**The shipped provider.** `WP_Secrets_Libsodium_Provider` in
`src/wp-includes/class-wp-secrets-libsodium-provider.php` is composed from a store and a key
manager. It reports `BOUNDARY_WORDPRESS`, `is_writable()` true, and a label built from the
keyring's `get_key_source()`. Its store only ever receives record arrays; its keyring only ever
receives 32 bytes of root key material. This is the provider in which the proposal's "never handed
a plaintext" holds.

**The default keyring.** `WP_Secrets_Config_Key_Provider` in
`src/wp-includes/class-wp-secrets-config-key-provider.php` wraps the root key under a site key
derived from `wp-config.php`. Its constructor takes a boolean to read `WP_SECRETS_KEY_PREVIOUS`
instead, used only during site-key rotation. See [envelope-encryption.md](envelope-encryption.md).

**Drop-in loading.** `wp_secrets_api_load_dropin()` in `secrets-api.php` requires
`wp-content/secrets.php` inside `try`/`catch ( \Throwable )`, then type-checks all three globals.
A missing global is fine. A throw or a wrong type sets `$GLOBALS['wp_secrets_dropin_broken']`.
`wp_using_secrets_dropin()` reports presence. In the plugin, this runs after every core-bound
interface has been required, since a drop-in class needs the interface to compile.
[`docs/reference/drop-in-example.php`](../reference/drop-in-example.php) is a skeleton with a
stub of each of the three classes and the globals that install them.

**Relationship to the proposal's two axes.** The store and keyring are the internals of the
shipped provider, and remain public. A drop-in may set `wp_secrets_store`, `wp_secrets_keyring`,
both, or neither, and get the shipped provider built around them. Setting `wp_secrets_provider`
replaces the whole thing, and any store or keyring globals are then ignored on the read and
write path. Site Health still reads the keyring global to describe the key source. The most
common host integration is a keyring alone: three methods.

**Supporting surface.** `wp_secrets_provider_is_writable()` and `wp_secrets_provider_label()` in
`src/wp-includes/secrets.php`. `WP_SECRETS_ERROR_PROVIDER_READ_ONLY` for writes a provider
refuses. A conformance suite, `WP_Secrets_Provider_Conformance` in
`tests/includes/class-wp-secrets-provider-conformance.php`, runs against the shipped provider and
can be extended for a third-party one. Two provider examples exist,
`examples/aws-secrets-manager/` and `examples/vault-provider/`, and `make test-examples` runs the
conformance suite against the Vault one on a real server.

**Plugin-only detail.** `secrets-api.php` sets `$GLOBALS['wp_secrets_store']` to a
`Secrets_API_Prototype_Fallback_Store` wrapping `WP_Secrets_Option_Store` before the drop-in
loads, so a drop-in's own store replaces it. The fallback store lives under `plugin/` and is not
core-bound.

## Why

**A third, outer extension point.** Hosts responding to the proposal described deployments the
two-axis design could not express: a platform that holds the credential itself, encrypts it with
its own KMS or HSM, and serves it to WordPress over an authenticated channel. Under the two-axis
contract that is banned, because the store is handed something WordPress did not encrypt. The
resolution was to restate the rule as "a provider must be stronger than the default, never
weaker" and to put the provider one level outside the store and keyring, rather than carve
exceptions into the store contract. `docs/decisions/0001-provider-as-outermost-extension-point.md` records the
discussion; `docs/spec/extension-points.md` holds the contracts.

**"Never handed a plaintext" is now a property of the shipped provider, not of every extension.**
Plaintext at rest in the database stays impossible, the default path is unchanged, and there is
still no filter. What changed is that WordPress doing the encrypting was a means to the rule, not
the rule.

**Declarations, not enforcement.** `get_protection_boundary()` and `is_writable()` are not
security controls. A drop-in is fully trusted code and could already read every secret by
implementing the keyring. They exist so Site Health, a reviewer, and a future settings screen can
see what a provider claims.

[proposal]: https://make.wordpress.org/core/2026/08/25/proposal-a-secrets-api-for-wordpress-7-2/
