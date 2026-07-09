## 1. Webpack production source maps

- [ ] 1.1 In `webpack.config.js:10`, change `webpackConfig.devtool = isDev ? 'cheap-source-map' : 'source-map'` to `webpackConfig.devtool = isDev ? 'cheap-source-map' : false`, matching `pipelinq/webpack.config.js:10-14` and `openregister/webpack.config.js:9-13`.
- [ ] 1.2 Add the same rationale comment used in pipelinq/openregister (memory/time cost of full source maps; dev keeps `cheap-source-map`).
- [ ] 1.3 Rebuild (`npm run build`) and confirm `js/openbuild-main.js.map`, `js/openbuild-settings.js.map`, `js/openbuild-builder.js.map` are no longer emitted; record the before/after `js/` directory size in the PR description.

## 2. Remove unused bootstrap-vue dependency

- [ ] 2.1 Confirm zero usage: `grep -rl "bootstrap-vue" src/` returns nothing (already verified at proposal time; re-verify at implementation time in case of drift).
- [ ] 2.2 Remove `"bootstrap-vue": "^2.23.1"` from `package.json` `dependencies`.
- [ ] 2.3 Regenerate `package-lock.json` (`npm install`) and confirm `bootstrap-vue` is fully absent from the lockfile (no longer a transitive dependency of anything else in this app).

## 3. Verification

- [ ] 3.1 Run `npm run build` (production mode) end-to-end without errors after both changes.
- [ ] 3.2 Smoke-test the app in the dev container (main app view, admin settings, builder host) to confirm no runtime regression from the dependency removal.
- [ ] 3.3 Run `composer check:strict` equivalent frontend lint (`npm run lint`) — no new violations.
