---
title: "The five questions the proposal asked the community"
description: "Where answers from the proposal's comment thread are recorded, so they land somewhere rather than being absorbed into an assumption."
date: 2026-09-04
---

## 🟢 The five questions the proposal asked the community

These have a home here so answers from the comments thread land somewhere rather than being
absorbed into an assumption.

1. **Is the no-filter decision on retrieval sufficient, with providers as the substitution?**
   — **Answered, and the answer was "not as published."** Hosts independently found the
   provider contract could not express their deployments. The no-filter decision itself was never
   challenged; what failed was the contract's shape. See
   [ADR 0001](../decisions/0001-provider-as-outermost-extension-point.md) for the resolution and
   [Host and platform providers](open-questions.md#host-and-platform-providers) for what is still
   open.
2. **Are two version slots (`CURRENT`/`PREVIOUS`) adequate, or is a different rotation pattern
   necessary?** — no objections raised. The AWS Secrets Manager example is supporting evidence:
   its `AWSCURRENT`/`AWSPREVIOUS` staging labels are the same two slots, so the model needed no
   emulation there. `'v' => 1` leaves room to change this, but see
   [ADR 0006](../decisions/0006-record-format-v2-not-read-compatible.md) for what a format bump
   would mean.
3. **Does `wp_import_option_as_secret()` fit actual plugin migration workflows?**
   — no objections raised, and no plugin outside this project has used it yet.
4. **Which WP-CLI commands most need this surface, and in what priority order?** — no objections
   raised to the implemented set, and no requests for others.

Questions 2 to 4, and the names this implementation added beyond the proposal, have been in front
of the community through the make/core thread, the docs site, Core Slack, and core dev chat.
The response has been support without critical feedback. That is silence rather than
confirmation, and it is recorded as such.
5. **For hosts running secret stores or key backends: what is missing from the drop-in surface?**
   — answered at length by two hosting platforms on the thread; see
   [ADR 0001](../decisions/0001-provider-as-outermost-extension-point.md) and
   [Host and platform providers](open-questions.md#host-and-platform-providers).

