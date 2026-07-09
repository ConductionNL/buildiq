---
kind: code
---

## Why

`webpack.config.js:10` sets `webpackConfig.devtool = isDev ? 'cheap-source-map' : 'source-map'` — production builds emit the **full** `source-map` devtool, which generates a complete `.map` file per bundle (`openbuild-main.js.map`, `openbuild-settings.js.map`, `openbuild-builder.js.map`) alongside the minified output under `js/`. Two sibling apps in this fleet hit this exact problem and fixed it: `pipelinq/webpack.config.js:10-14` and `openregister/webpack.config.js:9-13` both disable source maps in production (`isDev ? 'cheap-source-map' : false`), with the pipelinq comment recording the concrete cost — "added significant memory and time on top of compilation, and emitted ~77 MB of .map files into js/". OpenBuild has three entries (`main`, `adminSettings`, `builder` — `webpack.config.js:20-31`) plus the `@conduction/nextcloud-vue` shared library bundled per-entry, so it is exposed to the same class of cost; it was simply never re-aligned when pipelinq/openregister fixed theirs.

Separately, `package.json` declares `"bootstrap-vue": "^2.23.1"` as a runtime dependency, but no `.vue`/`.js` file anywhere in `src/` imports from `bootstrap-vue` (verified: `grep -rl "bootstrap-vue" src/` returns nothing). It is dead weight in the dependency tree — pulled into `node_modules`, scanned by every install/audit, and a candidate for accidental introduction into a bundle if anyone types the import later, with no product benefit today.

## What Changes

- **Disable source maps in production builds** — change `webpack.config.js:10` to `webpackConfig.devtool = isDev ? 'cheap-source-map' : false`, mirroring the proven pipelinq/openregister fix. Dev keeps `cheap-source-map` unchanged.
- **Remove the unused `bootstrap-vue` dependency** from `package.json` `dependencies` (and regenerate the lockfile). No source file references it.
- **No BREAKING changes.** Dev-mode debugging is unaffected (dev keeps source maps); nothing in `src/` imports `bootstrap-vue`, so removing it is a no-op for behaviour.

## Capabilities

### New Capabilities

_None._

### Modified Capabilities

- `frontend-foundation`: adds a requirement that production builds ship without full source maps and that `package.json` carries no unused runtime dependency.

## Impact

- `openbuild/webpack.config.js` — one line changed (`devtool`).
- `openbuild/package.json` — one dependency entry removed; lockfile regenerated.
- Build output: production `js/` no longer contains `.map` files; smaller deploy artifact, lower peak build memory/time (per pipelinq's measured ~77 MB reduction, this repo's actual figure should be measured post-fix and noted in the PR).
