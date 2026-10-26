---
title: "ADR 0014: The plugin keeps WP-CLI on a core that ships the API"
description: "Once WordPress ships the Secrets API, the plugin loads none of its own copy but still registers wp secret and wp network-secret, running on core's implementation. migrate-legacy does not come along."
---

# ADR 0014: The plugin keeps WP-CLI on a core that ships the API

| | |
|---|---|
| **Number** | 0014 |
| **Date** | 2026-10-26 |
| **Status** | Accepted |

## Context

The Secrets API landed in core trunk as r64337, for 7.2. Core took `src/` and left the WP-CLI
commands out: WP-CLI commands ship as WP-CLI packages rather than in core, and the commit message
says the commands stay in the feature plugin.

Until now the plugin's bootstrap went fully dark on a WordPress that ships the API. It loaded
nothing, and its admin notice said it could be deactivated. That made the commands disappear on
exactly the release the commit message promised them for.

## Decision

**On a WordPress that ships the API, the plugin registers `wp secret` and `wp network-secret` and
nothing else.** It loads none of `src/` or `plugin/`. The commands call the public API and the
internals core has as well (`_wp_secrets_get_key_manager()`, `_wp_secrets_get_provider()`,
`_wp_secrets_get_store()`, `WP_Secrets_Config_Key_Provider`), so the same `cli/` runs on core's
implementation unchanged. `wp secret health` loads core's
`wp-admin/includes/secrets-site-health.php` itself, since core loads it only for the Site Health
screen.

**`migrate-legacy` does not come along.** The prototype was deprecated and its users told to
migrate more than a month before 7.2. The migrator and legacy reader load only with the plugin's
own copy. On core, the subcommand stays registered, because WP-CLI registers every public method,
but it exits 1 with a message saying to migrate before upgrading.

**A conflict still registers nothing.** When something other than core declared
`wp_get_secret()`, the commands would run against an unknown credential store, so they stay off.

## Consequences

- The admin notice on a WordPress that ships the API says the plugin now provides only its WP-CLI
  commands, and can be deactivated by anyone who does not use them.
- `cli/` must keep calling nothing that only the plugin's copy defines. The CLI tests and the smoke
  test run against trunk in CI, which catches a call that would break there.
- The read-time prototype fallback store is gone on 7.2. A site still holding prototype rows
  has to run `migrate-legacy` on 6.8–7.1 before upgrading.
