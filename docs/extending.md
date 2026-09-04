# Extending: providers, stores, and keyrings

**Start here: `WP_Secrets_Provider` is the outermost extension point.** A provider is responsible
for a secret: storing it, protecting it, and handing it back. WordPress ships
`WP_Secrets_Libsodium_Provider`, which encrypts with libsodium and keeps ciphertext in the options
tables. That's the default and nothing more. A platform that protects credentials in a KMS, an
HSM, or its own control panel implements the same interface and stands on equal footing.

Every provider has to be **stronger than the default, never weaker**. You still can't store a
plaintext where the default would have stored ciphertext. Taking a value over an authenticated
channel and keeping it in an HSM clears that bar comfortably.

```php
// wp-content/secrets.php
$GLOBALS['wp_secrets_provider'] = new My_Platform_Provider();
```

A provider declares three things so Site Health and a future settings screen can tell an operator
what's protecting their credentials: `get_label()`, `get_protection_boundary()`
(`BOUNDARY_WORDPRESS` or `BOUNDARY_PROVIDER`), and `is_writable()`. **Nothing enforces these
declarations.** A drop-in is fully trusted code that could already read every secret. They exist
so a human reviewing a drop-in, or an operator reading Site Health, can see what it claims.

### Check it against the conformance suite

PHP can verify your class has the right method names. It can't tell you whether absence comes back
as `null` instead of an error, or whether an unreachable backend fails closed rather than looking
empty. Those are the parts a caller holding a credential depends on.

So extend the conformance suite and point it at your provider:

```php
class Tests_My_Platform_Provider extends WP_Secrets_Provider_Conformance {
    protected function provider() {
        return new My_Platform_Provider( /* ... */ );
    }
}
```

It checks that a name you never set reads as `null`; that `PREVIOUS` with no previous value is an
absence rather than an error; that deleting something absent succeeds; that fingerprints stay
stable for the same value; that listings never contain a plaintext; and that a provider declaring
itself read-only really does refuse writes with `secret_provider_read_only`. Where the contract
allows variation it adapts, so a read-only provider is never asked to round-trip a value, and it
reports what it skipped instead of passing quietly.

The suite lives in `tests/includes/class-wp-secrets-provider-conformance.php` and runs against the
shipped provider, so you have a known-good subject to compare failures against.

---

The two interfaces below are the internals of the shipped provider, and you can still replace
either one on its own. A host who wants their own key custody but is happy with WordPress's
storage swaps the keyring and writes no provider at all. Both live in `src/wp-includes/`, and both
are part of the API surface intended for core.

## `WP_Secrets_Store`: where records live

```php
interface WP_Secrets_Store {
    public function get( $name, $network = false );
    public function set( $name, $record, $network = false );
    public function delete( $name, $network = false );
    public function list_names( $network = false );
}
```

An implementation is never handed a plaintext secret. `$record` is the array `WP_Secrets_Cipher`
produces — nonce, ciphertext, tag, metadata — and nothing here accepts or returns anything else.
There is no method that could turn encryption off, because there is no plaintext for a store to
see in the first place.

`get()` returns three things, not two: the record array, `null` if the name has never been set,
or `WP_Error` if the store cannot currently tell you which. Collapsing the last two — treating
"unreachable" as "absent" — is exactly the bug this interface exists to make impossible. A
network outage must never look like a deleted credential.

There is no capability flag. A store that cannot perform an operation says so from that
operation, by returning `WP_Error` — one source of truth rather than a parallel oracle that can
disagree with the method it describes. "Read-only" as a deployment shape belongs a layer up: it is
a property of a *provider*, declared by `is_writable()`, because a platform whose credentials are
managed in a control panel is not really describing its record storage at all.

### Registering one

```php
// wp-content/secrets.php
$GLOBALS['wp_secrets_store'] = new My_Platform_Store();
```

See [`drop-in-example.php`](drop-in-example.php) for a complete, runnable skeleton.

## `WP_Secrets_Keyring`: where the root key lives

```php
interface WP_Secrets_Keyring {
    public function wrap( $key_material );
    public function unwrap( $wrapped );
    public function get_key_source();
}
```

A smaller interface, but everything else depends on it: every other key in the system derives
from what this one protects. `wrap()` and `unwrap()` only ever handle 32 bytes of root key
material, never a secret value. In a real deployment a KMS or HSM sits behind this interface. The
shipped default (`WP_Secrets_Config_Key_Provider`) wraps the root key with a key derived from
`wp-config.php`, since that's the only thing guaranteed to exist on every WordPress install.

`get_key_source()` returns a short human-readable string for Site Health, so an operator can see
whether they're on the config-derived default or something they wired up themselves. It describes
the key; it never contains the key material.

### Registering one

```php
// wp-content/secrets.php
$GLOBALS['wp_secrets_keyring'] = new My_KMS_Keyring();
```

A drop-in can set any of the three globals, or none of them. Setting only the keyring and leaving
storage on the default is a normal thing to do; most hosts want their own key management long
before they want their own row storage. Setting `$GLOBALS['wp_secrets_provider']` replaces both at
once, which is what a platform doing its own encryption should do.

## What happens if you get it wrong

`wp_secrets_api_load_dropin()` requires `secrets.php`, if one exists, and checks the type of
whatever ends up in `$GLOBALS['wp_secrets_provider']` / `$GLOBALS['wp_secrets_store']` /
`$GLOBALS['wp_secrets_keyring']` afterward.
Leaving a global unset is fine; plenty of drop-ins set only one. Setting one to something that
isn't an instance of the matching interface is not, and neither is a drop-in that throws or has a
syntax error. Either way the whole drop-in fails closed, through
`WP_Secrets_Broken_Provider` / `WP_Secrets_Broken_Store` / `WP_Secrets_Broken_Keyring`, which turn
every operation into a `WP_Error` instead of quietly falling back to the default. A broken
credential backend must never look like a working one that happens to have no secrets in it yet.

There's one gap worth knowing about. PHP treats some class declaration errors in the drop-in as an
uncatchable fatal, even inside the `try`/`catch` around the `require`. The usual culprit is a class
that `implements` an interface but omits one of its methods. Userland can't intercept that, and it
takes down the whole request rather than failing in a contained way. Run `php -l` over your drop-in
and load a real request before you trust it in production. See
[`open-questions.md`](open-questions.md), "Drop-in file loading".

## Error codes

Every `WP_Error` this API returns uses one of a fixed set of codes, defined in `secrets.php`:

| Constant | Code | Meaning |
|---|---|---|
| `WP_SECRETS_ERROR_INVALID_NAME` | `secret_invalid_name` | Name failed validation (see below) |
| `WP_SECRETS_ERROR_INVALID_VALUE` | `secret_invalid_value` | Value is not a string |
| `WP_SECRETS_ERROR_KEY_UNAVAILABLE` | `secret_key_unavailable` | Keyring could not produce a usable key |
| `WP_SECRETS_ERROR_CRYPTO_UNAVAILABLE` | `secret_crypto_unavailable` | No libsodium implementation present |
| `WP_SECRETS_ERROR_STORE_UNAVAILABLE` | `secret_store_unavailable` | Store could not determine an answer |
| `WP_SECRETS_ERROR_PROVIDER_READ_ONLY` | `secret_provider_read_only` | Write attempted against a provider whose credentials are managed elsewhere |
| `WP_SECRETS_ERROR_INVALID_ARGUMENT` | `secret_invalid_argument` | A caller passed an unusable argument; always accompanied by `_doing_it_wrong()` |
| `WP_SECRETS_ERROR_DECRYPTION_FAILED` | `secret_decryption_failed` | Record present but would not decrypt (wrong key, corruption, tampering) |
| `WP_SECRETS_ERROR_RECORD_MALFORMED` | `secret_record_malformed` | Record does not have the expected shape |
| `WP_SECRETS_ERROR_RECORD_UNSUPPORTED_VERSION` | `secret_record_unsupported_version` | Record's `v` field is not one this code understands |

A custom store should let these bubble up rather than inventing new codes for the same
conditions — code calling `wp_get_secret()` and branching on `is_wp_error()` should not need to
know which store is active to interpret the failure.

## Naming

Names are `'namespace/key'` — lowercase alphanumerics, hyphens, and underscores per segment,
exactly one `/`, no segment starting or ending with a hyphen or underscore, capped at
`WP_SECRETS_MAX_NAME_LENGTH` (172) characters total. `wp_secrets_validate_name()` is the single
source of truth; a store implementation does not need to re-validate a name it receives from this
API's own functions, since every public entry point validates before calling into the store.
