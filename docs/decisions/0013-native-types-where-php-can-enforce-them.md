---
title: "ADR 0013: Native types where PHP can enforce them"
description: "Parameters and return values carry PHP 7.4 type declarations. A plaintext value stays untyped, so a wrong type is refused instead of coerced, and a secret's name is not narrowed beyond string."
---

# ADR 0013: Native types where PHP can enforce them

| | |
|---|---|
| **Number** | 0013 |
| **Date** | 2026-10-07 |
| **Status** | Accepted |

## Context

Until now nothing under `src/` declared a parameter type. Types lived in docblocks, and every
function checked its own arguments at runtime: `is_string( $name )`, `is_int( $site_id )`,
`is_array( $record )`. A wrong type came back as a `WP_Error`, which fitted the rule that this API
reports a caller's mistake instead of throwing.

Review on the core patch, [#66187](https://core.trac.wordpress.org/ticket/66187), asked for three
things: native type declarations on all new code where PHP 7.4 allows them, narrower static types
such as `int<0, max>` where a value is known to be narrower than its PHP type, and a narrowed type
for a secret's name. New core code, the Abilities API and the Connectors registry among it, already
declares native types.

Native types are not free here. With coercive typing, which is what a caller gets unless its own
file declares `strict_types`, PHP converts a scalar to fit the declaration. `false` becomes `''`
and `12345` becomes `'12345'`, silently. Only `null`, arrays, and objects are refused, and they
are refused with a `TypeError`.

## Decision

**Parameters and return values carry native types wherever PHP 7.4 can express them.** That covers
the public functions, the three interfaces and everything that implements them, the internal
classes, the plugin-only code, and the WP-CLI command. A return type is declared when it is a
single type or a nullable one. A return such as `WP_Secret|null|WP_Error` needs a union type, which
PHP 7.4 does not have, so it stays in the docblock.

The runtime checks those declarations replace are removed, along with their tests. A check that
PHP cannot make stays: a name must not be empty, a site id must not be negative, a version must be
one of the two constants.

**A plaintext value stays untyped.** This is `$value` in `wp_set_secret()`,
`wp_set_network_secret()`, and the provider's `set()`, the plaintext given to the cipher and to
`WP_Secret`'s constructor, and the argument to `wp_secrets_memzero()`. Declared as `string`,
`wp_set_secret( 'plugin/key', get_option( 'missing' ) )` would store an empty secret and report
success. Untyped, with the check kept, it returns `WP_SECRETS_ERROR_INVALID_VALUE` as before.

**The two Site Health filter callbacks stay untyped.** They receive whatever an earlier callback
on the same filter returned. A type there would turn another plugin's mistake into a fatal error
on the Site Health screen.

**Static types are narrowed where that is true under both analyses.** `src/` is analyzed here and
again inside wordpress-develop, and a narrowing has to hold in both. The ones that do: a version or
slot is `WP_Secret_Version::CURRENT|WP_Secret_Version::PREVIOUS`, a scope is `'site'|'network'`, a
protection boundary is one of the `BOUNDARY_*` constants, a Site Health status is one of its three
values, and a count is `int<0, max>`.

Three were tried and taken back out:

- `int<0, max>` for a site id. `get_current_blog_id()` is declared as returning `int`, so every
  internal caller failed.
- `non-empty-string` for a provider's label and a keyring's key source. Both are translated, and a
  translation can be empty as far as the type system knows.
- An out-type of `''` on `wp_secrets_memzero()`. It made the tests of that function statically
  true, which is the opposite of what a test is for.

**A secret's name is not narrowed beyond `string`.** The suggestion was
`lowercase-string&non-empty-string`. These functions exist to take a string nobody has checked and
validate it, and they return a `WP_Error` when it fails. Narrowing the parameter says the caller
must have validated it already. WP-CLI passes what the operator typed, and Site Health passes
names read back from a listing, so both would be wrong by type while being correct in behavior.
Core's own narrowing work backed out of `register_rest_route()` for the same reason.

## Consequences

- **A provider, store, or keyring written against 0.2.x stops loading.** The interfaces now
  declare return types on `get_label()`, `get_protection_boundary()`, `is_writable()`, and
  `get_key_source()`, and PHP refuses a class that implements an interface without a compatible
  return type. It is a fatal error at class load, and it cannot be caught. Parameter types do not
  have this effect, since an implementation may leave its own parameters untyped. The three
  examples and the reference drop-in are updated.
- **A wrong type for anything but a value is now PHP's business.** `null`, an array, or an object
  throws a `TypeError`. A scalar is converted: `wp_get_secret( 123 )` looks up the name `'123'`.
  Neither returned a `WP_Error` in a way a caller could rely on, since the caller had already
  broken the documented type.
- Ten tests that existed to exercise the removed checks are gone. Eight of the inline
  `@phpstan-ignore` comments went with them, and five were added for tests that pass an invalid
  scope or version on purpose.
- If core's minimum PHP version reaches 8.0, the union returns can be declared too.
