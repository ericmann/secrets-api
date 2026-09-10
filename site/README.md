# Docs site

The documentation site for the Secrets API feature plugin, built with
[Astro](https://astro.build) and [Starlight](https://starlight.astro.build).

## Where the content lives

**In `../docs/`, not here.** The Starlight `docs` collection is pointed at the
repo's `docs/` folder (see `src/content.config.ts`), so that folder stays the
single source of truth and remains readable on GitHub. To change a page, edit
the Markdown under `docs/`. Nothing under `site/src/` is content.

The sidebar mirrors the `docs/` layout: `docs/index.md` is the overview, and
`spec/`, `reference/`, `decisions/`, `journal/`, and `changelog/` are groups in
that order. Journal entries sort newest first by their frontmatter `date`.

Relative links written for GitHub are rewritten at build time: links to
Markdown in `docs/` become site routes, and links to anything else in the repo
(the drop-in example, `examples/`, the root README) become GitHub URLs.

## Build

```sh
cd site
npm install
npm run build        # static output in site/dist
npm run dev          # local preview with live reload
```

From the repo root, `npm run docs:build` does the same build.

`_headers` and `_redirects` at the root of this directory are copied into
`dist/` by a small integration in `astro.config.mjs`.

## Publish

```sh
sf publish site/dist
```
