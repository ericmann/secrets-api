---
title: "ADR 0004: No compatibility shim for the prototype"
description: "Why prototype-era code gets a read-time upgrade path and a migration command rather than a get_secret()/set_secret() shim."
---

# ADR 0004: No compatibility shim for the prototype

| | |
|---|---|
| **Number** | 0004 |
| **Date** | 2026-08-27 |
| **Status** | Accepted |

## Context

Some plugins were built against an earlier prototype of this idea, calling its `get_secret()` and
`set_secret()` functions and reading its `_secret_*` option rows. An earlier revision of this
plugin shipped a compatibility shim behind a constant so that code could keep running unchanged.

A shim that lets old code run indefinitely is the kind of "temporary" surface that never goes
away. It would also have been a second implementation of a credential store running alongside the
first, on the same site, with its own bugs. The goal was never to preserve the prototype's API. It
was that sites adopting this one do not break.

## Decision

Remove the shim. Provide two narrower things instead.

**A read-time upgrade.** When `wp_get_secret()` finds no current-format record for a name, the
plugin's default store falls through to the prototype's row for that exact name, decrypts it,
writes a current-format record flagged `needs_rotation`, and returns it. The next read never
touches the prototype row again. Unnamespaced names are accepted for this reason and report through
`_doing_it_wrong()`. The mapping is exact: a namespaced name never inherits a bare prototype row,
so no namespace can claim another plugin's data.

**A bulk command.** `wp secret migrate-legacy` copies every prototype row, or one named row, into
the current format up front, keeping each key as spelled unless `--map` or `--namespace` says
otherwise. It is additive and idempotent.

The prototype's rows are never modified or deleted by anything in this plugin. An architectural
test enforces that.

## Consequences

- Porting means changing the function name at each call site, not the secret's name. Namespacing
  can follow later.
- Everything prototype-aware lives under `plugin/` and in one CLI subcommand, with nothing under
  `src/`. A documented deletion seam removes it in one commit when the window closes.
- There is no configuration surface for compatibility and nothing on the default request path
  once a record has been upgraded.
- Deleting a prototype row after migration is the operator's step, with `wp option delete`.
