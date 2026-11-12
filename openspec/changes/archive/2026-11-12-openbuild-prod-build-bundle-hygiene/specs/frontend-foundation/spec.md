## ADDED Requirements

### Requirement: Production build ships without full source maps and without unused dependencies

The production webpack build (`NODE_ENV=production`) SHALL set `devtool: false` for all entries (`main`, `adminSettings`, `builder`) — no `.map` file SHALL be emitted alongside the minified `js/` output. Development builds SHALL continue to use `cheap-source-map` for fast, line-level debugging. `package.json` SHALL NOT declare a runtime dependency that no file under `src/` imports.

#### Scenario: Production build emits no source maps

- **WHEN** `npm run build` runs with `NODE_ENV=production`
- **THEN** the `js/` output directory SHALL contain no `.map` files for `buildiq-main.js`, `buildiq-settings.js`, or `buildiq-builder.js`

#### Scenario: Development build keeps fast source maps

- **WHEN** the dev build runs (`NODE_ENV=development`)
- **THEN** `webpackConfig.devtool` SHALL be `cheap-source-map`

#### Scenario: No unused runtime dependency ships in package.json

- **WHEN** auditing `package.json` `dependencies` against actual imports under `src/`
- **THEN** every listed dependency SHALL have at least one importing file, OR be justified in a code comment (e.g. a peer dependency required transitively by a declared dependency)
