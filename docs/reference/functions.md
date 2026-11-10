---
title: "Functions"
description: "Every function the plugin declares, with signature, parameters, return value, and source file, generated from docblocks."
---

<!--
  GENERATED FILE. Do not edit by hand.
  Produced by bin/gen-reference.php from docblocks in the PHP source.
  Edit the docblock, then run `php bin/gen-reference.php`. CI fails when this
  file no longer matches the source.
-->

# Functions

Functions declared under `src/` are core-bound. Functions declared in `secrets-api.php` belong to the plugin's own bootstrap and are not proposed for core.

## `wp_delete_network_secret()`

Deletes a network-scope secret.

```php
function wp_delete_network_secret( string $name )
```

| Parameter | Type | Description |
|---|---|---|
| `$name` | `string` | The secret's namespaced name. |

**Returns:** `true|WP_Error`

**Since:** 7.2.0

**Source:** [`src/wp-includes/secrets.php`](../../src/wp-includes/secrets.php)

## `wp_delete_secret()`

Deletes a secret.

```php
function wp_delete_secret( string $name )
```

| Parameter | Type | Description |
|---|---|---|
| `$name` | `string` | The secret's namespaced name. |

**Returns:** `true|WP_Error`

**Since:** 7.2.0

**Source:** [`src/wp-includes/secrets.php`](../../src/wp-includes/secrets.php)

## `wp_get_network_secret()`

Retrieves a network-scope secret.

```php
function wp_get_network_secret( string $name, string $version = WP_Secret_Version::CURRENT )
```

| Parameter | Type | Description |
|---|---|---|
| `$name` | `string` | The secret's namespaced name. |
| `$version` | `string` | A WP_Secret_Version constant. Default WP_Secret_Version::CURRENT. |

**Returns:** `WP_Secret|null|WP_Error`

**Since:** 7.2.0

**Source:** [`src/wp-includes/secrets.php`](../../src/wp-includes/secrets.php)

## `wp_get_secret()`

Retrieves a secret.

Three states, never collapsed: a WP_Secret if it exists and decrypts, null if it
does not exist, WP_Error if it exists but could not be retrieved. No capability
check is applied here. See wp_set_secret().

```php
function wp_get_secret( string $name, string $version = WP_Secret_Version::CURRENT )
```

| Parameter | Type | Description |
|---|---|---|
| `$name` | `string` | The secret's namespaced name. |
| `$version` | `string` | A WP_Secret_Version constant. Default WP_Secret_Version::CURRENT. |

**Returns:** `WP_Secret|null|WP_Error`

**Since:** 7.2.0

**Source:** [`src/wp-includes/secrets.php`](../../src/wp-includes/secrets.php)

## `wp_import_option_as_secret()`

Imports an existing option's value as a secret.

For an explicit migration on the caller's own schedule, because core cannot
reliably tell which options are credentials, and guessing would break sites. The
source option is left untouched: this reads it, it does not move or delete it.

The imported secret is flagged needs_rotation, unconditionally. A credential that
sat in a plain option has already been through however many backups and
replication paths that option went through; re-encrypting it here does not undo
that, and the flag exists so an operator (or a future admin screen) knows to
actually rotate the value rather than considering the migration finished.

```php
function wp_import_option_as_secret( string $option, string $name )
```

| Parameter | Type | Description |
|---|---|---|
| `$option` | `string` | The existing option's name. |
| `$name` | `string` | The secret's namespaced name to store it under. |

**Returns:** `true|WP_Error`

**Since:** 7.2.0

**Source:** [`src/wp-includes/secrets.php`](../../src/wp-includes/secrets.php)

## `wp_is_secrets_api_available()`

Whether the Secrets API can store and read secrets on this site.

A plugin can check this before it moves a credential into the Secrets API, and
keep its existing storage while it returns false:

    if ( wp_is_secrets_api_available() ) {
        // Move the credential, then use wp_set_secret() and wp_get_secret().
    } else {
        // Keep using the existing storage.
    }

Returns false when the secrets.php drop-in failed to load, when the default
keyring has no usable WP_SECRETS_KEY, or when WP_SECRETS_KEY cannot unwrap the
stored root key. The last happens when a site that already stored secrets adds
or changes the key without re-wrapping the root key.

A provider or keyring from a working drop-in manages its own keys, so it is
reported as available. Its backend can still be unreachable on a given request,
which each call reports as a WP_Error.

Whether the provider accepts writes is a separate question. See
wp_secrets_provider_is_writable().

```php
function wp_is_secrets_api_available(): bool
```

**Returns:** `bool`

**Since:** 7.2.0

**Source:** [`src/wp-includes/secrets.php`](../../src/wp-includes/secrets.php)

## `wp_list_network_secrets()`

Lists network-scope secrets by name and metadata. Never a value.

```php
function wp_list_network_secrets( string $namespace = '' )
```

| Parameter | Type | Description |
|---|---|---|
| `$namespace` | `string` | Only secrets whose name starts with "{$namespace}/" are returned. Default '' returns every network-scope secret. |

**Returns:** `array|WP_Error`

**Since:** 7.2.0

**Source:** [`src/wp-includes/secrets.php`](../../src/wp-includes/secrets.php)

## `wp_list_secrets()`

Lists secrets by name and metadata. Never a value.

```php
function wp_list_secrets( string $namespace = '' )
```

| Parameter | Type | Description |
|---|---|---|
| `$namespace` | `string` | Only secrets whose name starts with "{$namespace}/" are returned. Default '' returns every secret. |

**Returns:** `array|WP_Error` Array of associative arrays, each with keys 'name', 'fingerprint', 'created', 'has_previous', and 'needs_rotation'.

**Since:** 7.2.0

**Source:** [`src/wp-includes/secrets.php`](../../src/wp-includes/secrets.php)

## `wp_retire_network_secret_version()`

Clears a network-scope secret's previous version.

```php
function wp_retire_network_secret_version( string $name )
```

| Parameter | Type | Description |
|---|---|---|
| `$name` | `string` | The secret's namespaced name. |

**Returns:** `true|WP_Error`

**Since:** 7.2.0

**Source:** [`src/wp-includes/secrets.php`](../../src/wp-includes/secrets.php)

## `wp_retire_secret_version()`

Clears a secret's previous version, retiring it for good.

An explicit operator action, with no timers and no cron. Calling this on a secret
that has never been rotated, or that does not exist, is a successful no-op:
the previous slot is already absent either way.

```php
function wp_retire_secret_version( string $name )
```

| Parameter | Type | Description |
|---|---|---|
| `$name` | `string` | The secret's namespaced name. |

**Returns:** `true|WP_Error`

**Since:** 7.2.0

**Source:** [`src/wp-includes/secrets.php`](../../src/wp-includes/secrets.php)

## `wp_secrets_api_activate()`

Grants the site-scope management capability to administrators.

```php
function wp_secrets_api_activate(): void
```

**Returns:** `void`

**Source:** [`secrets-api.php`](../../secrets-api.php)

## `wp_secrets_api_bootstrap()`

Decide whether to load, and load.

The entire no-op decision lives here. Files under src/ carry no function_exists() or
class_exists() guards at all, for two reasons:

1. src/ is destined to be copied verbatim into wordpress-develop, and core's
   wp-includes files do not guard their own function declarations.
2. A per-function guard on a credential retrieval function is an overloading surface.
   An mu-plugin that declared wp_get_secret() first would silently win, and every
   secret read on the site would flow through it. All-or-nothing is the only safe
   granularity here.

```php
function wp_secrets_api_bootstrap(): void
```

**Returns:** `void`

**Source:** [`secrets-api.php`](../../secrets-api.php)

## `wp_secrets_api_grant_network_cap_to_super_admins()`

Grants manage_network_secrets to super admins.

```php
function wp_secrets_api_grant_network_cap_to_super_admins( $allcaps, $caps, $args, $user )
```

| Parameter | Type | Description |
|---|---|---|
| `$allcaps` | `array<string, bool>` | All capabilities of the user. |
| `$caps` | `string[]` | Required primitive capabilities for the requested capability. |
| `$args` | `array<mixed>` | Arguments passed to current_user_can(). |
| `$user` | `WP_User` | The user object. |

**Returns:** `array<string, bool>`

**Source:** [`secrets-api.php`](../../secrets-api.php)

## `wp_secrets_api_load_dropin()`

Loads the secrets.php drop-in, if one exists, and records whether it left the provider, store, and keyring overrides in a usable state.

Idempotent: guarded by a static flag rather than relying on require_once alone,
since _wp_secrets_get_provider(), _wp_secrets_get_store(), and
_wp_secrets_get_key_manager() each cache their result for the request, so this
only ever needs to run once regardless of how many times it is called.

The drop-in sets its overrides in the `$wp_secrets_provider`, `$wp_secrets_store`
and `$wp_secrets_keyring` globals. They are declared global before the require, so
a plain assignment such as `$wp_secrets_keyring = new My_Keyring();` works.

Sets $GLOBALS['wp_secrets_dropin_loaded'] as soon as the file is found, whether
or not it then loads cleanly: wp_using_secrets_dropin() reports presence, not
health.

Sets $GLOBALS['wp_secrets_dropin_broken'] in two cases. First, when the require
throws: a syntax error, a thrown exception, or most runtime errors are caught as
\Throwable. Second, when any of $GLOBALS['wp_secrets_provider'],
$GLOBALS['wp_secrets_store'], or $GLOBALS['wp_secrets_keyring'] is set afterward
to something that is not an instance of its class. A global left unset is
fine; a drop-in overriding only the keyring legitimately leaves the other two
alone. Once the flag is set, the getters install WP_Secrets_Broken_Provider,
WP_Secrets_Broken_Store, and WP_Secrets_Broken_Keyring for the rest of the
request, so every operation returns WP_Error rather than falling back to the
default. A malformed drop-in must not turn into a white screen for the rest of
the site, and must not look like a working site with no secrets in it yet.

This is not airtight: PHP treats some class declaration errors as an uncatchable
fatal even inside a try/catch around the require, confirmed empirically on both
PHP 7.4 and 8.5 before writing this comment. Notably, a class that `extends` one
of the three abstract classes but omits one of its abstract methods, or a class
that still uses `implements` on one, as drop-ins written for interfaces did. That gap is unavoidable from
userland and is recorded in docs/journal/test-coverage-gaps.md rather than
silently assumed away.

```php
function wp_secrets_api_load_dropin(): void
```

**Returns:** `void`

**Source:** [`secrets-api.php`](../../secrets-api.php)

## `wp_secrets_api_notice_conflict()`

Admin notice shown when another plugin has already declared the Secrets API.

```php
function wp_secrets_api_notice_conflict(): void
```

**Returns:** `void`

**Source:** [`secrets-api.php`](../../secrets-api.php)

## `wp_secrets_api_notice_superseded()`

Admin notice shown when core supersedes this plugin.

```php
function wp_secrets_api_notice_superseded(): void
```

**Returns:** `void`

**Source:** [`secrets-api.php`](../../secrets-api.php)

## `wp_secrets_api_register_cli()`

Registers `wp secret` and `wp network-secret`.

Called under WP-CLI from both the branch that loads the plugin's own copy of
the API and the branch where core ships it. cli/ is never copied to core, and
every call it makes is to the public API or to internals core has too, so the
same commands run on either implementation. The one exception is
`migrate-legacy`, whose migrator loads only with the plugin's copy.

```php
function wp_secrets_api_register_cli(): void
```

**Returns:** `void`

**Source:** [`secrets-api.php`](../../secrets-api.php)

## `wp_secrets_api_uninstall()`

Removes the capability this plugin granted, on uninstall – not on deactivation. Deactivating and reactivating the plugin must not silently strip a capability an administrator may have started relying on for something else in the meantime.

```php
function wp_secrets_api_uninstall(): void
```

**Returns:** `void`

**Source:** [`secrets-api.php`](../../secrets-api.php)

## `wp_secrets_memzero()`

Best-effort clearing of a plaintext value from memory.

This is hygiene, not a guarantee. PHP strings are reference-counted and often
shared by copy-on-write; sodium_memzero() can only scrub the bytes of a string it
exclusively owns; if another reference to the same value exists elsewhere,
PHP forces a private copy before zeroing rather than corrupting the shared buffer,
and that other reference is left completely untouched. Call this on every plaintext
local as soon as it is no longer needed, and do not keep incidental copies around.

Under sodium_compat, core's documented fallback when the libsodium extension is
unavailable, this scrubs nothing. A userland polyfill cannot reach a PHP string's
underlying memory at all. Overwriting the local binding is the only thing available
in that case, and it removes the live reference from this scope without touching
the memory the string used to occupy.

```php
function wp_secrets_memzero( &$value ): void
```

| Parameter | Type | Description |
|---|---|---|
| `$value` | `string` | The value to clear, by reference. |

**Since:** 7.2.0

**Source:** [`src/wp-includes/secrets.php`](../../src/wp-includes/secrets.php)

## `wp_secrets_provider_is_writable()`

Whether the active provider accepts writes.

Exists so a settings screen can disable its own save control before an operator
types a credential into a field that will only reject it. A provider whose
credentials are managed by host tooling or a control panel reports false.

```php
function wp_secrets_provider_is_writable(): bool
```

**Returns:** `bool`

**Since:** 7.2.0

**Source:** [`src/wp-includes/secrets.php`](../../src/wp-includes/secrets.php)

## `wp_secrets_provider_label()`

A human-readable description of what is protecting this site's secrets.

For Site Health and a future admin screen. Never key material, never a value.

```php
function wp_secrets_provider_label(): string
```

**Returns:** `string`

**Since:** 7.2.0

**Source:** [`src/wp-includes/secrets.php`](../../src/wp-includes/secrets.php)

## `wp_secrets_validate_name()`

Validates a secret's name.

Names take the form 'plugin-slug/secret-name': lowercase alphanumerics,
hyphens, and underscores in each segment, exactly one '/' separating them,
and no segment starting or ending with a hyphen or underscore.

Namespacing is a matter of organization, not security. It groups secrets by owner
so that listings and admin screens can be sensible. It confers no isolation: any
plugin that can run PHP can read any secret, namespaced or not.

An unnamespaced name ('secret-name', no '/') is accepted, but reports through
_doing_it_wrong(). It exists for one reason: existing code may already use a flat
keyspace, and refusing those names outright would mean every such call site has
to be rewritten before it can be ported at all.
Accepting them keeps that migration incremental. Nothing else should use one.
Two plugins that both choose 'api-key' collide silently, which is a real problem
even though it is not a security one.

```php
function wp_secrets_validate_name( string $name )
```

| Parameter | Type | Description |
|---|---|---|
| `$name` | `string` | Candidate secret name. |

**Returns:** `true|WP_Error` True if $name is usable, including the unnamespaced form. Otherwise WP_Error with code WP_SECRETS_ERROR_INVALID_NAME.

**Since:** 7.2.0

**Source:** [`src/wp-includes/secrets.php`](../../src/wp-includes/secrets.php)

## `wp_set_network_secret()`

Encrypts and stores a network-scope secret.

Site secrets and network secrets are separate functions with separate
capabilities and separate storage prefixes, with no implicit fallback from one
scope to the other.

```php
function wp_set_network_secret( string $name, #[\SensitiveParameter] $value )
```

| Parameter | Type | Description |
|---|---|---|
| `$name` | `string` | The secret's namespaced name. |
| `$value` | `string` | The plaintext value to store. |

**Returns:** `true|WP_Error`

**Since:** 7.2.0

**Source:** [`src/wp-includes/secrets.php`](../../src/wp-includes/secrets.php)

## `wp_set_secret()`

Encrypts and stores a secret.

Encryption is unconditional: there is no plaintext mode and no constant to
disable it. No capability check is applied here, because this must be callable from
cron, REST, and front-end requests where no user is logged in. Enforce
capabilities at the operator boundary (CLI, an admin screen) instead.

```php
function wp_set_secret( string $name, #[\SensitiveParameter] $value )
```

| Parameter | Type | Description |
|---|---|---|
| `$name` | `string` | The secret's namespaced name ('plugin-slug/secret-name'). |
| `$value` | `string` | The plaintext value to store. |

**Returns:** `true|WP_Error`

**Since:** 7.2.0

**Source:** [`src/wp-includes/secrets.php`](../../src/wp-includes/secrets.php)

## `wp_using_secrets_dropin()`

Whether a secrets.php drop-in is present and was loaded.

True whether or not the drop-in successfully provided a store or keyring
override. It reports presence rather than health; Site Health reports separately
on whether a loaded drop-in is actually working.

```php
function wp_using_secrets_dropin(): bool
```

**Returns:** `bool`

**Since:** 7.2.0

**Source:** [`src/wp-includes/secrets.php`](../../src/wp-includes/secrets.php)

## Internal functions

Prefixed with an underscore by WordPress convention: private to the API, not part of the surface plugins should call, and subject to change.

### `_wp_secrets_delete()`

Shared implementation behind wp_delete_secret() and wp_delete_network_secret().

```php
function _wp_secrets_delete( string $name, bool $network )
```

| Parameter | Type | Description |
|---|---|---|
| `$name` | `string` | The secret's name. |
| `$network` | `bool` | Whether this is a network-scope secret. |

**Returns:** `true|WP_Error`

**Since:** 7.2.0

**Source:** [`src/wp-includes/secrets.php`](../../src/wp-includes/secrets.php)

### `_wp_secrets_get()`

Shared implementation behind wp_get_secret() and wp_get_network_secret().

```php
function _wp_secrets_get( string $name, string $version, bool $network )
```

| Parameter | Type | Description |
|---|---|---|
| `$name` | `string` | The secret's name. |
| `$version` | `string` | A WP_Secret_Version constant. |
| `$network` | `bool` | Whether this is a network-scope secret. |

**Returns:** `WP_Secret|null|WP_Error`

**Since:** 7.2.0

**Source:** [`src/wp-includes/secrets.php`](../../src/wp-includes/secrets.php)

### `_wp_secrets_get_key_manager()`

Returns the active key manager.

A secrets.php drop-in overrides the keyring by setting
$GLOBALS['wp_secrets_keyring'] to an instance before this is first called. See
_wp_secrets_get_store() for why an invalid override or a failed drop-in load
fails closed (WP_Secrets_Broken_Keyring) rather than falling back to the default.

```php
function _wp_secrets_get_key_manager(): WP_Secrets_Key_Manager
```

**Returns:** `WP_Secrets_Key_Manager`

**Since:** 7.2.0

**Source:** [`src/wp-includes/secrets.php`](../../src/wp-includes/secrets.php)

### `_wp_secrets_get_provider()`

Returns the active provider.

The provider is whatever is responsible for holding, protecting, and answering for
this site's secrets.

A secrets.php drop-in overrides it by setting $GLOBALS['wp_secrets_provider'] to
an instance before this is first called. Absent that, the provider WordPress
ships is assembled from the active store and keyring, which a drop-in can also
replace individually, so wanting host-managed key custody with default storage needs
no provider at all.

No filter is applied here, or anywhere on the retrieval path. Substitution is by
replacement, never by interception: a filter that can observe which provider
answers is one step from a filter that can answer instead.

Cached for the request. A failed drop-in fails closed, returning WP_Error from
every operation, rather than silently reverting to the default, because a
misconfigured credential backend must never look like a working one that happens
to be empty.

```php
function _wp_secrets_get_provider(): WP_Secrets_Provider
```

**Returns:** `WP_Secrets_Provider`

**Since:** 7.2.0

**Source:** [`src/wp-includes/secrets.php`](../../src/wp-includes/secrets.php)

### `_wp_secrets_get_store()`

Returns the active secret store.

No filter is applied here, or anywhere on the retrieval path. A secrets.php
drop-in overrides the store by setting $GLOBALS['wp_secrets_store'] to an
instance before this is first called, and that global is checked here directly,
not through a hook.

If the global was set but is not a valid WP_Secrets_Store, or if the drop-in
itself failed to load cleanly, this returns WP_Secrets_Broken_Store rather than
silently using the default: the drop-in's presence signals the operator wants
storage other than local options, and falling back to local options anyway would
be the exact silent downgrade this API refuses to make.

```php
function _wp_secrets_get_store(): WP_Secrets_Store
```

**Returns:** `WP_Secrets_Store`

**Since:** 7.2.0

**Source:** [`src/wp-includes/secrets.php`](../../src/wp-includes/secrets.php)

### `_wp_secrets_list()`

Shared implementation behind wp_list_secrets() and wp_list_network_secrets().

```php
function _wp_secrets_list( string $name_prefix, bool $network )
```

| Parameter | Type | Description |
|---|---|---|
| `$name_prefix` | `string` | Restrict to names beginning with this prefix. |
| `$network` | `bool` | Whether to list network-scope secrets. |

**Returns:** `array|WP_Error`

**Since:** 7.2.0

**Source:** [`src/wp-includes/secrets.php`](../../src/wp-includes/secrets.php)

### `_wp_secrets_retire()`

Shared implementation behind wp_retire_secret_version() and its network twin.

```php
function _wp_secrets_retire( string $name, bool $network )
```

| Parameter | Type | Description |
|---|---|---|
| `$name` | `string` | The secret's name. |
| `$network` | `bool` | Whether this is a network-scope secret. |

**Returns:** `true|WP_Error`

**Since:** 7.2.0

**Source:** [`src/wp-includes/secrets.php`](../../src/wp-includes/secrets.php)

### `_wp_secrets_set()`

Shared implementation behind wp_set_secret() and wp_set_network_secret().

```php
function _wp_secrets_set( string $name, #[\SensitiveParameter] $value, bool $network, bool $needs_rotation = false, ?string $action_override = null )
```

| Parameter | Type | Description |
|---|---|---|
| `$name` | `string` | The secret's name. |
| `$value` | `string` | The plaintext value. |
| `$network` | `bool` | Whether this is a network-scope secret. |
| `$needs_rotation` | `bool` | Flag the stored secret as needing rotation. |
| `$action_override` | `string\|null` | Overrides the $action reported to wp_secret_changed. |

**Returns:** `true|WP_Error`

**Since:** 7.2.0

**Source:** [`src/wp-includes/secrets.php`](../../src/wp-includes/secrets.php)
