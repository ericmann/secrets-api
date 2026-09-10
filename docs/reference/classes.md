---
title: "Classes"
description: "Every class and interface the plugin declares, with public constants and methods, generated from docblocks."
---

<!--
  GENERATED FILE. Do not edit by hand.
  Produced by bin/gen-reference.php from docblocks in the PHP source.
  Edit the docblock, then run `php bin/gen-reference.php`. CI fails when this
  file no longer matches the source.
-->

# Classes and interfaces

WP-CLI command classes are documented in [wp-cli.md](wp-cli.md) rather than here.

## `Secrets_API_Legacy_Reader`

*final class*

Read-only access to the earlier prototype's on-disk format.

Never writes, never deletes -- this class exists purely so
Secrets_API_Migrator can read a value out of the old format to write it into the
new one. All legacy crypto is isolated here specifically so it can be deleted in
one commit when the compatibility window closes.

The legacy format, verified against the prototype's own source:

- Secret option: '_secret_{key}'.
- Master key option: '_secrets_master_key'.
- Cipher: sodium_crypto_secretbox (XSalsa20-Poly1305).
- Record: base64( nonce . ciphertext ) -- a single string, not an array. No AAD.
- Site key: sodium_crypto_generichash( $material, '', 32 ), where $material is
  the literal WP_SECRETS_KEY string if defined, or LOGGED_IN_KEY . LOGGED_IN_SALT.

Critically, legacy always hashes WP_SECRETS_KEY's literal string form -- there is
no "raw base64-32 bytes" path here at all, unlike the new format's key provider.
A site with WP_SECRETS_KEY defined is therefore not automatically compatible
between the two formats even when the constant happens to be valid base64-32; the
salt-fallback path is the one that is byte-identical between old and new, which
is deliberate and is what lets those sites migrate with zero credential re-entry.

Because of that asymmetry, this reader does not assume the currently-defined
constants describe how existing records were sealed. It tries every site key the
legacy system could have used and keeps whichever one actually opens the master
key record -- see unwrap_master_key() for why that is safe and why it matters.

Deliberately does not reject the wp-config-sample.php placeholder the way
WP_Secrets_Config_Key_Provider does: that check is a hardening this project added
to the new format, not something the legacy system ever did. A site that
legitimately encrypted a value under a placeholder-derived key on the old system
must still be able to read it back here -- refusing it would break exactly the
migration this class exists to support, not improve security for a record that
already exists.

**Source:** [`plugin/class-secrets-api-legacy-reader.php`](../../plugin/class-secrets-api-legacy-reader.php)

### Constants

| Constant | Value | Description |
|---|---|---|
| `MASTER_KEY_OPTION` | `'_secrets_master_key'` |  |
| `SECRET_OPTION_PREFIX` | `'_secret_'` |  |

### Methods

#### `Secrets_API_Legacy_Reader::get()`

Reads and decrypts a legacy secret.

```php
public function get( $key )
```

| Parameter | Type | Description |
|---|---|---|
| `$key` | `string` | The legacy secret's bare key name (e.g. 'api_key' for the option '_secret_api_key') -- legacy names are not namespaced the way new-format names are. |

**Returns:** `string|WP_Error` Plaintext on success. WP_Error on failure.

#### `Secrets_API_Legacy_Reader::list_keys()`

Lists every legacy secret's bare key name. Still read-only: a listing, not a value.

```php
public function list_keys()
```

**Returns:** `array|WP_Error` List of bare key names on success. WP_Error on failure.

## `Secrets_API_Migrator`

*final class*

Copies secrets out of the prototype's on-disk format into the new one.

Strictly additive, by construction rather than by flag: this reads the
prototype's option rows and writes new-format ones, and there is no code path
here that writes to, deletes, or otherwise disturbs anything the prototype
owns. A site that runs this ends up with both copies, and the prototype --
along with anything vendoring it -- keeps working exactly as before.

That is a deliberate narrowing of the original plan, which had a
--delete-source flag to remove each legacy option once its migrated value
verified. Plugins built against the prototype are actively reading those
rows; deleting them is the one irreversible thing this plugin could do to
another team's working system, and no amount of verify-before-delete makes
that a good trade for a cleanup step an operator can perform explicitly with
`wp option delete` if they ever actually want it. Removing the capability
outright is a stronger guarantee than guarding it. See
the deletion seam documented in secrets-api.php.

Re-running is safe: already-migrated keys are reported as skipped rather than
rewritten. Read failures (a record that will not decrypt) are reported per key
and never abort the run -- one bad key must not block migrating the rest.

**Source:** [`plugin/class-secrets-api-migrator.php`](../../plugin/class-secrets-api-migrator.php)

### Constants

| Constant | Value | Description |
|---|---|---|
| `VENDORED_AI_PLUGIN_CLASS` | `'WordPress\\AI\\Vendor\\Secrets\\Secrets_Manager'` | The AI plugin's vendored copy of the prototype's code. Its presence means the prototype's option rows are live, not historical -- worth telling the operator, since after migrating, the same credential exists in two places and the AI plugin will keep reading its own copy. |

### Methods

#### `Secrets_API_Migrator::migrate()`

Migrates legacy secrets into the new format.

$args accepts:

- bool   $dry_run   Write nothing; report only. Default false.
- string $name      Migrate only this legacy key. Default null (all).
- array  $map       Legacy key =\> explicit new name, for keys whose derived
                    name would not validate.
- string $namespace Namespace prefixed onto a legacy key with no entry in
                    $map. Defaults to none, which keeps the key exactly as
                    the prototype spelled it.

```php
public function migrate( $args = array() )
```

| Parameter | Type | Description |
|---|---|---|
| `$args` | `array` | Migration options, as described above. |

**Returns:** `array` { @type bool $vendor_detected Whether the vendored AI plugin class exists. @type array $entries One report entry per legacy key, each with 'legacy_key', 'new_name', 'status', 'message', and, once migrated, 'fingerprint'. Never a value.

## `Secrets_API_Prototype_Fallback_Store`

*final class* · implements `WP_Secrets_Store`

Serves a read whose new-format record does not exist yet from the prototype's option row instead, upgrading it to the current format on the way through.

The problem this solves is adoption, not compatibility. The AI plugin was built
on the prototype, so its sites have credentials sitting in prototype-format
rows. When that plugin moves to the Secrets API, every one of those sites would
otherwise see wp_get_secret() return null for a credential it demonstrably has,
and would need an explicit migration run -- per site -- before working again.
Sites that nobody remembers to migrate would simply break, and a credential that
cannot be re-entered from memory means a rebuild.

So instead: a miss falls through to the prototype row, the value is re-encrypted
into a proper current-format record, and the caller gets a normal WP_Secret. The
next read hits the new record directly and never comes back here. The upgrade is
one-way and happens once per secret, on first use.

Only the unnamespaced form participates. wp_get_secret( 'api_key' ) consults the
prototype's 'api_key'; wp_get_secret( 'myplugin/api_key' ) consults nothing,
because the prototype had no namespaces and so cannot have owned that name.
Nothing is rewritten or inferred -- see prototype_key_for().

A note on what this is NOT. It does not implement the prototype's API, reinstate
its function names, or let prototype-era code keep running -- that would be a
compatibility layer, and there is deliberately none. It reads one option row,
once, and never writes to or deletes anything the prototype owns. Both systems
keep working on the same site throughout, because the two option namespaces do
not overlap.

---

Implemented as a decorator around whatever store would otherwise be active,
rather than as changes to WP_Secrets_Option_Store, for two reasons. Core has no
business knowing this format ever existed, and src/ stays a clean file-copy
candidate. And it composes: this wraps the default option store, and a
secrets.php drop-in that installs a host store replaces it entirely, which is
the right outcome -- a host serving secrets from its own platform has no
prototype rows to inherit.

**Source:** [`plugin/class-secrets-api-prototype-fallback-store.php`](../../plugin/class-secrets-api-prototype-fallback-store.php)

### Methods

#### `Secrets_API_Prototype_Fallback_Store::__construct()`

Wraps an existing store.

```php
public function __construct( WP_Secrets_Store $inner )
```

| Parameter | Type | Description |
|---|---|---|
| `$inner` | `WP_Secrets_Store` | The store to delegate to. |

#### `Secrets_API_Prototype_Fallback_Store::delete()`

Deletes a current-format record. The prototype's row is left alone, so a delete followed by a read will inherit the prototype value again rather than reporting absence -- the same answer the site would have given before the Secrets API was installed at all.

```php
public function delete( $name, $network = false )
```

| Parameter | Type | Description |
|---|---|---|
| `$name` | `string` | The secret's namespaced name. |
| `$network` | `bool` | Whether this is a network-scope secret. |

**Returns:** `bool|WP_Error`

#### `Secrets_API_Prototype_Fallback_Store::get()`

Reads a record, falling back to the prototype format on a miss.

```php
public function get( $name, $network = false )
```

| Parameter | Type | Description |
|---|---|---|
| `$name` | `string` | The secret's namespaced name. |
| `$network` | `bool` | Whether this is a network-scope secret. |

**Returns:** `array|null|WP_Error`

#### `Secrets_API_Prototype_Fallback_Store::has_current_record()`

Whether a current-format record already exists, without the fallback.

Ordinary callers want the fallback -- that is the entire point. The migrator
does not: it needs to report whether a secret was already in the current
format, and asking through get() would upgrade the very thing it is trying
to describe. That is not just a cosmetic reporting problem; it would make
`--dry-run` write.

```php
public function has_current_record( $name )
```

| Parameter | Type | Description |
|---|---|---|
| `$name` | `string` | The secret's namespaced name. |

**Returns:** `bool`

#### `Secrets_API_Prototype_Fallback_Store::list_names()`

Lists current-format names only. Prototype rows that have not been read yet are not listed: they are not secrets of this API until something asks for one by name. `wp secret migrate-legacy --dry-run` is the way to see what is still sitting in the old format.

```php
public function list_names( $network = false )
```

| Parameter | Type | Description |
|---|---|---|
| `$network` | `bool` | Whether to list network-scope secrets. |

**Returns:** `array|WP_Error`

#### `Secrets_API_Prototype_Fallback_Store::set()`

Writes a current-format record. Delegated unchanged.

```php
public function set( $name, $record, $network = false )
```

| Parameter | Type | Description |
|---|---|---|
| `$name` | `string` | The secret's namespaced name. |
| `$record` | `array` | The record to store. |
| `$network` | `bool` | Whether this is a network-scope secret. |

**Returns:** `bool|WP_Error`

## `WP_Secret`

*final class* · implements `JsonSerializable`

A decrypted secret value, returned by wp_get_secret().

Masking is total and unconditional. Every representation short of an explicit call
to reveal() yields the placeholder '[secret:{name}]' -- printing, logging, JSON
encoding, or dumping an instance never exposes the plaintext.

The plaintext is never a declared property of this class. It lives in a private
static table keyed by spl_object_id(), which is what makes the masking unconditional
rather than something every magic method has to individually get right: var_export()
in particular ignores __debugInfo() and __toString() and serializes declared
properties directly, so if the plaintext lived in one there would be no way to mask
it there at all.

**Since:** 7.2.0

**Source:** [`src/wp-includes/class-wp-secret.php`](../../src/wp-includes/class-wp-secret.php)

### Methods

#### `WP_Secret::__clone()`

Refuses cloning outright.

A clone would be a second, unaudited reference to the same plaintext with an
independent lifetime -- and, since the vault is keyed by object id rather than
copied, a bare `clone` would otherwise leave the clone's reveal() silently
reading nothing.

```php
public function __clone()
```

**Throws:** `LogicException` Always.

**Since:** 7.2.0

#### `WP_Secret::__construct()`

Constructor.

Throws, where the rest of this API returns WP_Error for a caller mistake.
That is not an inconsistency to be tidied up later: a constructor has no
return channel, so the only alternatives are throwing or building a
half-valid WP_Secret and letting it fail somewhere less obvious. Every
function that *can* return WP_Error does -- see
this file's own reasoning below. The same rule covers the serialization
and clone refusals below, which are magic methods with the same problem
and an additional one: silently permitting them would leak a plaintext.

Nothing outside this API constructs a WP_Secret in normal use; the values
passed here come from _wp_secrets_get() having just decrypted them.

```php
public function __construct( $name, $value, $fingerprint )
```

| Parameter | Type | Description |
|---|---|---|
| `$name` | `string` | The secret's namespaced name. |
| `$value` | `string` | The decrypted plaintext. |
| `$fingerprint` | `string` | Keyed fingerprint of $value. |

**Throws:** `InvalidArgumentException` If any argument is not a non-empty string (value may be empty, but must be a string).

**Since:** 7.2.0

#### `WP_Secret::__debugInfo()`

Masks the instance for var_dump().

Note that print_r() and var_export() have no equivalent hook. They are safe
regardless, because the plaintext is never a declared property for them to
enumerate; they will surface the (non-sensitive) name and fingerprint rather
than this placeholder. See docs/journal/open-questions.md for the documented
var_export() limitation.

```php
public function __debugInfo(): array
```

**Returns:** `array`

**Since:** 7.2.0

#### `WP_Secret::__destruct()`

Zeroes the plaintext and removes it from the vault.

```php
public function __destruct()
```

**Since:** 7.2.0

#### `WP_Secret::__serialize()`

Refuses serialization outright.

PHP calls this in preference to __sleep() when both are defined, so both must
refuse independently.

```php
public function __serialize()
```

**Returns:** `void`

**Throws:** `LogicException` Always.

**Since:** 7.2.0

#### `WP_Secret::__sleep()`

Refuses serialization outright.

```php
public function __sleep()
```

**Returns:** `void`

**Throws:** `LogicException` Always.

**Since:** 7.2.0

#### `WP_Secret::__toString()`

Masks the instance for string conversion.

Covers error_log( $secret ) and any other implicit string coercion.

```php
public function __toString()
```

**Returns:** `string`

**Since:** 7.2.0

#### `WP_Secret::__unserialize()`

Refuses unserialization outright.

```php
public function __unserialize( $data )
```

| Parameter | Type | Description |
|---|---|---|
| `$data` | `array` | Ignored. |

**Returns:** `void`

**Throws:** `LogicException` Always.

**Since:** 7.2.0

#### `WP_Secret::__wakeup()`

Refuses unserialization outright.

```php
public function __wakeup()
```

**Returns:** `void`

**Throws:** `LogicException` Always.

**Since:** 7.2.0

#### `WP_Secret::fingerprint()`

Returns the keyed fingerprint of the plaintext.

Stable for a given value on a given site; not a value-recovery oracle.

```php
public function fingerprint()
```

**Returns:** `string`

**Since:** 7.2.0

#### `WP_Secret::get_name()`

Returns the secret's namespaced name.

```php
public function get_name()
```

**Returns:** `string`

**Since:** 7.2.0

#### `WP_Secret::jsonSerialize()`

Masks the instance for json_encode().

```php
public function jsonSerialize()
```

**Returns:** `string`

**Since:** 7.2.0

#### `WP_Secret::reveal()`

Returns the decrypted plaintext.

This is the only path to the stored plaintext anywhere in the API.

Returns WP_Error for a secret whose provider will not release the value to
PHP at all -- see withheld(). That case does not arise for the provider
WordPress ships, which decrypts eagerly and therefore always has a value in
hand, so most callers will never see it. It is in the signature because a
return type cannot be widened after adoption: leaving reveal() as
`string` would permanently foreclose brokered and use-only credentials,
which is a decision better made deliberately than by omission.

```php
public function reveal()
```

**Returns:** `string|WP_Error` The plaintext, or WP_Error if this provider does not release values to PHP.

**Since:** 7.2.0

#### `WP_Secret::withheld()`

Builds a secret whose value exists but is not available to PHP.

For a provider that can prove a credential exists, and can name and
fingerprint it, but will not release it -- typically because releasing it
would defeat the point of where it is held. Everything except reveal()
behaves normally, so such a secret still lists, still reports a stable
fingerprint, and still masks itself in every output path.

```php
public static function withheld( $name, $fingerprint, $reason )
```

| Parameter | Type | Description |
|---|---|---|
| `$name` | `string` | The secret's name. |
| `$fingerprint` | `string` | A stable per-site digest, from the provider. |
| `$reason` | `WP_Error` | Why the value cannot be produced. Returned verbatim by reveal(). |

**Returns:** `WP_Secret`

**Throws:** `InvalidArgumentException` If $reason is not a WP_Error, or if $name or $fingerprint fail the constructor's checks.

**Since:** 7.2.0

## `WP_Secret_Version`

*final class*

Identifies which slot of a secret's two-slot history to operate on.

String constants on a final class rather than a native enum: enums require PHP 8.1,
and this class ships in core, whose minimum supported PHP version is 7.4.

**Since:** 7.2.0

**Source:** [`src/wp-includes/class-wp-secret-version.php`](../../src/wp-includes/class-wp-secret-version.php)

### Constants

| Constant | Value | Description |
|---|---|---|
| `CURRENT` | `'current'` | The current value of a secret. |
| `PREVIOUS` | `'previous'` | The value a secret held immediately before its most recent overwrite. |

## `WP_Secrets_Broken_Keyring`

*final class* · implements `WP_Secrets_Keyring`

Stands in for the keyring when a drop-in leaves an invalid value behind.

Installed when a secrets.php drop-in exists but $GLOBALS['wp_secrets_keyring'] does
not hold a WP_Secrets_Keyring.

Same reasoning as WP_Secrets_Broken_Store: the drop-in's presence signals the
operator wants a keyring other than the default, so silently falling back to
WP_Secrets_Config_Key_Provider would wrap the root key under a key the operator
did not choose. Every operation fails closed instead.

**Since:** 7.2.0

**Source:** [`src/wp-includes/class-wp-secrets-broken-keyring.php`](../../src/wp-includes/class-wp-secrets-broken-keyring.php)

### Methods

#### `WP_Secrets_Broken_Keyring::get_key_source()`

Describes the broken state, for Site Health.

```php
public function get_key_source()
```

**Returns:** `string`

**Since:** 7.2.0

#### `WP_Secrets_Broken_Keyring::unwrap()`

Always fails closed.

```php
public function unwrap( $wrapped )
```

| Parameter | Type | Description |
|---|---|---|
| `$wrapped` | `string` | Ignored. |

**Returns:** `WP_Error`

**Since:** 7.2.0

#### `WP_Secrets_Broken_Keyring::wrap()`

Always fails closed.

```php
public function wrap( $key_material )
```

| Parameter | Type | Description |
|---|---|---|
| `$key_material` | `string` | Ignored. |

**Returns:** `WP_Error`

**Since:** 7.2.0

## `WP_Secrets_Broken_Provider`

*final class* · implements `WP_Secrets_Provider`

The provider installed when a secrets.php drop-in did not load correctly.

Every operation returns WP_Error. It exists so that a broken drop-in cannot be
mistaken for a working site with no secrets in it yet -- which is the failure
mode that turns "my credential backend is misconfigured" into "my credentials
appear to have been deleted," and is precisely what this API's three-state return
exists to prevent.

Deliberately not a fallback to the default provider. Reverting to local storage
because a host's KMS drop-in failed would quietly downgrade a site's protection
at the exact moment nobody is watching.

**Since:** 7.2.0

**Source:** [`src/wp-includes/class-wp-secrets-broken-provider.php`](../../src/wp-includes/class-wp-secrets-broken-provider.php)

### Methods

#### `WP_Secrets_Broken_Provider::delete()`

Always an error.

```php
public function delete( $name, $network = false )
```

| Parameter | Type | Description |
|---|---|---|
| `$name` | `string` | The secret's name. |
| `$network` | `bool` | Whether this is a network-scope secret. |

**Returns:** `WP_Error`

**Since:** 7.2.0

#### `WP_Secrets_Broken_Provider::get()`

Always an error.

```php
public function get( $name, $version, $network = false )
```

| Parameter | Type | Description |
|---|---|---|
| `$name` | `string` | The secret's name. |
| `$version` | `string` | A WP_Secret_Version constant. |
| `$network` | `bool` | Whether this is a network-scope secret. |

**Returns:** `WP_Error`

**Since:** 7.2.0

#### `WP_Secrets_Broken_Provider::get_label()`

Says plainly that the drop-in is the problem.

```php
public function get_label()
```

**Returns:** `string`

**Since:** 7.2.0

#### `WP_Secrets_Broken_Provider::get_protection_boundary()`

Reports the provider boundary.

Whatever was meant to protect these secrets is not WordPress, and is not
working.

```php
public function get_protection_boundary()
```

**Returns:** `string`

**Since:** 7.2.0

#### `WP_Secrets_Broken_Provider::is_writable()`

Nothing is writable while the drop-in is broken.

```php
public function is_writable()
```

**Returns:** `bool`

**Since:** 7.2.0

#### `WP_Secrets_Broken_Provider::list_secrets()`

Always an error.

```php
public function list_secrets( $name_prefix = '', $network = false )
```

| Parameter | Type | Description |
|---|---|---|
| `$name_prefix` | `string` | Restrict to names beginning with this prefix. |
| `$network` | `bool` | Whether to list network-scope secrets. |

**Returns:** `WP_Error`

**Since:** 7.2.0

#### `WP_Secrets_Broken_Provider::retire_previous()`

Always an error.

```php
public function retire_previous( $name, $network = false )
```

| Parameter | Type | Description |
|---|---|---|
| `$name` | `string` | The secret's name. |
| `$network` | `bool` | Whether this is a network-scope secret. |

**Returns:** `WP_Error`

**Since:** 7.2.0

#### `WP_Secrets_Broken_Provider::set()`

Always an error.

```php
public function set( $name, $value, $network = false, $needs_rotation = false, $action = null )
```

| Parameter | Type | Description |
|---|---|---|
| `$name` | `string` | The secret's name. |
| `$value` | `string` | The plaintext value. |
| `$network` | `bool` | Whether this is a network-scope secret. |
| `$needs_rotation` | `bool` | Ignored. |
| `$action` | `string\|null` | Ignored. |

**Returns:** `WP_Error`

**Since:** 7.2.0

## `WP_Secrets_Broken_Store`

*final class* · implements `WP_Secrets_Store`

Stands in for the store when a drop-in leaves an invalid value behind.

Installed when a secrets.php drop-in exists but $GLOBALS['wp_secrets_store'] does
not hold a WP_Secrets_Store.

The drop-in's presence signals the operator wants storage other than the
default -- falling back to WP_Secrets_Option_Store here would silently write
secrets to local options against that intent, which is exactly the silent
downgrade to local storage this API refuses to make. Every operation fails
closed instead.

**Since:** 7.2.0

**Source:** [`src/wp-includes/class-wp-secrets-broken-store.php`](../../src/wp-includes/class-wp-secrets-broken-store.php)

### Methods

#### `WP_Secrets_Broken_Store::delete()`

Always fails closed.

```php
public function delete( $name, $network = false )
```

| Parameter | Type | Description |
|---|---|---|
| `$name` | `string` | Ignored. |
| `$network` | `bool` | Ignored. |

**Returns:** `WP_Error`

**Since:** 7.2.0

#### `WP_Secrets_Broken_Store::get()`

Always fails closed.

```php
public function get( $name, $network = false )
```

| Parameter | Type | Description |
|---|---|---|
| `$name` | `string` | Ignored. |
| `$network` | `bool` | Ignored. |

**Returns:** `WP_Error`

**Since:** 7.2.0

#### `WP_Secrets_Broken_Store::list_names()`

Always fails closed.

```php
public function list_names( $network = false )
```

| Parameter | Type | Description |
|---|---|---|
| `$network` | `bool` | Ignored. |

**Returns:** `WP_Error`

**Since:** 7.2.0

#### `WP_Secrets_Broken_Store::set()`

Always fails closed.

```php
public function set( $name, $record, $network = false )
```

| Parameter | Type | Description |
|---|---|---|
| `$name` | `string` | Ignored. |
| `$record` | `array` | Ignored. |
| `$network` | `bool` | Ignored. |

**Returns:** `WP_Error`

**Since:** 7.2.0

## `WP_Secrets_Cipher`

*final class*

Encrypts and decrypts a single record slot.

A slot holds a per-secret data key wrapped by a master key, plus a value encrypted
under that data key.

This class does not know where a master key comes from (that is
WP_Secrets_Key_Manager's job), does not know about the 'v'/current/previous record
envelope (that is assembled by the functions in secrets.php), and does not set
'created' or 'needs_rotation' (those are facts about a write, not about the
cryptography). It knows exactly one thing: given a 32-byte master key and a single
slot's worth of material, encrypt or decrypt it correctly.

**Since:** 7.2.0

**Source:** [`src/wp-includes/class-wp-secrets-cipher.php`](../../src/wp-includes/class-wp-secrets-cipher.php)

### Constants

| Constant | Value | Description |
|---|---|---|
| `AAD_DATA_KEY` | `'wp-secrets-data-key-v1'` | AAD purpose tag for a wrapped data key. |
| `AAD_VALUE` | `'wp-secrets-value-v1'` | AAD purpose tag for an encrypted value. |
| `FINGERPRINT_KDF_CONTEXT` | `'wpsecfpr'` | KDF context used to derive the fingerprint key from a master key. |

### Methods

#### `WP_Secrets_Cipher::decrypt_value()`

Decrypts a single record slot back to its plaintext.

```php
public function decrypt_value( $master_key, $scope, $site_id, $name, $slot, $record )
```

| Parameter | Type | Description |
|---|---|---|
| `$master_key` | `string` | 32-byte master key for this scope. |
| `$scope` | `string` | 'site' or 'network'. Must match what encrypt_value() was called with, or decryption fails. |
| `$site_id` | `int` | Must match what encrypt_value() was called with. |
| `$name` | `string` | Must match what encrypt_value() was called with. |
| `$slot` | `string` | Must match what encrypt_value() was called with. |
| `$record` | `mixed` | The slot array previously returned by encrypt_value(). |

**Returns:** `string|WP_Error` Plaintext on success. WP_Error on failure.

**Since:** 7.2.0

#### `WP_Secrets_Cipher::encrypt_value()`

Encrypts a plaintext into a single record slot.

```php
public function encrypt_value( $master_key, $scope, $site_id, $name, $slot, $plaintext )
```

| Parameter | Type | Description |
|---|---|---|
| `$master_key` | `string` | 32-byte master key for this scope. |
| `$scope` | `string` | 'site' or 'network'. |
| `$site_id` | `int` | Binds the AAD to a specific blog for site scope; use 0 for network scope, which is not bound to any one blog. |
| `$name` | `string` | The secret's namespaced name. |
| `$slot` | `string` | A WP_Secret_Version constant. |
| `$plaintext` | `string` | The value to encrypt. |

**Returns:** `array|WP_Error` Slot array with keys 'dk', 'dk_nonce', 'ct', 'nonce', 'fingerprint' on success. WP_Error on failure.

**Since:** 7.2.0

#### `WP_Secrets_Cipher::fingerprint()`

Computes the keyed fingerprint of a plaintext under a master key.

Keyed so a fingerprint is not a cross-site rainbow-table oracle: the same
plaintext fingerprints differently under a different master key. Callers
verifying a value against a previously stored fingerprint (for example, a
migration's verify-before-delete step) must recompute this from freshly
decrypted plaintext and compare -- never trust a fingerprint read back from a
record, which sits outside the AAD and is not authenticated.

```php
public function fingerprint( $master_key, $plaintext )
```

| Parameter | Type | Description |
|---|---|---|
| `$master_key` | `string` | 32-byte master key. |
| `$plaintext` | `string` | Value to fingerprint. |

**Returns:** `string|WP_Error` 32-character hex string on success. WP_Error on failure.

**Since:** 7.2.0

## `WP_Secrets_Config_Key_Provider`

*final class* · implements `WP_Secrets_Keyring`

The default keyring.

Derives a site key from wp-config.php and uses it to wrap and unwrap the root key
with authenticated encryption.

Site key derivation, in priority order:

1. WP_SECRETS_KEY is defined and base64-decodes (strictly) to exactly 32 bytes:
   those decoded bytes are used raw. This is the documented, recommended form;
   `wp secret generate-key` emits it.
2. WP_SECRETS_KEY is defined in any other shape: the literal constant string is
   hashed with a keyed BLAKE2b to 32 bytes. This is the legacy interpretation --
   sites arriving from a prior plugin with a constant of arbitrary shape are not
   locked out of their own credentials by a hard failure here.
3. WP_SECRETS_KEY is undefined: LOGGED_IN_KEY . LOGGED_IN_SALT is hashed the same
   way. Deliberately byte-identical to the legacy interpretation's hashing, not a
   coincidence -- it is what makes salt-fallback sites migrate with zero
   credential re-entry.

WP_SECRETS_KEY_PREVIOUS follows the same three rules and exists only so a site-key
rotation can unwrap under the old key before wrapping under the new one.

**Since:** 7.2.0

**Source:** [`src/wp-includes/class-wp-secrets-config-key-provider.php`](../../src/wp-includes/class-wp-secrets-config-key-provider.php)

### Constants

| Constant | Value | Description |
|---|---|---|
| `AAD` | `'wp-secrets-root-key-v1'` | AAD binding the root key's wrapped form to this purpose. |
| `KNOWN_PLACEHOLDER` | `'put your unique phrase here'` | The exact text wp-config-sample.php ships for every auth and salt constant. |

### Methods

#### `WP_Secrets_Config_Key_Provider::__construct()`

Constructor.

```php
public function __construct( $use_previous_key = false )
```

| Parameter | Type | Description |
|---|---|---|
| `$use_previous_key` | `bool` | Derive from WP_SECRETS_KEY_PREVIOUS rather than WP_SECRETS_KEY. Used only during a site-key rotation, to unwrap under the outgoing key. |

**Since:** 7.2.0

#### `WP_Secrets_Config_Key_Provider::get_key_source()`

Describes the active key source, for Site Health.

```php
public function get_key_source()
```

**Returns:** `string`

**Since:** 7.2.0

#### `WP_Secrets_Config_Key_Provider::unwrap()`

Unwraps key material previously wrapped by wrap().

```php
public function unwrap( $wrapped )
```

| Parameter | Type | Description |
|---|---|---|
| `$wrapped` | `string` | An opaque value previously returned by wrap(). |

**Returns:** `string|WP_Error`

**Since:** 7.2.0

#### `WP_Secrets_Config_Key_Provider::wrap()`

Wraps raw key material under the derived site key.

```php
public function wrap( $key_material )
```

| Parameter | Type | Description |
|---|---|---|
| `$key_material` | `string` | Raw key material to protect. |

**Returns:** `string|WP_Error`

**Since:** 7.2.0

## `WP_Secrets_Key_Manager`

*final class*

Manages the root key and derives per-scope master keys from it.

Owns the root key's generation, storage, and rotation.

There is exactly one root key per install: 32 random bytes, generated once, wrapped
by the active WP_Secrets_Keyring, and stored via update_site_option() (which is a
plain option on single-site, and network-wide on multisite). It is the only value a
site-key rotation ever re-wraps, on a single site or on a 500-site network.

Master keys are derived from the root key on demand and never stored:

- Site scope, blog N: sodium_crypto_kdf_derive_from_key( 32, N, 'wpsecsit', $root ).
  Distinct per blog, which shared network-wide salts otherwise cannot provide.
- Network scope: sodium_crypto_kdf_derive_from_key( 32, 0, 'wpsecnet', $root ).
  Subkey id 0 is reserved for this (blog ids start at 1) and the context string
  differs from the site path, so there is no collision between the two. Identical
  on every blog, so a network secret written on one blog reads on every other.

**Since:** 7.2.0

**Source:** [`src/wp-includes/class-wp-secrets-key-manager.php`](../../src/wp-includes/class-wp-secrets-key-manager.php)

### Constants

| Constant | Value | Description |
|---|---|---|
| `NETWORK_KDF_CONTEXT` | `'wpsecnet'` | KDF context for deriving the network-scope master key. Exactly 8 bytes. |
| `NETWORK_SUBKEY_ID` | `0` | Reserved KDF subkey id for network scope. |
| `ROOT_KEY_OPTION` | `'_wp_secrets_root_key'` | Option name the wrapped root key is stored under. |
| `SITE_KDF_CONTEXT` | `'wpsecsit'` | KDF context for deriving a site-scope master key. Exactly 8 bytes. |

### Methods

#### `WP_Secrets_Key_Manager::__construct()`

Constructor.

```php
public function __construct( ?WP_Secrets_Keyring $keyring = null )
```

| Parameter | Type | Description |
|---|---|---|
| `$keyring` | `WP_Secrets_Keyring\|null` | Keyring to use. Defaults to the built-in config-based provider. |

**Since:** 7.2.0

#### `WP_Secrets_Key_Manager::get_keyring()`

Returns the keyring in use.

Used by Site Health and by `wp secret dropin` and `wp secret health`, which all
need to describe the active keyring without duplicating the logic that resolves
it.

```php
public function get_keyring()
```

**Returns:** `WP_Secrets_Keyring`

**Since:** 7.2.0

#### `WP_Secrets_Key_Manager::get_master_key()`

Derives a scope's master key from the root key.

```php
public function get_master_key( $scope, $site_id = null )
```

| Parameter | Type | Description |
|---|---|---|
| `$scope` | `string` | 'site' or 'network'. |
| `$site_id` | `int\|null` | Blog id for site scope. Defaults to the current blog. Ignored for network scope. |

**Returns:** `string|WP_Error` 32-byte master key on success. WP_Error on failure, including when a caller passes an invalid scope or site id -- see WP_Secrets_Cipher::validate_common() for why that is a WP_Error and not an exception.

**Since:** 7.2.0

#### `WP_Secrets_Key_Manager::get_root_key()`

Returns the root key, generating and persisting one on first use.

```php
public function get_root_key()
```

**Returns:** `string|WP_Error` 32 raw bytes on success. WP_Error on failure.

**Since:** 7.2.0

#### `WP_Secrets_Key_Manager::rotate_site_key()`

Re-wraps the root key under a new keyring, without touching any stored secret.

Every derived master key is unchanged by this: they come from the root key's
raw bytes, which rotation never alters, only its wrapping. No secret value is
ever re-encrypted as a result of a site-key rotation.

```php
public function rotate_site_key( WP_Secrets_Keyring $old_keyring, WP_Secrets_Keyring $new_keyring )
```

| Parameter | Type | Description |
|---|---|---|
| `$old_keyring` | `WP_Secrets_Keyring` | Unwraps the current wrapped root key. |
| `$new_keyring` | `WP_Secrets_Keyring` | Wraps it again for storage. |

**Returns:** `true|WP_Error`

**Since:** 7.2.0

## `WP_Secrets_Keyring`

*interface*

Wraps and unwraps the root key that everything else in this API derives from.

A KMS or HSM lives behind this interface in a production deployment. An
implementation is never handed a plaintext secret, only 32 bytes of key material,
and cannot turn encryption off. There is no method here that
accepts a plaintext secret value at all.

**Since:** 7.2.0

**Source:** [`src/wp-includes/interface-wp-secrets-keyring.php`](../../src/wp-includes/interface-wp-secrets-keyring.php)

### Methods

#### `WP_Secrets_Keyring::get_key_source()`

A human-readable description of the key source, for Site Health.

```php
public function get_key_source()
```

**Returns:** `string`

**Since:** 7.2.0

#### `WP_Secrets_Keyring::unwrap()`

Unwraps (decrypts) previously wrapped key material.

```php
public function unwrap( $wrapped )
```

| Parameter | Type | Description |
|---|---|---|
| `$wrapped` | `string` | An opaque value previously returned by wrap(). |

**Returns:** `string|WP_Error` Raw key material on success, WP_Error on failure.

**Since:** 7.2.0

#### `WP_Secrets_Keyring::wrap()`

Wraps (encrypts) raw key material for storage.

```php
public function wrap( $key_material )
```

| Parameter | Type | Description |
|---|---|---|
| `$key_material` | `string` | Raw key material to protect. |

**Returns:** `string|WP_Error` Opaque wrapped value on success, WP_Error on failure.

**Since:** 7.2.0

## `WP_Secrets_Libsodium_Provider`

*final class* · implements `WP_Secrets_Provider`

The provider WordPress ships.

Uses libsodium envelope encryption and stores ciphertext in the options tables.

This is the default rather than a privileged case. Everything it does is expressed
through WP_Secrets_Provider, the same interface a platform implements, so a KMS-
or HSM-backed provider is a peer rather than an exception carved into the side of
this one.

It is assembled from two smaller pieces, and that is deliberate: a host who wants
their own key custody but is happy with WordPress's storage swaps only the
keyring and keeps everything else, and the inverse works too. Coupling them would
force an all-or-nothing decision most sites cannot make.

- WP_Secrets_Store   -- where the ciphertext records live.
- WP_Secrets_Keyring -- what wraps the root key everything else derives from.

Neither is handed a plaintext, because for this provider the encryption boundary
genuinely is inside WordPress. That is a property of *this* implementation rather
than a rule imposed on every provider; see WP_Secrets_Provider for why the
distinction matters.

**Since:** 7.2.0

**Source:** [`src/wp-includes/class-wp-secrets-libsodium-provider.php`](../../src/wp-includes/class-wp-secrets-libsodium-provider.php)

### Methods

#### `WP_Secrets_Libsodium_Provider::__construct()`

Composes the provider from its two replaceable halves.

```php
public function __construct( WP_Secrets_Store $store, WP_Secrets_Key_Manager $key_manager )
```

| Parameter | Type | Description |
|---|---|---|
| `$store` | `WP_Secrets_Store` | Where ciphertext records live. |
| `$key_manager` | `WP_Secrets_Key_Manager` | Derives per-scope master keys. |

**Since:** 7.2.0

#### `WP_Secrets_Libsodium_Provider::delete()`

Shared implementation behind wp_delete_secret() and wp_delete_network_secret().

```php
public function delete( $name, $network = false )
```

| Parameter | Type | Description |
|---|---|---|
| `$name` | `string` | The secret's namespaced name. |
| `$network` | `bool` | Whether this is a network-scope secret. |

**Returns:** `true|WP_Error`

**Since:** 7.2.0

#### `WP_Secrets_Libsodium_Provider::get()`

Shared implementation behind wp_get_secret() and wp_get_network_secret().

```php
public function get( $name, $version, $network = false )
```

| Parameter | Type | Description |
|---|---|---|
| `$name` | `string` | The secret's namespaced name. |
| `$version` | `string` | A WP_Secret_Version constant. |
| `$network` | `bool` | Whether this is a network-scope secret. |

**Returns:** `WP_Secret|null|WP_Error`

**Since:** 7.2.0

#### `WP_Secrets_Libsodium_Provider::get_label()`

A description of what is protecting these secrets, for Site Health.

Reports the keyring's own description rather than a fixed string: "libsodium"
is only half the answer, and which key source is in use is the half an
operator cannot otherwise see.

```php
public function get_label()
```

**Returns:** `string`

**Since:** 7.2.0

#### `WP_Secrets_Libsodium_Provider::get_protection_boundary()`

Protection happens inside WordPress for this provider.

```php
public function get_protection_boundary()
```

**Returns:** `string`

**Since:** 7.2.0

#### `WP_Secrets_Libsodium_Provider::is_writable()`

Always true.

A store that cannot accept a write reports that from set() itself. There is no
separate capability flag to consult, and the shipped store accepts writes
unconditionally.

```php
public function is_writable()
```

**Returns:** `bool`

**Since:** 7.2.0

#### `WP_Secrets_Libsodium_Provider::list_secrets()`

Shared implementation behind wp_list_secrets() and wp_list_network_secrets().

Beyond the API surface the proposal names, and justified by its statement that
"the hooks and accessors an admin screen would need are in scope now; the screen
itself is not."

Fingerprints returned here come directly from the stored record field, not
recomputed by decrypting each secret. That is a deliberate difference from
WP_Secret::fingerprint(), which always recomputes -- recomputing here would mean
decrypting every matching secret just to list them, defeating the point of a
lightweight listing call. This is safe specifically because a list entry is
documented as informational only and
is never used to gate anything; nothing in this codebase performs a security
decision based on a fingerprint returned from this function.

```php
public function list_secrets( $name_prefix = '', $network = false )
```

| Parameter | Type | Description |
|---|---|---|
| `$name_prefix` | `string` | Only secrets whose name starts with "{$name_prefix}/" are returned. Default '' returns every secret in this scope. Named to match the public function's own $namespace parameter, without using the reserved word 'namespace' in an internal signature. |
| `$network` | `bool` | Whether to list network-scope secrets. |

**Returns:** `array|WP_Error` Array of associative arrays, each with keys 'name', 'fingerprint', 'created', 'has_previous', and 'needs_rotation'. Never a value. WP_Error on failure.

**Since:** 7.2.0

#### `WP_Secrets_Libsodium_Provider::retire_previous()`

Shared implementation behind the two version-retirement functions.

Backs wp_retire_secret_version() and wp_retire_network_secret_version().

Beyond the API surface the proposal names. It states that retirement is "an
explicit operator action" but names no function; this is this implementation's
name for it, pending confirmation in the comments thread.

```php
public function retire_previous( $name, $network = false )
```

| Parameter | Type | Description |
|---|---|---|
| `$name` | `string` | The secret's namespaced name. |
| `$network` | `bool` | Whether this is a network-scope secret. |

**Returns:** `true|WP_Error`

**Since:** 7.2.0

#### `WP_Secrets_Libsodium_Provider::set()`

Shared implementation behind the three secret-writing functions.

Backs wp_set_secret(), wp_set_network_secret(), and wp_import_option_as_secret(),
which select their behavior through the last two parameters.

```php
public function set( $name, $value, $network = false, $needs_rotation = false, $action = null )
```

| Parameter | Type | Description |
|---|---|---|
| `$name` | `string` | The secret's namespaced name. |
| `$value` | `string` | The plaintext value. |
| `$network` | `bool` | Whether this is a network-scope secret. |
| `$needs_rotation` | `bool` | Value for the new current slot's 'needs_rotation' flag. False for an ordinary write; wp_import_option_as_secret() passes true, since a credential that sat in an option is already in backups and re-encrypting does not fix that. |
| `$action` | `string\|null` | When given, used as the $action reported to the wp_secret_changed hook instead of the usual 'created'/'updated' detection -- wp_import_option_as_secret() passes 'imported'. |

**Returns:** `true|WP_Error`

**Since:** 7.2.0

## `WP_Secrets_Option_Store`

*final class* · implements `WP_Secrets_Store`

The default secret store.

Keeps records as rows in the options tables, always with autoload disabled, and
always excluded from options.php and the REST settings endpoint.

Site-scope secrets live under '_wp_secret_{name}' via get_option()/update_option().
Network-scope secrets live under '_wp_network_secret_{name}' via the *_site_option()
functions, which on a non-multisite install are themselves backed by wp_options --
so on a single site, site- and network-scope secrets differ only by prefix, in the
same table; on a real network, network-scope rows live in wp_sitemeta instead.

**Since:** 7.2.0

**Source:** [`src/wp-includes/class-wp-secrets-option-store.php`](../../src/wp-includes/class-wp-secrets-option-store.php)

### Constants

| Constant | Value | Description |
|---|---|---|
| `NETWORK_PREFIX` | `'_wp_network_secret_'` | Option name prefix for network-scope secrets. |
| `SITE_PREFIX` | `'_wp_secret_'` | Option name prefix for site-scope secrets. |

### Methods

#### `WP_Secrets_Option_Store::delete()`

Deletes a secret's record.

```php
public function delete( $name, $network = false )
```

| Parameter | Type | Description |
|---|---|---|
| `$name` | `string` | The secret's namespaced name. |
| `$network` | `bool` | Whether this is a network-scope secret. |

**Returns:** `true|WP_Error`

**Since:** 7.2.0

#### `WP_Secrets_Option_Store::get()`

Reads a secret's stored record.

```php
public function get( $name, $network = false )
```

| Parameter | Type | Description |
|---|---|---|
| `$name` | `string` | The secret's namespaced name. |
| `$network` | `bool` | Whether this is a network-scope secret. |

**Returns:** `array|null|WP_Error`

**Since:** 7.2.0

#### `WP_Secrets_Option_Store::list_names()`

Lists the names of every secret in this store, for this scope.

```php
public function list_names( $network = false )
```

| Parameter | Type | Description |
|---|---|---|
| `$network` | `bool` | Whether to list network-scope secrets. |

**Returns:** `array|WP_Error`

**Since:** 7.2.0

#### `WP_Secrets_Option_Store::set()`

Writes a secret's record.

```php
public function set( $name, $record, $network = false )
```

| Parameter | Type | Description |
|---|---|---|
| `$name` | `string` | The secret's namespaced name. |
| `$record` | `array` | The record to store. |
| `$network` | `bool` | Whether this is a network-scope secret. |

**Returns:** `true|WP_Error`

**Since:** 7.2.0

## `WP_Secrets_Provider`

*interface*

Interface for a secrets provider.

A provider is responsible for a secret: storing it, protecting it, and returning
it to a caller. It is the outermost extension point in the Secrets API, and the
one a hosting platform implements.

WordPress ships WP_Secrets_Libsodium_Provider, which encrypts with libsodium and
stores ciphertext in the options tables. That provider is the default rather than
a privileged case: a platform that protects credentials in a key management
service, a hardware security module, or its own control panel implements this
same interface.

A provider must be stronger than the default, never weaker. Storing a plaintext
where the default would have stored ciphertext is what this interface exists to
prevent. Receiving a value over an authenticated channel and holding it in a
hardware security module satisfies that requirement.

WordPress cannot verify any of this. A provider is loaded from a drop-in, which is
fully trusted code: it runs before plugins, and could already read every secret by
implementing the keyring. get_protection_boundary() and is_writable() are
declarations rather than enforcement. They exist so that a developer reviewing a
drop-in can see what it claims, Site Health can report where credentials are
protected, and a settings screen can determine that a write will be refused before
an operator enters a credential. They are not a security boundary.

Every implementation shares these contracts:

- Three states, never collapsed. get() returns a WP_Secret, null when the secret
  does not exist, or WP_Error when it exists but cannot be produced. Reporting an
  unreachable backend as absent turns an outage into an apparently deleted
  credential.
- Fail closed. A provider that cannot answer returns WP_Error. It never
  substitutes a weaker source, and never returns a partial or placeholder value.
- No filter on the retrieval path. A provider replaces a component; it is not a
  hook. Nothing is given the opportunity to observe or alter a value in transit.
- Never persist a value more weakly than the provider protects it. A provider that
  fetches over the network must not write the plaintext into the persistent object
  cache. WP_Secret cannot round-trip a plaintext through wp_cache_set(), and
  caching the raw value alongside it would undo that. Memoize within the request
  only.

**Since:** 7.2.0

**Source:** [`src/wp-includes/interface-wp-secrets-provider.php`](../../src/wp-includes/interface-wp-secrets-provider.php)

### Constants

| Constant | Value | Description |
|---|---|---|
| `BOUNDARY_PROVIDER` | `'provider'` | Protection happens outside WordPress. |
| `BOUNDARY_WORDPRESS` | `'wordpress'` | Protection happens inside WordPress. |

### Methods

#### `WP_Secrets_Provider::delete()`

Deletes a secret.

Deleting a secret that does not exist is a success.

```php
public function delete( $name, $network = false )
```

| Parameter | Type | Description |
|---|---|---|
| `$name` | `string` | The secret's name. |
| `$network` | `bool` | Whether this is a network-scope secret. |

**Returns:** `true|WP_Error`

**Since:** 7.2.0

#### `WP_Secrets_Provider::get()`

Retrieves a secret.

Returning null means "I am not responsible for this name" as well as "this
name does not exist". The two are the same answer from a caller's point of
view, and keeping them distinct would require a provider to enumerate names
it has never heard of.

```php
public function get( $name, $version, $network = false )
```

| Parameter | Type | Description |
|---|---|---|
| `$name` | `string` | The secret's name. |
| `$version` | `string` | A WP_Secret_Version constant. A provider with no version history returns null for PREVIOUS, which is absence rather than an error. |
| `$network` | `bool` | Whether this is a network-scope secret. |

**Returns:** `WP_Secret|null|WP_Error`

**Since:** 7.2.0

#### `WP_Secrets_Provider::get_label()`

A short human-readable name for this provider, for Site Health.

Describes the protection, not the vendor's marketing: "AWS KMS
(alias/wp-secrets)" tells an operator something, "SuperSecure Pro" does not.
Never key material, never a credential, never a value.

```php
public function get_label()
```

**Returns:** `string`

**Since:** 7.2.0

#### `WP_Secrets_Provider::get_protection_boundary()`

Where secrets this provider holds are actually protected.

One of the BOUNDARY_* constants. This is what lets Site Health and a future
admin screen tell an operator whether their credentials are protected by
WordPress's own libsodium envelope or by something outside it, a question
they currently have no way to answer, and the one hosts asked to be able to
answer honestly.

```php
public function get_protection_boundary()
```

**Returns:** `string`

**Since:** 7.2.0

#### `WP_Secrets_Provider::is_writable()`

Whether wp_set_secret() can succeed against this provider.

False for a provider whose credentials are managed by host tooling or a
control panel. Declared rather than discovered so a settings screen can
disable its save control before an operator types a credential into a field
that will only reject it.

```php
public function is_writable()
```

**Returns:** `bool`

**Since:** 7.2.0

#### `WP_Secrets_Provider::list_secrets()`

Lists secrets by name and metadata. Never values.

```php
public function list_secrets( $name_prefix = '', $network = false )
```

| Parameter | Type | Description |
|---|---|---|
| `$name_prefix` | `string` | Restrict to names beginning with this prefix, or '' for all of them. |
| `$network` | `bool` | Whether to list network-scope secrets. |

**Returns:** `array|WP_Error` List of arrays with keys 'name', 'fingerprint', 'created', 'has_previous', 'needs_rotation'.

**Since:** 7.2.0

#### `WP_Secrets_Provider::retire_previous()`

Clears a secret's previous version, if this provider keeps one.

A provider with no version history treats this as a successful no-op: there
is nothing to retire, which is the state the caller asked for.

```php
public function retire_previous( $name, $network = false )
```

| Parameter | Type | Description |
|---|---|---|
| `$name` | `string` | The secret's name. |
| `$network` | `bool` | Whether this is a network-scope secret. |

**Returns:** `true|WP_Error`

**Since:** 7.2.0

#### `WP_Secrets_Provider::set()`

Stores a secret.

A provider whose credentials are managed elsewhere, by a control panel, host
tooling, or a KMS with its own access policy, returns WP_Error here with code
WP_SECRETS_ERROR_PROVIDER_READ_ONLY, and reports false from is_writable() so
that callers can find that out without attempting the write first.

Implementations are responsible for firing the `wp_secret_changed` action. The
API does not fire it on the provider's behalf, because only the provider knows
the prior fingerprint without paying for an additional read. An audit hook that
stopped firing once a host installed a provider would be unreliable in exactly
the situation it matters most.

```php
public function set( $name, $value, $network = false, $needs_rotation = false, $action = null )
```

| Parameter | Type | Description |
|---|---|---|
| `$name` | `string` | The secret's name. |
| `$value` | `string` | The plaintext value. |
| `$network` | `bool` | Whether this is a network-scope secret. |
| `$needs_rotation` | `bool` | Mark the stored secret as needing rotation. Set for values that arrived from somewhere less protected than this provider, such as an imported option. A provider with nowhere to record this may ignore it, but must not report it as honored. |
| `$action` | `string\|null` | Overrides the action reported to `wp_secret_changed`; null means the provider decides between 'created' and 'updated'. |

**Returns:** `true|WP_Error`

**Since:** 7.2.0

## `WP_Secrets_Store`

*interface*

Where a secret's encrypted record lives.

An implementation is never handed a plaintext secret, only the record array
produced by WP_Secrets_Cipher and assembled by the functions in secrets.php, and
cannot turn encryption off; there is no method here that accepts or returns
anything but ciphertext-bearing structures.

A platform store that is read-only from the application, with credentials managed
by a separate CLI or dashboard, returns WP_Error from set() rather than pretending
the write succeeded. Providers declare writability up front through
WP_Secrets_Provider::is_writable().

**Since:** 7.2.0

**Source:** [`src/wp-includes/interface-wp-secrets-store.php`](../../src/wp-includes/interface-wp-secrets-store.php)

### Methods

#### `WP_Secrets_Store::delete()`

Deletes a secret's record.

```php
public function delete( $name, $network = false )
```

| Parameter | Type | Description |
|---|---|---|
| `$name` | `string` | The secret's namespaced name. |
| `$network` | `bool` | Whether this is a network-scope secret. |

**Returns:** `bool|WP_Error` True on success (including if it did not exist). WP_Error on failure.

**Since:** 7.2.0

#### `WP_Secrets_Store::get()`

Reads a secret's stored record.

```php
public function get( $name, $network = false )
```

| Parameter | Type | Description |
|---|---|---|
| `$name` | `string` | The secret's namespaced name. |
| `$network` | `bool` | Whether this is a network-scope secret. |

**Returns:** `array|null|WP_Error` The record array if it exists. Null if it does not. WP_Error if it could not be determined which.

**Since:** 7.2.0

#### `WP_Secrets_Store::list_names()`

Lists the names of every secret in this store, for this scope. Never a value.

```php
public function list_names( $network = false )
```

| Parameter | Type | Description |
|---|---|---|
| `$network` | `bool` | Whether to list network-scope secrets. |

**Returns:** `array|WP_Error` Array of secret names on success. WP_Error on failure.

**Since:** 7.2.0

#### `WP_Secrets_Store::set()`

Writes a secret's record.

```php
public function set( $name, $record, $network = false )
```

| Parameter | Type | Description |
|---|---|---|
| `$name` | `string` | The secret's namespaced name. |
| `$record` | `array` | The record to store. |
| `$network` | `bool` | Whether this is a network-scope secret. |

**Returns:** `bool|WP_Error` True on success. WP_Error on failure, including when the store does not accept writes.

**Since:** 7.2.0
