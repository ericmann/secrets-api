---
title: "ADR 0017: Abstract classes for the extension points"
description: "WP_Secrets_Provider, WP_Secrets_Store and WP_Secrets_Keyring become abstract classes, so a later release can add a method without breaking every drop-in."
---

# ADR 0017: Abstract classes for the extension points

| | |
|---|---|
| **Number** | 0017 |
| **Date** | 2026-11-06 |
| **Status** | Accepted |

## Context

The three extension points, `WP_Secrets_Provider`, `WP_Secrets_Store` and `WP_Secrets_Keyring`,
were interfaces from 0.1.0 on ([ADR 0001](0001-provider-as-outermost-extension-point.md)).
Reviewing the code in core, a committer pointed out on [#66187 comment:45][c45] that once 7.2
ships, core can never add a method to them. A class that implements an interface without one of
its methods is a fatal error, so every drop-in written against 7.2 would break.

There are already two methods we expect to want:

- A way for a provider or keyring to say whether it is usable. `wp_is_secrets_api_available()`,
  added in [PR 15258][pr15258] at a committer's request, can only answer that for the default
  keyring. For a drop-in it assumes yes.
- A path to re-wrap the root key under a new site key, planned for 7.3, which may need a keyring
  method.

Interfaces were the first choice, and they are still the cleaner contract: a drop-in class can
extend whatever it likes, and PHP checks the whole contract. The alternative to switching was to
keep the interfaces and add every later capability as a separate, optional interface checked with
`instanceof`. That keeps compatibility, but every new feature then needs a new type and a check at
each call site.

## Decision

**The three become abstract classes.** Their methods become `abstract public`, with the same
signatures. The default and fail-closed implementations `extend` them. The files are renamed from
`interface-wp-secrets-*.php` to `class-wp-secrets-*.php`. Proposed in [PR 15744][pr15744].

**A later release adds methods with a default implementation.** That is the reason for the change,
and the class docblocks say so.

**A test pins them as abstract classes**, so they can't become interfaces again by accident.

## Consequences

- A drop-in changes `implements` to `extends`. Nothing else in the contract changes. 7.2 has not
  shipped, so the only drop-ins affected are those written against the beta or this plugin.
- A drop-in that still uses `implements` is a fatal error on every request, WP-CLI included. It
  does not fail closed. PHP reports it while compiling the drop-in, before the `try`/`catch`
  around the `require` can run. Catching it would mean reading the drop-in's source before
  requiring it, on every request, which isn't worth it for drop-ins written against a beta. The
  0.5.0 changelog and the 7.2 beta 5 announcement call it out instead.
- A drop-in class can no longer extend another base class, such as an SDK client. It can still hold
  one and delegate to it, as the examples already do.
- The conformance suites, `WP_Secrets_Provider_Conformance` and `WP_Secrets_Keyring_Conformance`,
  work as before.
- `src/` follows core. The classes, the examples in `examples/`, and the pages that call these
  interfaces ([extension points](../spec/extension-points.md),
  [providers and keyrings](../spec/providers-and-keyrings.md)) change when the core patch is
  ported.

[c45]: https://core.trac.wordpress.org/ticket/66187#comment:45
[pr15258]: https://github.com/WordPress/wordpress-develop/pull/15258
[pr15744]: https://github.com/WordPress/wordpress-develop/pull/15744
