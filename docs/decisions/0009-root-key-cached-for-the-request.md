---
title: "ADR 0009: Root key cached for the request"
description: "WP_Secrets_Key_Manager unwraps the root key at most once per request, keyed on the stored wrapped value, so a remote keyring pays one round trip per request instead of one per secret."
---

# ADR 0009: Root key cached for the request

| | |
|---|---|
| **Number** | 0009 |
| **Date** | 2026-09-24 |
| **Status** | Accepted. |

## Context

`WP_Secrets_Key_Manager::get_root_key()` unwrapped the root key on every call, and every master-key
derivation called it. A request reading ten secrets across site and network scope unwrapped the
root key ten times. For the default `WP_Secrets_Config_Key_Provider`, an in-process derivation,
that cost is trivial. For a keyring backed by a KMS or HSM, each unwrap is a network round trip
against a service billed per call, and `examples/README.md`'s "Start with a KMS keyring" section
already claimed the opposite: that a KMS "gets called once per request at most instead of once per
secret." That claim was aspirational, not built.

Writing the KMS keyring example first, as [ADR 0008](0008-the-trac-ticket-replaces-thread-confirmation.md)
schedules, surfaced this before the claim shipped to reviewers. The option considered and rejected
was to leave caching to each keyring implementation: every host writing a `WP_Secrets_Keyring`
would then have to build its own request-scoped memoisation to be usable at any real secret count,
each a fresh chance to get the "memory only, never the object cache" rule wrong.

## Decision

`WP_Secrets_Key_Manager` caches one unwrapped root key for the life of the object, which
`_wp_secrets_get_key_manager()` makes the life of the request. The cache is keyed on the stored
wrapped value, not on time or call count: `get_root_key()` serves the cached bytes only while
`get_site_option( ROOT_KEY_OPTION )` still returns the exact value the cache was unwrapped from. A
rotation, a re-wrap, or a restore changes that stored value, so the next `get_root_key()` unwraps
again rather than serving stale bytes. Root-key generation and `rotate_site_key()` both prime the
cache with the value they just produced, at no extra unwrap cost. An unwrap error is never cached,
so a transient failure does not stick for the rest of the request. The cache never touches
`wp_cache_*`, a transient, or any option other than `ROOT_KEY_OPTION`.

## Consequences

- One unwrapped copy of the root key lives in the key manager object for the request, in memory
  only. The class docblock says so plainly, since this is now load-bearing behavior a reviewer
  needs to see without reading the method bodies.
- The memzero discipline for callers of `get_root_key()` is unchanged: they still receive a copy
  and are still responsible for zeroing it. The key manager's own cached copy is not theirs to
  zero, and PHP's copy-on-write semantics mean a caller zeroing their copy cannot corrupt the
  cached one.
- A remote keyring now costs one call per request rather than one per secret, which is what
  `examples/README.md` already claimed before this existed.
- The cache is per key-manager instance. `_wp_secrets_get_key_manager()` already builds exactly one
  per request via a static local, so no new global or lifecycle concept is introduced.
- This lands in the Trac patch alongside the rest of the key manager; it is not a follow-up.
