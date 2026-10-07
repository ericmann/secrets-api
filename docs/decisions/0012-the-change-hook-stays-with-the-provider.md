---
title: "ADR 0012: The change hook stays with the provider, and reports scope"
description: "wp_secret_changed is fired by the provider rather than by the API functions, the conformance suite now checks that it is, and it gains a seventh argument saying whether the secret is network-scope."
---

# ADR 0012: The change hook stays with the provider, and reports scope

| | |
|---|---|
| **Number** | 0012 |
| **Date** | 2026-10-07 |
| **Status** | Accepted |

## Context

`wp_secret_changed` is the one hook in the core-bound code. It fires when a secret is created,
updated, imported, deleted, or has its previous version retired, and it exists so that a site can
keep an audit log. The shipped provider fires it from `set()`, `delete()`, and `retire_previous()`.

Review on the core patch, [#66187](https://core.trac.wordpress.org/ticket/66187), raised two
points about it.

**A drop-in provider can leave it out.** The hook is fired inside the provider, so a host that
replaces the provider and forgets the `do_action()` call silences the audit log, and nothing
notices. The suggestion was to fire it from the functions in `secrets.php` instead, where no
provider can skip it.

The check turned up more than the question asked. The interface stated the requirement on `set()`
only, not on `delete()` or `retire_previous()`. `WP_Secrets_Provider_Conformance` did not test for
the hook at all. And this repository's own AWS Secrets Manager example fired it on a write and not
on a delete.

**It does not say which scope changed.** The hook passed the name, the action, the actor, a
timestamp, and the old and new fingerprints. A network secret and a site secret can share a name
and are different credentials, and a listener had no way to tell which one changed.

## Decision

**The provider keeps firing the hook.** The hook reports the fingerprint the secret had before the
change. The provider has that in hand, because it reads the prior record in order to write the new
one. The API functions do not: they would have to read the secret before every write and again
after it. For a provider backed by a remote service that is two more network calls on each write,
and the answer can be stale by the time it is reported. A provider that never releases a value to
PHP could not answer at all.

**The requirement is now stated everywhere it applies, and tested.**

- The interface says, on `set()`, `delete()`, and `retire_previous()`, that the implementation
  fires the hook once the change has succeeded and fires nothing when nothing changed.
- `WP_Secrets_Provider_Conformance` checks it: a write, an overridden action, a delete, a retired
  version, a network-scope change, and a refused write on a read-only provider. It asks for all
  seven arguments, so a provider that passes fewer fails on the count.
- The AWS Secrets Manager example fires the hook on delete.

**The hook gains a seventh argument, `bool $network`.** It is true for a network-scope secret and
false for a site-scope one. A site-scope change belongs to the current site, which a listener
reads from `get_current_blog_id()`, so no site id is passed.

## Consequences

- A host's provider that omits the hook, or passes six arguments, fails the conformance suite. It
  still cannot be forced to run that suite. A provider is trusted code that already holds every
  credential on the site, and the hook is an audit aid, not a control.
- A listener registered for six arguments or fewer keeps working unchanged. One that wants the
  scope registers for seven.
- A provider written against 0.2.2 or earlier has to add the argument to each `do_action()` call.
  Both example providers have been updated.
- If a later design gives the API functions a cheap way to learn the prior fingerprint, firing the
  hook from them becomes possible. Nothing here prevents that.
