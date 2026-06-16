# Manifest fragments (ADR-037)

Drop modular manifest fragments here as `*.json` files. Each fragment may
declare `pages` and/or `menu` arrays.

`src/main.js`'s `mergeManifestFragments()` loads every `manifest.d/*.json` file
(via webpack `require.context`, sorted by filename) and concatenates their
`pages` and `menu` arrays onto the bundled base `src/manifest.json` before the
vue-router and `CnAppRoot` consume the merged manifest.

## Why

Each OpenSpec change adds its own fragment file instead of editing the shared
`src/manifest.json` monolith, so concurrent same-app builds touch disjoint
files and never collide on the manifest on merge.

`_placeholder.json` keeps the directory present (so `require.context` resolves
at build time) and is an inert empty fragment.
