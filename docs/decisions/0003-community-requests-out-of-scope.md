---
title: "ADR 0003: Community requests out of scope"
description: "Two requests from the proposal thread judged reasonable but outside the API: Two Factor integration and coordination with the AI plugin's key-encryption experiment."
---

# ADR 0003: Community requests out of scope

| | |
|---|---|
| **Number** | 0003 |
| **Date** | 2026-08-26 |
| **Status** | Accepted |

## Context

The [proposal][proposal] thread produced two requests that sit next to the API rather than inside
it. One reviewer asked for integration with the Two Factor plugin, which stores per-user secrets
of its own. Another asked whether the developer and user experience should be iterated inside the
AI plugin's key-encryption experiment before the API reaches core, since that plugin already
carries a vendored copy of the earlier prototype.

Both are reasonable. Neither is a property of the storage and retrieval semantics the 7.2 target
has to get right.

## Decision

Neither request is in scope for the API or for this plugin.

Two Factor integration is a consumer question: whether that plugin should be an early adopter of
`wp_get_secret()` is for its maintainers, and the answer says nothing about the API's shape. It is
worth a note to them once the surface is stable.

The AI plugin is touched by this project in exactly one place: the read-time upgrade of records
left in the prototype's format, which never modifies or deletes the plugin's own rows. Whether the
two efforts should coordinate their user experience is a project-level question for the release,
not one this plugin can answer by adding code.

## Consequences

- No Two Factor or AI plugin specific code exists anywhere in the repository, and none is planned
  before the core patch lands.
- Both requests stay recorded in the journal so an answer from the thread has somewhere to land
  rather than being absorbed into an assumption.
- If either plugin adopts the API, the right response is a bug report against this project about
  what the adoption turned up, not a special case in this code.

[proposal]: https://make.wordpress.org/core/2026/08/25/proposal-a-secrets-api-for-wordpress-7-2/
