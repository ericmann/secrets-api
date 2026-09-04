# Security policy

This project stores credentials, so a bug here can undermine everything it's meant to protect.
Reports are welcome and taken seriously, including the ones that turn out to be wrong.

## Reporting a vulnerability

**Do not open a public issue.**

Use GitHub's private vulnerability reporting:
[**Report a vulnerability**](https://github.com/ericmann/secrets-api/security/advisories/new).
That opens an advisory only the maintainers can see, and keeps the fix and the disclosure in one
place.

Helpful things to include, roughly in order of usefulness:

- the property you believe is violated, stated as a property (for example "a record sealed for
  one secret name can be decrypted under another")
- a proof of concept, or the shortest sequence of `wp secret` commands that shows it
- the PHP and WordPress versions, and whether the site is multisite
- whether a `wp-content/secrets.php` drop-in was installed, and which provider it registered

You'll get an acknowledgement either way. If it turns out not to be a vulnerability, you'll get an
explanation rather than silence.

## Scope

This is a pre-release feature plugin (`0.1.0`) tracking a proposal, not yet shipped in WordPress
core. There is no supported-version matrix yet: `main` is the only supported branch, and fixes
land there.

Things worth reporting:

- the envelope construction and key hierarchy in `src/wp-includes/` — key derivation, AAD
  binding, nonce handling, the two-slot rotation model
- anything that lets a stored record be decrypted outside the context it was sealed for: a
  different secret name, a different slot, a different site in a network, a different install
- anything that causes a secret value to be written anywhere in plaintext, including logs,
  the persistent object cache, `wp_options`, or CLI output that was not explicitly asked to
  reveal
- anything that makes an unreachable or broken backend report a credential as **absent**. Absent
  and broken are kept separate on purpose, since confusing the two is how someone ends up
  regenerating a credential they still had
- privilege or capability checks on the WP-CLI commands and Site Health surfaces

## Known and documented non-goals

These are documented decisions in the README and `docs/`, not things that were missed. Reporting
them won't get you far, though arguing that one of them is the wrong decision is a fair issue to
open:

- **There is no per-plugin isolation.** Namespacing (`plugin-slug/secret-name`) is organisational.
  Any plugin that can run PHP can read any secret. Masking is hygiene against shoulder-surfing
  and accidental logging, not a privilege boundary.
- **A `wp-content/secrets.php` drop-in is fully trusted code.** It runs before plugins and can do
  anything PHP can. `get_protection_boundary()` and its neighbours are declarations for humans to
  read; nothing enforces them, and their docblocks say as much.
- **`var_export()` of a `WP_Secret` cannot be masked from userland.** It ignores `__debugInfo()`
  and emits private properties directly. Mitigated by never storing the plaintext as an object
  property; documented as a known limitation.
- **The `options.php` all-settings screen reads the options table directly**, with no filter a
  plugin can use to exclude rows. Surfaced as a Site Health warning; a real fix is a core patch.
- **`sodium_memzero()` is a no-op under core's bundled `sodium_compat`.** A userland polyfill
  cannot reach a PHP string's memory. The docblock says so rather than overclaiming.

## Cryptography

The construction is boring on purpose: libsodium primitives, composed the way the documentation
says to compose them, with no invented cipher and no hand-rolled mode.

If you find this project *using* one of those primitives incorrectly — a reused nonce, an
unauthenticated path, a KDF misuse, a context string that doesn't bind what it claims to — that's
the report worth making, and the hardest kind to catch from the inside.
