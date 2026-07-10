## 1. Webpack production source maps

- [x] 1.1 In `webpack.config.js`, changed `devtool` to `isDev ? 'cheap-source-map' : false`, matching pipelinq/openregister.
- [x] 1.2 Added the shared rationale comment (memory/time cost of full source maps; dev keeps `cheap-source-map`; openbuild bundles the nextcloud-vue lib per-entry across three entries).
- [x] 1.3 Rebuilt (`NODE_ENV=production npm run build`, exit 0) and confirmed NO `.map` files are emitted into `js/` (`ls js/*.map` → none). `js/` is 18M (main 7.7M, builder 5.3M, settings 3.3M) with zero `.map` companions. (Exact before/after delta needs a clean baseline build for the PR; the maps are demonstrably gone.)

## 2. Remove unused bootstrap-vue dependency

- [x] 2.1 Re-verified zero usage: `grep -rl "bootstrap-vue" src/` returns nothing.
- [x] 2.2 Removed `"bootstrap-vue": "^2.23.1"` from `package.json` `dependencies`.
- [x] 2.3 Regenerated `package-lock.json` (`npm install --package-lock-only`) — `bootstrap-vue` is now fully absent from the lockfile (`grep -c bootstrap-vue package-lock.json` → 0), including all deps that were solely transitive to it. (Used `--package-lock-only` because this worktree symlinks the shared `node_modules`; the lockfile is a real per-worktree file, node_modules untouched.)

## 3. Verification

- [x] 3.1 `npm run build` (production) completed end-to-end without errors after both changes (2 pre-existing non-blocking warnings: entrypoint size limit).
- [ ] 3.2 Smoke-test the app in the dev container (main view, admin settings, builder host). DEFERRED — needs a live instance; the removed dependency has zero `src/` importers, so the removal is a behavioural no-op, and all three bundles compile clean.
- [x] 3.3 `npm run lint` (eslint) on `webpack.config.js` — clean (0 errors). Also fixed 3 pre-existing lint errors in the touched file (2 unnecessary quote-props, 1 extraneous `semver` require now annotated as an intentional optional transitive dep).
