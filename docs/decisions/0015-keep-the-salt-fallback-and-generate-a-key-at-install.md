---
title: "ADR 0015: Keep the salt fallback, and generate a key at install"
description: "Rotating the salts locks every secret on a site that derives its key from them. The fallback stays, because removing it pushes consumers back to plaintext options. New installs get a dedicated WP_SECRETS_KEY from setup-config.php instead."
---

# ADR 0015: Keep the salt fallback, and generate a key at install

| | |
|---|---|
| **Number** | 0015 |
| **Date** | 2026-10-28 |
| **Status** | Accepted |

## Context

When `WP_SECRETS_KEY` is not defined, the default keyring derives the site key from
`LOGGED_IN_KEY . LOGGED_IN_SALT`. Every site that installs 7.2 without editing `wp-config.php`
uses that fallback.

Rotating the salts is common advice. `wp config shuffle-salts` does it, and security plugins offer
it as a button or a schedule. Before 7.2 the only cost was that every user had to log in again. On
a site using the fallback it now makes every stored secret unreadable and blocks saving new ones,
because the root key is wrapped under a key that no longer exists. Restoring the old values recovers
everything. If they were not kept, the secrets are lost.

This was raised on [#66187 comment:23][c23], which proposed removing the fallback and returning an
error instead. A test report on [comment:24][c24] confirmed it on 7.2-beta2. It also showed that
Site Health called the secrets unrecoverable when restoring the salts recovers them.

## Decision

**The fallback stays.** Without it, `wp_set_secret()` returns `WP_Error` on every site that has
not defined `WP_SECRETS_KEY`, which on 7.2 is nearly all of them. Each consumer would then need
somewhere else to put the credential, and that place is a plaintext option: the exposure this API
exists to end. Failing closed already prevents data loss while the old salts exist. What the
fallback lacks is a way to avoid it.

**New installs get a dedicated key.** `setup-config.php` already generates the eight keys and
salts. It also writes `WP_SECRETS_KEY` as a base64-encoded random 32-byte value, after the salts
and outside their docblock group, whose advice to change them at any time does not apply. A site
installed this way never uses the fallback. Proposed in [#66399][t66399].

**`wp-config-sample.php` gets no placeholder.** The keyring rejects the salt placeholder, but it
hashes any `WP_SECRETS_KEY` string. A placeholder in the sample would give every hand-edited
install that left it alone the same public key. A config copied from the sample keeps the fallback,
and Site Health recommends a dedicated key.

**No CSPRNG, no key.** Where `random_bytes()` throws, `setup-config.php` leaves the define out
rather than use the weaker sources it falls back to for the salts. The site then uses the fallback.

**Site Health says what happened.** The patch on [#66380][t66380] reports a key that cannot
unwrap the root key as critical, names the salts as a cause, and says restoring the previous value
fixes it. Once secrets exist, it warns that changing the salts makes them unreadable. The plugin
takes that change from core once it lands.

## Consequences

- Sites installed before this change, and sites whose `wp-config.php` was written by another
  installer, keep the fallback. WP-CLI's `wp config create` and hosts' one-click installers write
  their own config and would need to generate the key themselves.
- Moving an existing site off the fallback means re-wrapping the root key. Core has no public path
  for that in 7.2. The plugin's `wp secret rotate` does it: set `WP_SECRETS_KEY_PREVIOUS` to the
  old `LOGGED_IN_KEY . LOGGED_IN_SALT` and `WP_SECRETS_KEY` to a new key. The same command
  recovers a site whose salts were rotated, as long as the old values were kept. A public path in
  core is planned for 7.3.
- The 7.2 dev note has to say that rotating the salts on a site without `WP_SECRETS_KEY` makes its
  secrets unreadable.
- None of this changes `src/`. The plugin cannot reach core's installer, so the change ships only
  in core.

[c23]: https://core.trac.wordpress.org/ticket/66187#comment:23
[c24]: https://core.trac.wordpress.org/ticket/66187#comment:24
[t66380]: https://core.trac.wordpress.org/ticket/66380
[t66399]: https://core.trac.wordpress.org/ticket/66399
