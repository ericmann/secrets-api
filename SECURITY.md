# Security policy

This project stores credentials. A flaw here is a flaw in the thing protecting everything else,
so reports are welcome and taken seriously — including ones that turn out to be wrong.

## Reporting a vulnerability

**Do not open a public issue.**

Use GitHub's private vulnerability reporting:
[**Report a vulnerability**](https://github.com/ericmann/secrets-api/security/advisories/new).
That opens a private advisory visible only to the maintainers, and it lets us work the fix and
the disclosure in the same place.

Useful things to include, in rough order of value:

- the property you believe is violated, stated as a property (for example "a record sealed for
  one secret name can be decrypted under another")
- a proof of concept, or the shortest sequence of `wp secret` commands that shows it
- the PHP and WordPress versions, and whether the site is multisite
- whether a `wp-content/secrets.php` drop-in was installed, and which provider it registered

You will get an acknowledgement. If a report is not a vulnerability, you will get an explanation
of why rather than silence.

## Scope

This is a pre-release feature plugin (`0.1.0`) tracking a proposal, not yet shipped in WordPress
core. There is no supported-version matrix yet: `main` is the only supported branch, and fixes
land there.

In scope, and interesting:

- the envelope construction and key hierarchy in `src/wp-includes/` — key derivation, AAD
  binding, nonce handling, the two-slot rotation model
- anything that lets a stored record be decrypted outside the context it was sealed for: a
  different secret name, a different slot, a different site in a network, a different install
- anything that causes a secret value to be written anywhere in plaintext, including logs,
  the persistent object cache, `wp_options`, or CLI output that was not explicitly asked to
  reveal
- anything that makes an unreachable or broken backend report a credential as **absent**.
  Absent and broken are deliberately distinct states, and collapsing them is how someone gets
  talked into regenerating a credential they still had
- privilege or capability checks on the WP-CLI commands and Site Health surfaces

## Known and documented non-goals

These are design decisions, documented in the README and `docs/`, not oversights. Reporting them
is not useful — though arguing that a decision is *wrong* absolutely is, in a normal issue:

- **There is no per-plugin isolation.** Namespacing (`plugin-slug/secret-name`) is organisational.
  Any plugin that can run PHP can read any secret. Masking is hygiene against shoulder-surfing
  and accidental logging, not a privilege boundary.
- **A `wp-content/secrets.php` drop-in is fully trusted code.** It runs before plugins and can
  do anything PHP can. `get_protection_boundary()` and friends are declarations for humans and
  interfaces, never enforcement, and say so in their own docblocks.
- **`var_export()` of a `WP_Secret` cannot be masked from userland.** It ignores `__debugInfo()`
  and emits private properties directly. Mitigated by never storing the plaintext as an object
  property; documented as a known limitation.
- **The `options.php` all-settings screen reads the options table directly**, with no filter a
  plugin can use to exclude rows. Surfaced as a Site Health warning; a real fix is a core patch.
- **`sodium_memzero()` is a no-op under core's bundled `sodium_compat`.** A userland polyfill
  cannot reach a PHP string's memory. The docblock says so rather than overclaiming.

## Cryptography

The construction is deliberately boring: libsodium primitives, composed in the documented way,
with no invented cipher and no hand-rolled mode.

If you find that this project is *using* a primitive incorrectly — a reused nonce, an
unauthenticated path, a KDF misuse, a context string that does not bind what it claims to bind —
that is exactly the report worth making, and the one hardest to catch from the inside.
