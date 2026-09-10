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

The site lives in one Spacefast Space, `spc_9bba714db6074646904fbfc3a3bb4fc9`, at
https://secrets-api-docs.view.fast/. Every publish is a new immutable version of that Space.

### From CI

`.github/workflows/docs-publish.yml` builds and publishes on every `v*.*.*` tag, and can be run
by hand from the Actions tab (`workflow_dispatch`, with an optional changelog message). It needs
one repository secret:

| Secret | Value |
|---|---|
| `SPACEFAST_TOKEN` | A Spacefast API key with write access to the Space above. Create it in the Spacefast dashboard and add it under the repository's Settings, Secrets and variables, Actions. |

The workflow refuses to start without the secret, publishes exactly once to the fixed Space id, and
fails the job on any error rather than retrying. A retry that missed the Space id would create a
stray Space, which is the one outcome it is built to prevent. The version receipt is written to the
job summary with credential-shaped fields redacted.

### By hand

```sh
sf publish site/dist --space spc_9bba714db6074646904fbfc3a3bb4fc9
```

Pass `--space` every time. The CLI stores its link to the Space in `site/dist/.spacefast/`, and
`astro build` clears that directory, so a bare `sf publish site/dist` after a rebuild would create
a second Space instead of a new version of this one.
