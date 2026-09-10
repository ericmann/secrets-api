---
title: "Envelope encryption"
description: "The master-key envelope as proposed, the four-layer XChaCha20-Poly1305 construction as built at 0.1.0, and why the extra layer exists."
---

# Envelope encryption

## As proposed

Secrets are encrypted at rest under a master-key envelope: a per-secret data key, wrapped by a
master key, with the site key wrapping only the master key so that rotation re-wraps a single
value. There is no plaintext mode and no constant to disable encryption. See "Encryption is not
optional" in the [proposal][proposal]. The proposal names libsodium as the primitive source but
does not name a specific cipher, and does not mention additional authenticated data.

## As built

The 0.1.0 code has four layers, not two. Exactly one value is ever stored wrapped.

1. **Site key.** `WP_Secrets_Config_Key_Provider::get_site_key()` in
   `src/wp-includes/class-wp-secrets-config-key-provider.php` derives 32 bytes from
   `wp-config.php`, in priority order: `WP_SECRETS_KEY` when it is the canonical base64 encoding
   of exactly 32 bytes (used raw); `WP_SECRETS_KEY` of any other shape (hashed with
   `sodium_crypto_generichash()` to 32 bytes); otherwise `LOGGED_IN_KEY . LOGGED_IN_SALT` hashed
   the same way. The literal `wp-config-sample.php` placeholder is rejected as unusable.
2. **Root key.** `WP_Secrets_Key_Manager::generate_root_key()` in
   `src/wp-includes/class-wp-secrets-key-manager.php` draws 32 random bytes once per install,
   wraps them with the keyring, and stores the result under the `_wp_secrets_root_key` site
   option via `add_site_option()`, handling the two-requests-race by re-reading the winner. The
   default keyring wraps with `sodium_crypto_aead_xchacha20poly1305_ietf_encrypt()` under the
   fixed AAD `wp-secrets-root-key-v1`, storing `nonce . ciphertext` base64-encoded.
3. **Master key.** `WP_Secrets_Key_Manager::get_master_key()` derives a per-scope master key from
   the root key on demand with `sodium_crypto_kdf_derive_from_key()`. Master keys are never
   stored. See [network.md](network.md) for the subkey and context values.
4. **Data key and value.** `WP_Secrets_Cipher::encrypt_value()` in
   `src/wp-includes/class-wp-secrets-cipher.php` draws a fresh 32-byte data key and a fresh
   24-byte nonce per slot, wraps the data key under the master key, then encrypts the value under
   the data key with a second fresh nonce. Both calls use
   `sodium_crypto_aead_xchacha20poly1305_ietf_encrypt()`.

**AAD binding.** `WP_Secrets_Cipher::build_aad()` produces
`{purpose}|{scope}|{site_id}|{name}|{slot}`, where purpose is `wp-secrets-data-key-v1` for the
wrapped data key and `wp-secrets-value-v1` for the value, scope is `site` or `network`, site_id is
the blog id or `0` for network scope, and slot is a `WP_Secret_Version` constant. A record
therefore cannot be replayed under a different name, slot, scope, or site. The `|` delimiter is
safe because `wp_secrets_validate_name()` runs first and the permitted character set excludes it.

**Record shape.** `WP_Secrets_Libsodium_Provider::set()` in
`src/wp-includes/class-wp-secrets-libsodium-provider.php` assembles
`array( 'v' => WP_SECRETS_RECORD_VERSION, 'current' => $slot, 'previous' => $slot )`, where each
slot carries base64 `dk`, `dk_nonce`, `ct`, `nonce`, plus `fingerprint`, `created`, and
`needs_rotation`. `WP_SECRETS_RECORD_VERSION` is `1`, defined in `src/wp-includes/secrets.php`.

**Fingerprint.** `WP_Secrets_Cipher::fingerprint()` derives a fingerprint key from the master key
(`sodium_crypto_kdf_derive_from_key( 32, 1, 'wpsecfpr', $master_key )`) and returns a 16-byte
`sodium_crypto_generichash()` of the plaintext as hex. It is keyed so the same value fingerprints
differently on different sites. On read, the fingerprint is recomputed from the decrypted plaintext
rather than trusted from the record, because the stored field sits outside the AAD.

**Non-optional.** There is no constant, filter, or code path that stores a plaintext.
`WP_Secrets_Store` implementations only ever receive the record array. If neither the libsodium
extension nor `sodium_compat` is present, every operation that encrypts, decrypts, or derives a
key returns `WP_SECRETS_ERROR_CRYPTO_UNAVAILABLE` rather than degrading. `wp_secrets_memzero()` in
`src/wp-includes/secrets.php` is called on every key and plaintext local once it is no longer
needed.

## Why

**The extra layer.** The proposal describes the site key wrapping the master key directly. The
code inserts a stored root key between them and derives the master key from it. This is what lets
the network requirement in the same proposal ("a network root key derives per-site subkeys") and
the rotation claim ("rotation re-wraps a single value") both hold at once: on a multisite network
each blog gets a cryptographically distinct master key, and a site-key rotation still re-wraps
exactly one stored value, on one site or on five hundred. The README's key-hierarchy diagram and
`docs/decisions/host-provider-model.md` describe the same structure, so the code and the design
documents agree here.

**The cipher.** The proposal leaves the AEAD unnamed. XChaCha20-Poly1305-IETF was chosen because
its 24-byte nonce is large enough that random nonces are safe without any counter or nonce
bookkeeping, which matters for a store with no locking, and because it is the construction
libsodium's own documentation recommends for this use.

**AAD.** The proposal does not mention AAD. Binding every ciphertext to its purpose, scope, site,
name, and slot closes a class of attack the two-slot design would otherwise open: swapping a
`previous` slot's bytes into `current`, or moving a record between names, would otherwise decrypt
cleanly. The cost is that slot demotion during rotation has to decrypt and re-encrypt rather than
copy bytes; see [rotation.md](rotation.md).

**Two interpretations of `WP_SECRETS_KEY`.** The proposal says only that a dedicated constant is
preferred. The code accepts a canonical base64 32-byte value as raw key material and hashes any
other string. The second form exists so a site arriving with a constant of arbitrary shape is not
locked out of its credentials. Site Health reports which interpretation is active.

[proposal]: https://make.wordpress.org/core/2026/08/25/proposal-a-secrets-api-for-wordpress-7-2/
