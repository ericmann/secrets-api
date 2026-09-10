---
title: "Access control language"
description: "Namespacing is organisational, not access control; the phrasing the docs must keep using."
---

## 🟢 Access control language — decided, keep the docs accurate

**Namespacing is organisational, not access control, and was never intended as
anything else.** It groups secrets by owner so listings and a future admin screen can be sensible.
It is not a visibility or privilege boundary.

This matters because the prior proof-of-concept's README described namespace-based access control
in a way that led people to believe one plugin's secret was inaccessible to another. Darin Kotter
raised it in the comments. It was not true then, it is not true now, and the docs must not drift
back toward implying it.

**The phrasing to keep using:** there is no per-plugin isolation. Any plugin that can run PHP can
read any secret. Masking is hygiene against shoulder-surfing and accidental logging, not a
privilege boundary.

Tracking-only now: applied in `README.md`, in `wp_secrets_validate_name()`'s docblock, and in the
`_doing_it_wrong()` message an unnamespaced name produces. Anything new that describes namespaces
should match.

