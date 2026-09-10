---
title: "Hooks"
description: "Every action and filter the plugin fires, with parameters and every call site, generated from docblocks."
---

<!--
  GENERATED FILE. Do not edit by hand.
  Produced by bin/gen-reference.php from docblocks in the PHP source.
  Edit the docblock, then run `php bin/gen-reference.php`. CI fails when this
  file no longer matches the source.
-->

# Hooks

There is no filter anywhere in core-bound code, and nothing on the retrieval path fires a hook. What follows is the complete list.

## `wp_secret_changed`

**Type:** Action

Fires whenever a secret is created, updated, deleted, imported, or retired.

The only hook in the core-bound Secrets API code, and it does not fire on the
retrieval path. Fingerprints are readable; values are never passed.

| Parameter | Type | Description |
|---|---|---|
| `$name` | `string` | The secret's namespaced name. |
| `$action` | `string` | One of 'created', 'updated', 'deleted', 'imported', 'retired'. |
| `$actor_id` | `int` | The current user id, or 0. |
| `$timestamp` | `int` | Unix timestamp of the change. |
| `$old_fingerprint` | `string` | The previous fingerprint, or '' if none. |
| `$new_fingerprint` | `string` | The new fingerprint, or '' if the secret was deleted. |

**Since:** 7.2.0

**Fired from:**

- [`src/wp-includes/class-wp-secrets-libsodium-provider.php`](../../src/wp-includes/class-wp-secrets-libsodium-provider.php) (3 call sites)
