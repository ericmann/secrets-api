---
title: "ADR 0011: Stay on Starlight for now"
description: "The docs site was evaluated for a move from Starlight to Blume. Blume 2.0.2 cannot run the site's link-rewriting Markdown plugin, so the site stays on Starlight until it can."
---

# ADR 0011: Stay on Starlight for now

| | |
|---|---|
| **Number** | 0011 |
| **Date** | 2026-09-24 |
| **Status** | Accepted |

## Context

The hosting side asked whether the documentation site could use [Blume](https://useblume.dev/docs)
instead of Astro Starlight. It was a preference about who maintains the framework, not a
requirement. A time-boxed spike built Blume 2.0.2 against this repository's real `docs/`.

Most of it worked. Blume read `docs/` from outside its project, built all 34 pages in about two
seconds, and handled pages with no description and the non-Markdown file in `docs/reference/`.

One thing did not work, and the site depends on it. `docs/` is written to be read on GitHub, so its
links are relative paths to `.md` files and to source files elsewhere in the repository. A small
Markdown plugin, `site/src/plugins/docs-links.mjs`, rewrites them at build time: a link to a page
becomes that page's route, and a link to anything else in the repository becomes a GitHub URL.
Blume 2.0.2 has no way to run it. Its processor's plugin list is exposed to Astro integrations and
can be changed, but the renderer never reads the changed list, and the plugin ran zero times
across three builds. Blume's built-in link handling does not replace it either. A link to the
repository's `README.md` 404s, and links between ADRs are sent to GitHub instead of staying on
the site. `blume validate` reported 11 broken links.

The only workarounds are to eject Blume into a plain Astro app, which gives up most of what it
offers, or to rewrite every link in `docs/`, which breaks them on GitHub.

## Decision

The site stays on Starlight. Neither workaround is taken.

## Consequences

- Nothing changes for the site, the publish workflow, or `docs/`.
- The question is worth reopening when Blume gives site authors a supported hook for Markdown
  plugins. The test is simple: `docs-links.mjs` must run during a Blume build, and the site's 33
  routes and 169 in-content links must come through unchanged.
- Whoever retries should start from that parity check rather than from a fresh spike. The rest of
  what the spike checked already worked.
