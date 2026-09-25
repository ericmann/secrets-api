---
title: "Extending: providers, stores, and keyrings"
description: "The secrets.php drop-in as proposed, the WP_Secrets_Provider, WP_Secrets_Store, and WP_Secrets_Keyring contracts as built, fail-closed behaviour, error codes, and naming rules."
---

# Extending: providers, stores, and keyrings

## As proposed

A `wp-content/secrets.php` drop-in exposes two independently replaceable extension points: where
ciphertext is stored (a vault, a parameter store, a host API) and what wraps the master key (a
KMS, an HSM). Neither is ever handed a plaintext secret, and neither can turn encryption off. The
proposal asks hosts running a real secret store or key backend to say what the drop-in surface is
missing. See "Two extension points, independently replaceable" and "Feedback wanted" in the
[proposal][proposal].

## As built

**Start here: `WP_Secrets_Provider` is the outermost extension point.** A provider is responsible
for a secret: storing it, protecting it, and handing it back. WordPress ships
`WP_Secrets_Libsodium_Provider`, which encrypts with libsodium and keeps ciphertext in the options
tables. That is the default and nothing more. A platform that protects credentials in a KMS, an
HSM, or its own control panel implements the same interface and stands on equal footing. The
interface has eight methods: `get()`, `set()`, `delete()`, `retire_previous()`, `list_secrets()`,
`get_label()`, `get_protection_boundary()`, and `is_writable()`, in
`src/wp-includes/interface-wp-secrets-provider.php`.

Every provider has to be **stronger than the default, never weaker**. A provider still cannot
store a plaintext where the default would have stored ciphertext. Taking a value over an
authenticated channel and keeping it in an HSM clears that bar comfortably.

```php
// wp-content/secrets.php
$GLOBALS['wp_secrets_provider'] = new My_Platform_Provider();
```

A provider declares three things so Site Health and a future settings screen can tell an operator
what is protecting their credentials: `get_label()`, `get_protection_boundary()`
(`BOUNDARY_WORDPRESS` or `BOUNDARY_PROVIDER`), and `is_writable()`. **Nothing enforces these
declarations.** A drop-in is fully trusted code that could already read every secret. They exist
so a human reviewing a drop-in, or an operator reading Site Health, can see what it claims.

**The conformance suite.** PHP can verify a class has the right method names. It cannot tell
whether absence comes back as `null` instead of an error, or whether an unreachable backend fails
closed rather than looking empty. Those are the parts a caller holding a credential depends on, so
extend the suite and point it at the provider:

```php
class Tests_My_Platform_Provider extends WP_Secrets_Provider_Conformance {
    protected function provider() {
        return new My_Platform_Provider( /* ... */ );
    }
}
```

It checks that a name never set reads as `null`; that `PREVIOUS` with no previous value is an
absence rather than an error; that deleting or retiring something absent succeeds; that a stored
value round-trips and a deleted one reads as absent; that fingerprints stay stable for the same
value; that listings never contain a plaintext and respect a name prefix; that the label,
boundary, and writability declarations are well-formed; and that a provider declaring itself
read-only really does refuse writes with `secret_provider_read_only`. Where the contract allows
variation it adapts: a read-only provider is never asked to round-trip a value, and the skipped
checks are reported as skipped rather than passing quietly. The suite lives in
`tests/includes/class-wp-secrets-provider-conformance.php` and runs against the shipped provider,
so there is a known-good subject to compare failures against. It also runs against the Vault
provider example on a real dev server in `make test-examples`, so there is a second known-good
subject whose backend does not share the two-slot shape.

**The two inner interfaces.** The store and keyring are the internals of the shipped provider,
and either can still be replaced on its own. A host who wants their own key custody but is happy
with WordPress's storage swaps the keyring and writes no provider at all. Both live in
`src/wp-includes/`, and both are part of the API surface intended for core.

**`WP_Secrets_Store`: where records live.**

```php
interface WP_Secrets_Store {
    public function get( $name, $network = false );
    public function set( $name, $record, $network = false );
    public function delete( $name, $network = false );
    public function list_names( $network = false );
}
```

An implementation is never handed a plaintext secret. `$record` is the array the shipped
provider assembles: `v` (the record format version), `current`, and, once a secret has been
rotated, `previous`. Each slot carries the base64 fields `dk`, `dk_nonce`, `ct`, and `nonce`
produced by `WP_Secrets_Cipher`, plus `fingerprint`, `created`, and `needs_rotation`. Nothing
here accepts or returns anything else. There is no method that could turn encryption off, because
there is no plaintext for a store to see in the first place. See
[envelope-encryption.md](envelope-encryption.md) for what each field holds.

`get()` returns three things, not two: the record array, `null` if the name has never been set,
or `WP_Error` if the store cannot currently tell which. Collapsing the last two, treating
"unreachable" as "absent", is exactly the bug this interface exists to make impossible. A network
outage must never look like a deleted credential.

There is no capability flag. A store that cannot perform an operation says so from that
operation, by returning `WP_Error`: one source of truth rather than a parallel oracle that can
disagree with the method it describes. "Read-only" as a deployment shape belongs a layer up. It
is a property of a *provider*, declared by `is_writable()`, because a platform whose credentials
are managed in a control panel is not really describing its record storage at all.

```php
// wp-content/secrets.php
$GLOBALS['wp_secrets_store'] = new My_Platform_Store();
```

**`WP_Secrets_Keyring`: where the root key lives.**

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
shipped default, `WP_Secrets_Config_Key_Provider`, wraps the root key with a key derived from
`wp-config.php`, since that is the only thing guaranteed to exist on every WordPress install.

`wrap()` must be non-deterministic: two calls on the same 32 bytes must return two different
wrapped values. `WP_Secrets_Key_Manager::rotate_site_key()` stores the re-wrapped root key with
`update_site_option()`, which reports an unchanged value as a failure the same way `update_option()`
does, so a keyring that ever produced the same wrapped output twice would make rotation
indistinguishable from a storage error. `unwrap()` returns `WP_Error` for anything it did not
produce, garbage, a truncated value, or a single tampered byte, and never throws: a caller holding
`is_wp_error()` as its only failure signal must never receive a plausible-looking wrong key instead
of a clear failure.

`get_key_source()` returns a short human-readable string for Site Health, so an operator can see
whether they are on the config-derived default or something they wired up themselves. It
describes the key; it never contains the key material.

**The keyring conformance suite** mirrors the provider one. `WP_Secrets_Keyring_Conformance` in
`tests/includes/class-wp-secrets-keyring-conformance.php` is an abstract test case with a
`keyring()` method to implement. It checks that `wrap()` of 32 random bytes returns a non-empty
string that `unwrap()` returns to the same bytes; that two `wrap()` calls on the same bytes never
match; that `unwrap()` of garbage, of a truncated value, and of a value with one flipped byte each
returns `WP_Error`; and that `get_key_source()` is a non-empty string. It runs against the shipped
`WP_Secrets_Config_Key_Provider` and against `Mock_Keyring`, the same way the provider suite runs
against the shipped provider. `examples/aws-kms-keyring/` runs it against a real
`WP_Secrets_Keyring` implementation, AWS KMS, reached through Moto in `make test-examples`.

```php
// wp-content/secrets.php
$GLOBALS['wp_secrets_keyring'] = new My_KMS_Keyring();
```

A drop-in can set any of the three globals, or none of them. Setting only the keyring and leaving
storage on the default is a normal thing to do; most hosts want their own key management long
before they want their own row storage. Setting `$GLOBALS['wp_secrets_provider']` replaces both at
once, which is what a platform doing its own encryption should do. See
[`drop-in-example.php`](../reference/drop-in-example.php) for a complete skeleton of all three.

**What happens if you get it wrong.** `wp_secrets_api_load_dropin()` requires `secrets.php`, if
one exists, and checks the type of whatever ends up in `$GLOBALS['wp_secrets_provider']`,
`$GLOBALS['wp_secrets_store']`, and `$GLOBALS['wp_secrets_keyring']` afterward. Leaving a global
unset is fine; plenty of drop-ins set only one. Setting one to something that is not an instance
of the matching interface is not, and neither is a drop-in that throws or has a syntax error.
Either way the whole drop-in fails closed, through `WP_Secrets_Broken_Provider`,
`WP_Secrets_Broken_Store`, and `WP_Secrets_Broken_Keyring`, which turn every operation into a
`WP_Error` instead of quietly falling back to the default. A broken credential backend must never
look like a working one that happens to have no secrets in it yet.
[ADR 0007](../decisions/0007-fail-closed-on-a-broken-drop-in.md) records the decision.

There is one gap worth knowing about. PHP treats some class declaration errors in the drop-in as
an uncatchable fatal, even inside the `try`/`catch` around the `require`. The usual culprit is a
class that `implements` an interface but omits one of its methods. Userland cannot intercept that,
and it takes down the whole request rather than failing in a contained way. Run `php -l` over a
drop-in and load a real request before trusting it in production. `tests/smoke/smoke.sh` case D
checks the syntax-error, throw-on-load, and wrong-type cases through the real loader; only the
fatal remains a manual check. See [`test-coverage-gaps.md`](../journal/test-coverage-gaps.md),
"Drop-in file loading".

**Error codes.** Every `WP_Error` this API returns uses one of a fixed set of codes, defined in
`src/wp-includes/secrets.php`:

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

A custom store or provider should let these bubble up rather than inventing new codes for the
same conditions. Code calling `wp_get_secret()` and branching on `is_wp_error()` should not need
to know which backend is active to interpret the failure.

**Naming.** Names take the form `namespace/key`: lowercase alphanumerics, hyphens, and
underscores in each segment; at most one `/`; no segment starting or ending with a hyphen or
underscore; at most `WP_SECRETS_MAX_NAME_LENGTH` (172) characters in total. An unnamespaced name
(`api_key`, no `/`) is accepted and reports through `_doing_it_wrong()`. It exists so code written
against the earlier prototype can be ported one call site at a time; see
[ADR 0004](../decisions/0004-no-compat-shim-for-the-prototype.md) and
[migrating-from-displace.md](../reference/migrating-from-displace.md). Two plugins that both
choose `api-key` collide silently, which
[ADR 0005](../decisions/0005-namespaces-are-not-access-control.md) calls a real problem but not a
security one. `wp_secrets_validate_name()` in `src/wp-includes/secrets.php` is the single source
of truth. Every public entry point validates before calling into a provider, store, or cipher, so
an implementation does not need to re-validate a name it receives from this API's own functions.

## Why

**A third, outer extension point.** The proposal names two. Hosts on the thread described
deployments the two-axis contract could not express: a platform that holds the credential itself,
protects it with its own KMS or HSM, and serves it to WordPress over an authenticated channel. The
rule was restated as "stronger than the default, never weaker" and the provider was placed one
level outside the store and keyring rather than carving exceptions into the store contract.
[ADR 0001](../decisions/0001-provider-as-outermost-extension-point.md) records the decision and
[providers-and-keyrings.md](providers-and-keyrings.md) describes how the three relate.

**Declarations, not a capability flag.** An earlier revision put a capability flag on the store.
It was replaced by `is_writable()` and `get_protection_boundary()` on the provider because
read-only is a property of who manages the credential, not of where ciphertext rows live, and a
flag that can disagree with the method it describes is worse than no flag.

**Fail closed, no fallback.** The proposal does not say what happens when the drop-in is broken.
Falling back to the shipped provider would report every host-held credential as absent rather
than unreachable, which is the collapse the three-state return exists to prevent.

**Error codes and naming rules are this implementation's.** The proposal names neither. A fixed
code set lets callers branch without knowing which backend is active. The unnamespaced name is
accepted only because refusing it would force every prototype-era call site to be rewritten before
any of them could be ported.

[proposal]: https://make.wordpress.org/core/2026/08/25/proposal-a-secrets-api-for-wordpress-7-2/
