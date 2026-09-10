---
title: "Rotation"
description: "Operator-driven rotation: value rotation by overwrite, explicit retirement of the previous slot, and site-key rotation that re-wraps one value."
---

# Rotation

## As proposed

With an envelope, rotating the site key re-wraps a single value (the master key) without touching
stored secrets. Retirement of a previous version is operator-driven, with no timers and no cron,
because core cannot know when a third-party integration has finished draining the old value. See
"Encryption is not optional" and "Two version slots, not unbounded history" in the
[proposal][proposal]. The proposal does not name a retirement function or a rotation command.

## As built

Three distinct operations exist at 0.1.0. None runs on a schedule.

**Rotating a value.** Calling `wp_set_secret()` on an existing name is the rotation.
`WP_Secrets_Libsodium_Provider::set()` in `src/wp-includes/class-wp-secrets-libsodium-provider.php`
reads the prior record, encrypts the new value into `current`, and moves the old `current` to
`previous` through `demote_slot()`, which decrypts under the `current` binding and re-encrypts
under `previous`. If the outgoing slot cannot be decrypted, it is dropped and the write proceeds,
so a corrupted record never blocks its own repair. The `wp_secret_changed` action fires with
`updated`. During the drain, `wp_get_secret( $name, WP_Secret_Version::PREVIOUS )` returns the
old value.

**Retiring the previous value.** `wp_retire_secret_version()` and
`wp_retire_network_secret_version()` in `src/wp-includes/secrets.php` call the provider's
`retire_previous()`, which removes the `previous` slot, writes the record, and fires
`wp_secret_changed` with `retired`. When there is no previous slot, or no secret, it returns `true`:
the requested state already holds. `wp secret retire <name> [--yes]` in
`cli/class-wp-cli-secret-command.php` wraps it.

**Rotating the site key.** `wp secret rotate [--yes]` in `cli/class-wp-cli-secret-command.php`
requires `WP_SECRETS_KEY_PREVIOUS` to be defined and calls
`WP_Secrets_Key_Manager::rotate_site_key()` in `src/wp-includes/class-wp-secrets-key-manager.php`
with `new WP_Secrets_Config_Key_Provider( true )` as the old keyring and
`new WP_Secrets_Config_Key_Provider( false )` as the new one. The method unwraps the stored root
key under the old keyring, wraps it under the new one, and updates the `_wp_secrets_root_key`
site option. The root key's bytes do not change, so every derived master key is unchanged and no
secret is re-encrypted. `wp secret generate-key` prints a base64 32-byte value for the new
`WP_SECRETS_KEY`; it never writes `wp-config.php`. There is no public API function for site-key
rotation; the method is reached through the CLI.

**Flagging for rotation.** Every slot carries `needs_rotation`. `wp_import_option_as_secret()`
sets it to `true`; ordinary writes set `false`. It surfaces in `wp_list_secrets()`, in the Site
Health test `secrets_api_needs_rotation` in `src/wp-admin/includes/secrets-site-health.php`, and
in `wp secret health`.

## Why

**What the site key wraps.** The proposal says the site key wraps the master key. In the code it
wraps the root key, and master keys are derived from that. The observable property the proposal
promises, one re-wrapped value and no re-encrypted secrets, holds. The reason for the indirection
is in [envelope-encryption.md](envelope-encryption.md).

**A named retirement function.** The proposal says retirement is an explicit operator action but
names nothing. `wp_retire_secret_version()` is this implementation's name for it, and its
docblock says so. It is beyond the API surface the proposal publishes.

**Rotation by overwrite.** The proposal does not say how a value is rotated. There is no separate
"rotate value" function because a write to an existing name already has the right semantics, and
a second function would invite two paths to the same state.

**Site-key rotation is CLI-only.** The proposal does not place it. Changing the wrapping key needs
both the old and the new constant present in `wp-config.php` at once, which is an operator's
deployment step and not something a plugin should trigger from a request.

[proposal]: https://make.wordpress.org/core/2026/08/25/proposal-a-secrets-api-for-wordpress-7-2/
