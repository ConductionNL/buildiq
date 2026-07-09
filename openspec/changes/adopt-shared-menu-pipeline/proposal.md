---
kind: code
---

## Why

`src/main.js:69-84` defines a bespoke `mergeManifestFragments(base)`
function that manually concatenates each `src/manifest.d/*.json`
fragment's `pages[]`/`menu[]` arrays onto the bundled base manifest. This
is exactly the kind of per-app reimplementation `hydra/openspec/architecture/
adr-044-menu-architecture.md` was written to eliminate: the ADR's Context
section describes "every manifest-v2 app carried an identical ~150-line
copy of that pipeline inline in `src/main.js`" and its Decision §1 states
apps MUST build their effective manifest via the shared
`@conduction/nextcloud-vue` `buildManifest(base, fragments, menuLayout)`
util — "No app may re-implement `mergeMenuItems` / `applyMenuRelocations`
/ `applyMenuRemovals` / `applySettingsSection` inline."

OpenBuild already satisfies the ADR's prerequisite (§6): it has a working
`src/manifest.d/` fragment pipeline (confirmed at
`src/main.js:71-82`, collecting via `require.context('./manifest.d/', ...)`),
unlike the "monolithic manifest" apps the ADR calls out as needing an extra
refactor step first. But OpenBuild is not among the "Shipped 2026-06" adopter
list in the ADR's Consequences section (shillinq, pipelinq, procest,
openregister, decidesk, openconnector, opencatalogi, softwarecatalog,
larpingapp, doriath) — it is the one fragment-pipeline-ready app that has not
adopted the shared `buildManifest()` util, and it has no
`src/menu-layout.json` (checked: no such file exists in `src/`).

Practically, OpenBuild's own `mergeManifestFragments` only concatenates
`pages`/`menu` arrays — it has none of `buildManifest()`'s relocation,
removal, or settings-foldout-placement logic, so OpenBuild cannot declare a
`menu-layout.json` today even if a future navigation change wanted to move
an entry (e.g. "Documentation") into the settings foldout without editing
`main.js` again.

## What Changes

- Replace `src/main.js`'s local `mergeManifestFragments(base)` function
  (lines 69-84) with a call to `@conduction/nextcloud-vue`'s exported
  `buildManifest(base, fragments, menuLayout)`, passing the
  `require.context('./manifest.d/', false, /\.json$/)`-collected fragments
  array unchanged (the fragment-collection step itself stays app-local per
  ADR-037 — only the merge/relocate/remove pipeline moves to the shared
  util).
- Add `src/menu-layout.json` with the three ADR-044 keys (`relocations`,
  `removals`, `settingsSection`), initially empty/no-op (`{}` or all-empty
  arrays) so behaviour is unchanged on landing — this is the enabling step,
  not a navigation-IA redesign.
- No BREAKING changes — `buildManifest()` with empty `menu-layout.json`
  keys reproduces today's `pages`/`menu` concatenation exactly (a
  regression check against pre-change route/menu output is the acceptance
  gate).
- Explicitly out of scope: any actual menu-layout redesign (moving
  "Documentation" or other entries into the settings foldout, cards-collapse
  for deep groups). OpenBuild's menu is currently shallow (5 top-level
  items, no children), so §3/§4 of the ADR do not yet apply substantively —
  this change only closes the "shared pipeline, not a per-app copy" gap
  (§1) and puts `menu-layout.json` in place for future navigation work.

## Capabilities

### Modified Capabilities

- `frontend-foundation`: the app-shell manifest-build step now delegates to
  the shared `@conduction/nextcloud-vue` `buildManifest()` pipeline instead
  of a local re-implementation (delta spec at
  `specs/frontend-foundation/spec.md`).

## Impact

- **No external dependency wait:** the installed
  `node_modules/@conduction/nextcloud-vue` (per `package.json`'s
  `^1.0.0-beta.138` range) already exports `buildManifest`,
  `applyMenuLayout`, `mergeMenuItems`, `mergePages`,
  `applyMenuRelocations`, `applyMenuRemovals`, and `applySettingsSection`
  from `src/utils/buildManifest.js` (re-exported via `src/index.js:273`) —
  confirmed by direct inspection. This change can proceed without waiting
  on an upstream release.
- Files touched: `src/main.js` (remove `mergeManifestFragments`, call
  `buildManifest`), new `src/menu-layout.json`.
- No backend, schema, or route changes.
