---
title: "No compatibility shim for the Displace prototype"
description: "Why the plugin ships a read-time upgrade rather than a get_secret()/set_secret() shim for prototype-era code."
---

This plugin does not provide a compatibility layer for the Displace prototype that some plugins
were built against. There is no `get_secret()` / `set_secret()` shim, no reimplemented filter,
nothing that lets prototype-era code keep running unchanged. An earlier revision had one, and it
was removed on purpose. A shim that lets old code run indefinitely is the kind of "temporary"
surface that never goes away, and it doesn't serve the goal here, which is that adopting sites
don't break.

Recorded from the introduction of [the migration guide](../reference/migrating-from-displace.md), which describes what ships instead.
