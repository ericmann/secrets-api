---
title: "Host-managed keys"
description: "How a hosting platform owns a site's encryption key: generating it, keeping it out of backups, rotating it, and keeping a history of past keys."
---

# Host-managed keys

A common question from hosts: can the platform generate and hold a site's encryption key itself,
out of `wp-config.php` and out of backups, and own its rotation? Yes. That is what the keyring
extension point is for, and nothing in core has to change to do it. This page explains which part
of the key hierarchy a host takes over, then describes three ways to do it, from least to most
work.

## What the host actually owns

Four layers of keys sit between a site and its secrets. The full construction is in
[envelope-encryption.md](../spec/envelope-encryption.md). The two outermost layers are the ones
that matter here:

| Layer | Where it lives | Who can own it |
|---|---|---|
| **Wrapping key** (the "site key") | `WP_SECRETS_KEY` by default, or a host service | **The host** |
| **Root key**, 32 random bytes | The `_wp_secrets_root_key` site option, *wrapped* | WordPress generates it once |
| Master keys, data keys | Derived or generated per secret | WordPress |

The wrapping key is the only key the host needs to own. WordPress generates the root key, but it
stores only the wrapped form, and the root key never leaves the site in plaintext. Every master key
and data key derives from the root key. Rotating the wrapping key therefore re-wraps one 32-byte
value and re-encrypts no secrets.

The consequence for backups is the one hosts care about: **a database dump holds ciphertext and a
wrapped root key, and neither is usable without the wrapping key.** If the wrapping key sits
somewhere the backup does not reach, the backup cannot decrypt anything on its own.

## Option 1: an environment variable

This needs no code. The default keyring reads a PHP constant, and the constant can come from
anywhere `wp-config.php` can read:

```php
// wp-config.php
define( 'WP_SECRETS_KEY', getenv( 'WP_SECRETS_KEY' ) );
```

The host generates the value (`wp secret generate-key`, or any 32 random bytes, base64-encoded)
and injects it from the hosting panel. `wp-config.php` then holds a reference instead of the key,
so a file backup no longer carries it.

If the variable goes missing, `getenv()` returns `false`, and the keyring treats a constant that
is defined but not a non-empty string as an error. Every secret read then fails closed with
`secret_key_unavailable`. It never falls back to the salts or to an empty key.

**Rotating.** Rotation needs both keys for as long as one command takes to run:

1. Expose the old value as `WP_SECRETS_KEY_PREVIOUS` and the new one as `WP_SECRETS_KEY`.
2. Run `wp secret rotate`. It unwraps the root key with the previous key and re-wraps it with the
   current one.
3. Remove `WP_SECRETS_KEY_PREVIOUS`.

The panel can keep as many past values as it likes; WordPress only ever needs the one it is
rotating away from. A site that has never set `WP_SECRETS_KEY` is running on the salt-derived key,
and its "previous" value is the string `LOGGED_IN_KEY . LOGGED_IN_SALT`. Setting that as
`WP_SECRETS_KEY_PREVIOUS` moves the site onto a host-issued key the same way.

**The limit.** An environment variable is still visible to the PHP process. It can end up in
`phpinfo()`, in `$_SERVER` or `$_ENV` under some SAPI configurations, and in an error tracker that
captures the environment. That exposure is narrower than a key in `wp-config.php`, but it is not
zero. If "doesn't get dumped accidentally" is a hard requirement, use option 2.

## Option 2: a host keyring

In this option the wrapping key never enters PHP at all. A `WP_Secrets_Keyring` is three methods,
and the host implements `wrap()` and `unwrap()` by calling whatever it already runs: a PHP
extension function, a local agent over a Unix socket, a metadata endpoint, or its KMS.

```php
// wp-content/secrets.php, installed and owned by the host.
final class Host_Panel_Keyring implements WP_Secrets_Keyring {

	public function wrap( $key_material ) {
		// The host's function wraps under the site's newest key version and
		// returns an opaque string that records which version it used.
		$wrapped = host_panel_wrap( $key_material );

		return false === $wrapped
			? new WP_Error( WP_SECRETS_ERROR_KEY_UNAVAILABLE, 'Host key service unavailable.' )
			: $wrapped;
	}

	public function unwrap( $wrapped ) {
		// Reads the version from $wrapped and unwraps with that version,
		// current or retained.
		$key_material = host_panel_unwrap( $wrapped );

		return false === $key_material
			? new WP_Error( WP_SECRETS_ERROR_KEY_UNAVAILABLE, 'Host key service could not unwrap the root key.' )
			: $key_material;
	}

	public function get_key_source() {
		return 'Host panel key service';
	}
}

$GLOBALS['wp_secrets_keyring'] = new Host_Panel_Keyring();
```

`host_panel_wrap()` and `host_panel_unwrap()` stand in for whatever the platform provides. They
are not WordPress functions. Everything else stays WordPress's: the libsodium envelope, the
options-table storage, the `wp secret` commands. Site Health reports the key source as the host's.

What this gets the host:

- **Nothing to leak from PHP.** The wrapping key stays inside the host's service. PHP only ever
  sees the unwrapped root key, in memory for one request.
  [ADR 0009](../decisions/0009-root-key-cached-for-the-request.md) keeps it out of the object
  cache.
- **One call per request.** The key manager unwraps once and caches the result for the rest of
  the request, however many secrets the request reads.
- **Backups are inert.** `secrets.php` holds code, not a key, so it can be backed up along with
  the rest of the site.
- **Key history on the host's side.** If the wrapped value carries a key version, which is how
  AWS KMS, Google Cloud KMS, and Vault Transit all work, `unwrap()` can use any version the host
  still retains. The host can then issue a new version without touching the site: the stored root
  key keeps unwrapping under the version that wrapped it. The host decides how many versions to
  keep and for how long.
- **Fails closed.** If the service is down, `unwrap()` returns a `WP_Error` and every secret read
  reports `secret_key_unavailable`. The request never gets an empty value and never falls back to
  `wp-config.php`. [ADR 0007](../decisions/0007-fail-closed-on-a-broken-drop-in.md) covers the
  case where the drop-in itself breaks.

`wrap()` must be non-deterministic, and `unwrap()` must return `WP_Error` for anything it did not
produce and must never throw. The
[keyring contract](../spec/extension-points.md) and its conformance suite,
`WP_Secrets_Keyring_Conformance`, check both. The
[AWS KMS keyring example](../../examples/aws-kms-keyring/README.md) is a complete, tested
implementation of this option, about 300 lines with no SDK.

**Moving an existing site onto the host keyring:** install the drop-in, then run
`wp secret rotate --from=config` in the same maintenance window. The command unwraps the root key
with `WP_SECRETS_KEY` (or the salts) and re-wraps it under the host keyring. Between the two steps,
reads fail closed.

**Retiring an old key version** means re-wrapping the root key under the newest version. There is
no `wp secret rotate` option for re-wrapping within the same drop-in keyring yet. Because a
versioned `unwrap()` keeps reading old versions, this matters only when the host wants to destroy
a version. It is tracked in
[open-questions.md](../journal/open-questions.md#re-wrapping-under-a-newer-version-of-the-same-keyring).

## Option 3: a host provider

A host whose panel already stores credentials, and not only keys, can go one level further. In
that case WordPress stops being the custodian at all. A `WP_Secrets_Provider` takes the value
over an authenticated channel, and the platform stores and encrypts it. Site Health reports
`Encryption boundary: the provider (outside WordPress)`, and the platform's own version history
backs the API's two slots, `CURRENT` and `PREVIOUS`.

That requires eight methods instead of three, and the host has to agree to run a secret store.
The [AWS Secrets Manager](../../examples/aws-secrets-manager/README.md) and
[HashiCorp Vault](../../examples/vault-provider/README.md) examples show what it looks like. Both
are described in [examples.md](examples.md).

## Which one

| You want | Use |
|---|---|
| The key out of `wp-config.php` and file backups, with no code | Option 1, an environment variable |
| The key never readable by PHP, with rotation and key history owned by the host | Option 2, a host keyring |
| The host to hold the credentials themselves, not just the key | Option 3, a host provider |

Most hosts want option 2. It is the smallest piece of code that moves key custody off the site,
and it leaves storage and encryption exactly as they are on every other WordPress install.
