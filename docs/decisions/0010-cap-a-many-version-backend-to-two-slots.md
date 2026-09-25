---
title: "ADR 0010: Cap a many-version backend to two slots"
description: "The Vault KV v2 provider example sets max_versions: 2 on every secret it creates and defines PREVIOUS as strictly version N-1, so a backend that keeps ten versions still behaves like the API's two-slot model."
---

# ADR 0010: Cap a many-version backend to two slots

| | |
|---|---|
| **Number** | 0010 |
| **Date** | 2026-09-24 |
| **Status** | Accepted. |

## Context

[The detailed spec for the Vault provider example](../../examples/vault-provider/SPEC.md) built
the first provider whose backend does not already share the API's two-slot shape. HashiCorp
Vault's KV v2 secrets engine numbers versions 1, 2, 3, and so on, and keeps up to `max_versions`
of them — 10 by default. The Secrets API exposes exactly two: `WP_Secret_Version::CURRENT` and
`::PREVIOUS`.

Two problems follow from that mismatch. First, any version older than N-1 that Vault still keeps
is readable to anyone holding a Vault token, even though nothing in WordPress can see or retire
it — the value is protected by Vault, but the API's model of "there is a current value and a
previous one, and nothing else" no longer describes what actually exists. Second, "previous" has
no obvious definition once there are more than two versions: is it N-1, or the newest version that
has not been deleted or destroyed? Those two readings differ the moment a version other than N-1
goes missing, and only one of them is safe to expose through
[`wp_retire_secret_version()`](../../examples/vault-provider/README.md#1-what-previous-is), whose
whole purpose is to make a compromised credential unreachable.

## Decision

`Vault_KV2_Provider` in [`examples/vault-provider/secrets.php`](../../examples/vault-provider/secrets.php)
makes Vault a two-slot store rather than teaching the API about N versions:

- On creating a secret, it sets `max_versions: 2` in the secret's metadata, so Vault itself stops
  keeping anything older than the current pair.
- `PREVIOUS` is defined as strictly version N-1. If N-1 is missing, soft-deleted, or destroyed,
  the result is `null` — never the newest surviving version below N. `previous_version()` is the
  one place this rule lives.
- `retire_previous()` destroys version N-1 outright rather than soft-deleting it, because a
  soft-deleted version can still be undeleted and retiring is meant to make the value gone for
  good.

This amends nothing in [ADR 0008](0008-the-trac-ticket-replaces-thread-confirmation.md); it
records what that ADR's Vault example turned up.

## Consequences

- A secret created outside this provider — by `vault kv put` directly, by an older policy, or by
  a different tool against the same mount — keeps whatever `max_versions` it already has, and may
  still hold versions WordPress cannot see or retire. The provider only enforces the cap on
  secrets it creates itself.
- Retiring can leave no previous version at all. That is by design: a backend with nothing to
  promote into `PREVIOUS` is the correct outcome of "make the compromised value unreachable," not
  a bug to work around.
- The interface docblocks for `get()` and `retire_previous()` do not yet say what "previous" means
  on a backend with more than two versions. That gap is recorded in
  [`docs/journal/open-questions.md`](../journal/open-questions.md) and goes to the Trac ticket
  description as a docblock clarification, per
  [ADR 0008](0008-the-trac-ticket-replaces-thread-confirmation.md)'s review path.
- If capping at two slots turns out to be wrong in practice — for example, if a real deployment
  needs the versions Vault would otherwise have kept — this is the record to amend.
