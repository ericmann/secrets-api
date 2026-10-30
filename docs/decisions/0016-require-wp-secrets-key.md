---
title: "ADR 0016: Require WP_SECRETS_KEY"
description: "The default keyring no longer derives a key from LOGGED_IN_KEY and LOGGED_IN_SALT. Without a usable WP_SECRETS_KEY there is no key. Supersedes ADR 0015."
---

# ADR 0016: Require WP_SECRETS_KEY

| | |
|---|---|
| **Number** | 0016 |
| **Date** | 2026-10-30 |
| **Status** | Accepted. Supersedes [ADR 0015](0015-keep-the-salt-fallback-and-generate-a-key-at-install.md). |

## Context

[ADR 0015](0015-keep-the-salt-fallback-and-generate-a-key-at-install.md) kept the salt fallback and
had `setup-config.php` generate `WP_SECRETS_KEY` for new installs. Reviewing that installer patch
on [#66187 comment:27][c27], a committer found a second problem with the fallback. It reads
`LOGGED_IN_KEY` and `LOGGED_IN_SALT` directly. `wp_salt()` does not trust those constants as they
are: it treats a translated placeholder, or a value shared by two of the constants, as unusable and
generates its own salt instead. Localized `wp-config-sample.php` files have shipped translated
placeholders, for example in French between WordPress 5.4 and 6.0. The keyring rejected only the
English placeholder, so on those sites it derived the key from a public phrase. A test report on
[comment:29][c29] confirmed this for a translated placeholder and for duplicated values.

A patch rejected the translated placeholder with `__()`. That result depends on when the check runs
and who is logged in. Before `load_default_textdomain()`, which runs after `plugins_loaded`, `__()`
returns English. After that it follows the user's locale. The same site would accept the value in
one request and reject it in the next.

Salt rotation also stays a trap on every site without the key. Site Health can warn about that,
but it cannot prevent it.

## Decision

**There is no fallback.** Without a usable `WP_SECRETS_KEY`, every call that needs the key returns
`secret_key_unavailable`.

**Placeholders are rejected in every language.** A key equal to the English placeholder is not
usable. Neither is a key equal to any of the eight authentication keys and salts. An unfilled
sample gives all nine constants the same phrase, translated or not, so this rejects the placeholder
in any locale without calling `__()`.

**`wp-config-sample.php` gets a placeholder.** The line goes after the salts' `/**#@-*/`, outside
their group, whose docblock says they can be changed at any time. ADR 0015 kept the placeholder out
because the keyring would have hashed it into a public key. Now that the keyring rejects it,
the line tells anyone editing the file by hand that the key exists.

**`setup-config.php` writes the key like the salts.** It replaces the placeholder line. If the
sample has no such line, as with a custom or older localized sample, it adds the line after the
salts. Without a CSPRNG it falls back to `wp_generate_password()`, as the salts do. A weak key
loses nothing compared with no key, because the salt fallback that used to cover that case no
longer exists. Proposed in [#66399][t66399].

**Site Health gives the line to add.** Without a usable key, the key source test is
"recommended". It shows a newly generated `define()` line to add, or to put in place of an
unusable one.

**Why this reverses ADR 0015.** The two choices are not symmetric. A fallback can be added in a
later release without breaking anyone. Removing one after release strands every secret written
under it. ADR 0015 was right that consumers without a key have nowhere to store a credential
except a plaintext option. But a consumer has to handle `secret_key_unavailable` anyway: a broken
drop-in and placeholder salts already return it.

## Consequences

- A site upgraded to 7.2 cannot store secrets until `WP_SECRETS_KEY` is added. Hosts can define it
  for every site they run.
- A site that stored secrets under the fallback, during the 7.2 beta or with this plugin, moves to
  a dedicated key with `wp secret rotate`. Set `WP_SECRETS_KEY` to a new key and
  `WP_SECRETS_KEY_PREVIOUS` to the old `LOGGED_IN_KEY . LOGGED_IN_SALT`. A previous key in that
  shape is hashed the same way the fallback hashed the salts, so it reproduces the old site key.
- Rotating the salts no longer affects stored secrets.
- WP-CLI's `wp config create` writes its own config file and needs to generate the key too.
- `src/` follows core. The keyring, the Site Health test, and the docs that describe the fallback
  ([envelope encryption](../spec/envelope-encryption.md),
  [host-managed keys](../reference/host-managed-keys.md)) change when the core patch is ported.

[c27]: https://core.trac.wordpress.org/ticket/66187#comment:27
[c29]: https://core.trac.wordpress.org/ticket/66187#comment:29
[t66399]: https://core.trac.wordpress.org/ticket/66399
