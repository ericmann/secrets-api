---
title: "ADR 0005: Namespaces are not access control"
description: "Namespacing groups secrets by owner for listings and future screens. It confers no isolation, and the documentation must never imply that it does."
---

# ADR 0005: Namespaces are not access control

| | |
|---|---|
| **Number** | 0005 |
| **Date** | 2026-08-27 |
| **Status** | Accepted |

## Context

Secret names take the form `plugin-slug/secret-name`. An earlier proof of concept described its
namespacing in terms that led readers to believe one plugin's secret was inaccessible to another.
A reviewer on the [proposal][proposal] thread raised this directly.

It was not true then and it is not true now. Every plugin runs as the same PHP process with the
same database credentials and the same key material. Nothing in WordPress can give one plugin a
secret and withhold it from another, and a design that pretended otherwise would be a promise the
platform cannot keep.

## Decision

Namespacing is organisational, not a security boundary, and was never intended as anything else.
It groups secrets by owner so that listings and a future admin screen can be sensible.

The phrasing to use, everywhere, is: there is no per-plugin isolation. Any plugin that can run PHP
can read any secret. Masking in `WP_Secret` is hygiene against shoulder-surfing and accidental
logging, not a privilege boundary.

That phrasing is applied in the README, in the docblock of `wp_secrets_validate_name()`, and in
the `_doing_it_wrong()` message an unnamespaced name produces.

## Consequences

- Anything new that describes namespaces must match the phrasing above. Drift back toward
  implying isolation is a documentation bug worth fixing on sight.
- Unnamespaced names are accepted, for the prototype upgrade path, and two plugins that both
  choose `api-key` collide silently. That is a real problem, but not a security one.
- Capability checks belong at the operator boundary, in WP-CLI and any future screen, not inside
  the functions, which must work from cron, REST, and anonymous requests.

[proposal]: https://make.wordpress.org/core/2026/08/25/proposal-a-secrets-api-for-wordpress-7-2/
