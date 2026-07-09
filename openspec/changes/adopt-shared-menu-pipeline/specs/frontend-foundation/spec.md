## ADDED Requirements

### Requirement: Shell manifest build uses the shared buildManifest pipeline

OpenBuild's own app-shell bootstrap (`src/main.js`) SHALL build its
effective runtime manifest via `@conduction/nextcloud-vue`'s shared
`buildManifest(base, fragments, menuLayout)` utility rather than a
locally re-implemented merge function. The app-local step SHALL be
limited to collecting `src/manifest.d/*.json` fragments (via
`require.context`, per ADR-037) and declaring `src/menu-layout.json`
(`relocations`, `removals`, `settingsSection`); no app code SHALL
re-implement `mergeMenuItems`, `applyMenuRelocations`,
`applyMenuRemovals`, or `applySettingsSection`.

#### Scenario: Manifest build delegates to the shared util

- **WHEN** the OpenBuild shell boots and resolves its effective manifest
- **THEN** the merge of the bundled base manifest with every
  `src/manifest.d/*.json` fragment is performed by
  `@conduction/nextcloud-vue`'s `buildManifest()`
- **AND** no locally-defined function duplicates its relocation, removal,
  or settings-foldout-placement logic

#### Scenario: Existing pages and menu entries remain reachable

- **WHEN** `buildManifest()` runs with an empty (no-op) `menu-layout.json`
- **THEN** the resolved `pages[]` and `menu[]` arrays are equivalent to the
  arrays produced by the prior local `mergeManifestFragments()` function
- **AND** every previously-reachable route stays reachable through its
  existing menu entry or the settings foldout
