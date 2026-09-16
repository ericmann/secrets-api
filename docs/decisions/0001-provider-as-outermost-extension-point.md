---
title: "ADR 0001: Provider as the outermost extension point"
description: "Why WP_Secrets_Provider sits outside the store and keyring, what it replaced, and what it asks of implementers."
---

# ADR 0001: Provider as the outermost extension point

| | |
|---|---|
| **Number** | 0001 |
| **Date** | 2026-08-27 |
| **Status** | Accepted |

## Context

The [proposal][proposal] published two extension points behind a `wp-content/secrets.php`
drop-in: a store, for where ciphertext lives, and a keyring, for what wraps the master key.
Neither is ever handed a plaintext secret, and neither can turn encryption off.

Hosting-focused reviewers on the proposal thread showed the contract could not express
deployments they already run: a platform that holds the credential itself, protects it with its
own key service or hardware module, and serves it to WordPress over an authenticated channel.
Under the two-point contract those arrangements were banned, even though each protects a
credential at rest better than the default does. The proposal had asked whether providers could
stand in for a retrieval filter. The idea held; the shape was too narrow. One commenter on the
thread supplied the reframe that resolved it:

> A provider can be stronger than the default, never weaker. Plaintext storage stays banned… and
> the setups [the hosts] describe stop being banned with it.

## Decision

`WP_Secrets_Provider` becomes the outermost extension point, and the public functions route
through it. The provider WordPress ships, `WP_Secrets_Libsodium_Provider`, is one implementation of
that interface rather than a privileged case. It is composed from a `WP_Secrets_Store` and a
`WP_Secrets_Keyring`, both of which remain public, so a host that wants only its own key custody
still swaps the keyring alone.

The rule is restated as **a provider must be stronger than the default, never weaker**. Plaintext
at rest stays banned. "The store never sees plaintext" is now a property of the shipped provider,
not a rule imposed on every extension.

A provider declares `get_label()`, `get_protection_boundary()`, and `is_writable()`, replacing a
capability flag that had been bolted onto the store. `WP_Secret::reveal()` returns
`string|WP_Error` so a provider can name and fingerprint a credential it will not release to PHP.

## Consequences

- A platform that owns the credential implements the provider, eight methods. A platform that
  owns only keys implements the keyring, three methods. Picking wrong costs an API call per read.
- The declarations are documentation for Site Health, reviewers, and a future settings screen.
  Nothing enforces them. A drop-in is fully trusted code.
- What does not flex: no filter on the retrieval path, fail closed on a broken drop-in, and the
  three-state return. A conformance suite checks those for any implementation.
- Shipped in 0.1.0: the interface, the shipped provider composed from a store and keyring, the
  `wp_secrets_provider` global, `WP_Secret::withheld()`, and reporting of the provider's label,
  boundary, and writability in Site Health and `wp secret dropin`.
- Nobody has built a real platform provider against this yet. The first one will find something.
  The journal's [open questions](../journal/open-questions.md#host-and-platform-providers) track
  that alone.

[proposal]: https://make.wordpress.org/core/2026/08/25/proposal-a-secrets-api-for-wordpress-7-2/
