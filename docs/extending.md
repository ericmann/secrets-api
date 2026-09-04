# Extending: providers, stores, and keyrings

**Start here: `WP_Secrets_Provider` is the outermost seam.** It answers for a secret — holding it,
protecting it, and producing it. WordPress ships `WP_Secrets_Libsodium_Provider`, which encrypts
with libsodium and keeps ciphertext in the options tables, and that is the *default*, not the
privileged case. A platform that protects credentials in a KMS, an HSM, or its own control panel
implements the same interface and is a peer.

The rule a provider must satisfy is **stronger than the default, never weaker**. Storing a
plaintext where the default would have stored ciphertext stays impossible. Receiving a value over
an authenticated channel and protecting it in an HSM is not that.

```php
// wp-content/secrets.php
$GLOBALS['wp_secrets_provider'] = new My_Platform_Provider();
```

A provider declares three things, so Site Health and a future settings screen can be honest about
what is protecting a site's credentials: `get_label()`, `get_protection_boundary()` (
`BOUNDARY_WORDPRESS` or `BOUNDARY_PROVIDER`), and `is_writable()`. **These are declarations, not
enforcement** — a drop-in is fully trusted code that could already read every secret. Their value
is visibility, and the interface says so rather than implying a boundary that is not there.

### Prove it conforms before you ship it

`implements WP_Secrets_Provider` is a claim PHP can check about method names and
nothing else. It cannot tell you that absence is reported as `null` rather than an error, or that
an unreachable backend fails closed instead of looking empty — and those are the properties that
actually matter to a caller holding a credential.

So extend the conformance suite and point it at your provider:

```php
class Tests_My_Platform_Provider extends WP_Secrets_Provider_Conformance {
    protected function provider() {
        return new My_Platform_Provider( /* ... */ );
    }
}
```

It checks what every provider owes a caller: a name that was never set reads as `null`; `PREVIOUS`
with no previous value is absence rather than an error; deleting something absent succeeds;
fingerprints are stable for the same value; listings never contain a plaintext; and a provider
that declares itself read-only actually refuses writes with `secret_provider_read_only`. Where the
contract legitimately varies it adapts — a read-only provider is not asked to round-trip a value —
and it says in the report what it skipped rather than passing silently.

The suite lives in `tests/includes/class-wp-secrets-provider-conformance.php` and runs against the
shipped provider, so there is a known-good subject to compare failures against.

---

The two interfaces below are the *internals of the shipped provider*, and remain replaceable
independently. A host who wants their own key custody but is happy with WordPress's storage swaps
only the keyring and writes no provider at all. Both live in `src/wp-includes/` and both are part
of the API surface intended for core.

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

Smaller surface, higher stakes: this is the one thing every other key in the system derives
from. `wrap()`/`unwrap()` protect exactly one thing — 32 bytes of root key material — never a
secret value. A KMS or HSM lives behind this interface in a real deployment; the shipped default
(`WP_Secrets_Config_Key_Provider`) wraps it with a key derived from `wp-config.php`, because that
is the only thing guaranteed to exist on every WordPress install.

`get_key_source()` is a one-line, human-readable string surfaced in Site Health so an operator
can see at a glance whether they're running the config-derived default or something they wired
up themselves — not sensitive, never the key material itself.

### Registering one

```php
// wp-content/secrets.php
$GLOBALS['wp_secrets_keyring'] = new My_KMS_Keyring();
```

A drop-in can set any of the three globals, or none. Setting only the keyring and leaving storage
on the default is a normal, supported combination — most hosts want their own key management long
before they want their own row storage. Setting `$GLOBALS['wp_secrets_provider']` replaces both at
once, and is what a platform that is itself the encryption boundary should do.

## What happens if you get it wrong

`wp_secrets_api_load_dropin()` requires `secrets.php`, if one exists, and checks the type of
whatever ends up in `$GLOBALS['wp_secrets_provider']` / `$GLOBALS['wp_secrets_store']` /
`$GLOBALS['wp_secrets_keyring']` afterward.
A missing global is fine — a drop-in that sets only one of them is a legitimate and common case.
A global set to something that isn't an instance of the matching interface is not fine, and
neither is a drop-in that throws or has a syntax error. Both fail the whole drop-in closed, via
`WP_Secrets_Broken_Provider` / `WP_Secrets_Broken_Store` / `WP_Secrets_Broken_Keyring`, which turn
every operation into a `WP_Error` rather than silently falling back to the default. A broken
credential backend must never look like a working one that happens to have no secrets in it yet.

One gap, load-bearing enough to call out here rather than leave buried: PHP treats certain class
declaration errors in the drop-in — most notably a class that `implements` an interface but
omits a required method — as an uncatchable fatal, even inside the `try`/`catch` around the
`require`. There is no userland way to close this; a fatal there is a fatal for the whole
request, not a scoped, contained failure. Test your drop-in with `-l` and a real request before
trusting it in production. See [`open-questions.md`](open-questions.md), "Drop-in file loading".

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
