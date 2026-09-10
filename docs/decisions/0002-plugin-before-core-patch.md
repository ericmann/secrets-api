---
title: "ADR 0002: Plugin before core patch"
description: "Why the Secrets API ships as a feature plugin first, and what finished means for the plugin and for the patch."
---

# ADR 0002: Plugin before core patch

| | |
|---|---|
| **Number** | 0002 |
| **Date** | 2026-08-25 |
| **Status** | Accepted |

## Context

The [proposal][proposal] targets WordPress 7.2 for the API and WP-CLI surface, with any admin
screen deferred to 7.3. Storage and retrieval semantics have to be right the first time, because
every plugin, command, and future screen inherits them, and a shipped surface cannot change
without cost.

A proposal on make/core gets read. It does not get run. The awkward parts of an API show up when
someone writes a drop-in against it, rotates a key on a network, or pipes a value through WP-CLI,
and none of that happens to a document.

## Decision

Publish the implementation as a feature plugin first, and write it so the core patch is a copy
rather than a port.

Everything under `src/` follows core's paths, coding standard, `default` text domain, and
`@since 7.2.0` annotations, and carries no `function_exists()` guards. Architectural tests enforce
that boundary. Everything the plugin needs that core would not, the bootstrap, WP-CLI commands, and
the prototype upgrade path, lives under `plugin/` and `cli/` and is never copied.

The plugin stands down on its own when it detects the API in core, gated on both the version and
the presence of the symbol, so a slip to 7.3 cannot silently disable it.

## Consequences

**The plugin is done when:**

- the public surface matches the proposal, with every addition beyond it recorded and confirmed
  on the thread;
- `make ci` is green across the PHP 7.4 to 8.3 and single-site to multisite matrix;
- at least one real platform provider has been built against `WP_Secrets_Provider` and the
  conformance suite, and what it turned up has been fixed;
- sites on WordPress 6.6 and later can run it in production today.

**The patch is done when:**

- `src/` has been copied into `wordpress-develop` unchanged, with the drop-in loading moved into
  `wp-settings.php` and the two capabilities granted by core's role setup rather than an
  activation hook;
- the tests run inside core's own suite;
- it lands on Trac before 7.2 Beta 1, matching the plugin's surface at that point.

The cost is two release trains for one code base until the patch lands.

[proposal]: https://make.wordpress.org/core/2026/08/25/proposal-a-secrets-api-for-wordpress-7-2/
