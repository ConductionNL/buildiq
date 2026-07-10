## 1. Confirm the shared util contract

- [x] 1.1 Confirm `@conduction/nextcloud-vue`'s installed version exports
      `buildManifest(base, fragments, menuLayout)` (already verified present
      at `node_modules/@conduction/nextcloud-vue/src/utils/buildManifest.js`
      and re-exported from `src/index.js`); read its signature and
      `menuLayout` shape (`relocations`, `removals`, `settingsSection`)
      before wiring it in.

## 2. Replace the bespoke merge function

- [x] 2.1 In `src/main.js`, import `buildManifest` from
      `@conduction/nextcloud-vue` alongside the existing
      `CnPageRenderer`/`defaultPageTypes`/`registerIcons`/`registerTranslations`
      import.
- [x] 2.2 Add `import menuLayout from './menu-layout.json'`.
- [x] 2.3 Keep the existing `require.context('./manifest.d/', false,
      /\.json$/)` fragment-collection block (ADR-037's app-local step is
      unchanged) but pass the resolved fragment array into
      `buildManifest(bundledManifest, fragments, menuLayout)` instead of the
      local `mergeManifestFragments`.
- [x] 2.4 Delete the local `mergeManifestFragments` function
      (`src/main.js:69-84`) once `buildManifest` produces an equivalent
      `mergedManifest`.

## 3. Add menu-layout.json

- [x] 3.1 Create `src/menu-layout.json` with the three ADR-044 keys, all
      empty/no-op initially: `{ "relocations": {}, "removals": [],
      "settingsSection": [] }`.
- [x] 3.2 Document in a comment (or the file's README convention used by
      other adopter apps, if any) that this file is the single place future
      navigation-IA changes are declared, per ADR-044 §2.

## 4. Regression check (no-functionality-loss invariant)

- [x] 4.1 Diff the resolved `mergedManifest.pages` and `.menu` arrays
      before/after this change (e.g. a one-off script or a Vitest snapshot)
      — with empty `menu-layout.json` keys, `buildManifest()`'s output MUST
      be array-equal to the old `mergeManifestFragments()` output.
- [ ] 4.2 Manual smoke test: every existing OpenBuild page (Dashboard, Apps,
      Store, Documentation, Features & roadmap, Business Rules) remains
      reachable via its existing route and its existing menu entry (or the
      settings foldout for Features & roadmap, unchanged). DEFERRED — needs a
      live instance; covered structurally by the array-equality regression spec
      (task 4.1) which proves the merged pages/menu are byte-identical.
- [ ] 4.3 Run the existing Playwright suite for navigation-adjacent specs
      (`app-nav-entries`) to confirm no route/menu regression. DEFERRED — needs
      a running instance (Playwright e2e).

## 5. Verify

- [x] 5.1 `npm run lint` and `npm run build` succeed.
- [x] 5.2 `npm test` (Vitest) passes, including any new snapshot/regression
      test from task 4.1.
