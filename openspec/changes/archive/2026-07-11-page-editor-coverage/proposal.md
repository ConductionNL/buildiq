---
kind: code
---

## Why

The manifest v2 page-type enum
(`@conduction/nextcloud-vue/src/schemas/app-manifest-v2.schema.json`,
`$defs.page.properties.type`, line 1779) is a closed enum of the 14
supported page types, and the library's renderer
(`src/components/CnPageRenderer/pageTypes.js`, `defaultPageTypes`)
ships a page component for twelve of them — including `map`
(`CnMapPage`), `roadmap` (`CnFeaturesAndRoadmapPage`), `search`
(`CnSearchPage`) and `wiki` (`CnWikiPage`). OpenBuild already depends
on a library version that renders all four
(`package.json`: `@conduction/nextcloud-vue: ^1.0.0-beta.168`).

The Page Designer has not kept up. Verified against HEAD:

- `src/views/PageDesigner.vue:153-163` — `SUB_EDITOR_MAP` wires exactly
  nine types (`index`, `detail`, `dashboard`, `form`, `logs`,
  `settings`, `chat`, `files`, `custom`);
  `src/views/PageDesigner.vue:474` (`subEditorFor`) sends everything
  else to `StubPageEditor` — a raw-JSON `<textarea>`
  (`src/components/page-editor/StubPageEditor.vue`).
- Worse: `StubPageEditor` declares `title` and `message` as
  **required** props, but the generic `<component :is>` binding
  (`src/views/PageDesigner.vue:59-66`) never passes them — so a `map` /
  `roadmap` / `search` / `wiki` page today renders a raw textarea with
  an empty heading, no explanation, and a Vue required-prop warning in
  the console.
- `src/components/page-editor/PageListEditor.vue:87-97` — `PAGE_TYPES`
  offers the same nine types in the "Add page" picker, and
  `DEFAULT_CONFIGS` (line 100) has no entry for the four missing types.
  A builder user cannot even *create* a map, roadmap, search or wiki
  page from the designer; the only path is hand-authoring JSON in the
  Raw JSON tab.

So four page types that the runtime renders perfectly well are
effectively invisible in OpenBuild's flagship visual editor. Every
other canonical type has a dedicated form-based sub-editor with
register/schema pickers, inline validation marks and a lossless config
round-trip (REQ-OBPD-003/004/005/006 in
`openspec/specs/openbuild-page-designer/spec.md`); these four deserve
the same.

## What Changes

- Add four new sub-editor components under
  `src/components/page-editor/`, each following the established
  contract (props `config` / `pageType` / `appSlug` / `dataRegisters` /
  `parentRoute`, emit `update:config`, `pageEditorValidationMixin` +
  `validatedConfigKeys` + `InlineFieldMark` marks, `useRegisterPicker`
  for register/schema/schema-property dropdowns, lossless
  clone-and-touch-one-key `update()`):
  - `MapPageEditor.vue` — centre lat/lng + zoom, tile/WMS/WFS/GeoJSON
    layer list, marker source (URL, or the reserved
    `markers.dataSource.{register, schema}` shape with schema-property
    dropdowns for `latField` / `lngField` / `popupField`), clustering
    toggle, height.
  - `RoadmapPageEditor.vue` — `repo`, forge (type + baseUrl),
    `disabled` opt-out, doc/CTA URL overrides; surfaces the
    config > initialState > fallback resolution order
    `CnFeaturesAndRoadmapPage` documents.
  - `SearchPageEditor.vue` — register/schema scope pickers, query
    placeholder + label texts, facet-declaration list builder.
  - `WikiPageEditor.vue` — register/schema pickers (required by the
    canonical enum description), article field mapping (`contentField`,
    `titleField`, `idParam`), sidebar tree config (`sidebarRegister`,
    `sidebarSchema`, `treeField`, `sidebarTitleField`), empty-state
    texts.
- Register all four in `SUB_EDITOR_MAP` + the `components` block of
  `src/views/PageDesigner.vue`; `StubPageEditor` remains the fallback
  **only** for types outside the map (unknown/future types), and the
  fallback binding now passes the stub's required `title`/`message`
  props.
- Extend `PAGE_TYPES` and `DEFAULT_CONFIGS` in
  `src/components/page-editor/PageListEditor.vue` so the four types are
  creatable from the "Add page" picker with type-shaped default
  configs.
- Raw-JSON tab round-trip is preserved: each editor only touches the
  keys it surfaces, so externally-authored keys survive (same lossless
  contract `LogsPageEditor.vue` documents).
- Vitest unit specs for each new component plus updates to
  `PageListEditor.spec.js` / `PageDesigner.spec.js`; one Playwright
  e2e flow per new editor (create page of type X → configure → save →
  render in the builder).
- No backend, route or schema changes — the canonical v2 schema already
  accepts all four types; this is designer-UI-only.

## Capabilities

### Added Capabilities

- `page-editor-coverage`: dedicated sub-editors for the `map`,
  `roadmap`, `search` and `wiki` manifest v2 page types, add-page
  picker coverage, and the narrowed StubPageEditor fallback (delta spec
  at `specs/page-editor-coverage/spec.md`).

### Modified Capabilities

- None as delta specs. `openbuild-page-designer`'s REQ-OBPD-003
  ("nine canonical types") remains accurate for the v1-era enum it
  names; the four v2 types are specified as the new capability above
  rather than rewriting the archived requirement.

## Impact

- Files touched: `src/components/page-editor/MapPageEditor.vue`,
  `RoadmapPageEditor.vue`, `SearchPageEditor.vue`, `WikiPageEditor.vue`
  (new); `src/views/PageDesigner.vue` (imports, `components`,
  `SUB_EDITOR_MAP`, stub prop binding);
  `src/components/page-editor/PageListEditor.vue` (`PAGE_TYPES`,
  `DEFAULT_CONFIGS`); tests under `tests/components/page-editor/` and
  `tests/e2e/spec-coverage/`.
- No new dependencies; all pickers/mixins/fields reused from the
  existing page-editor toolkit.
- Manifest compatibility: purely additive designer UI. Existing
  manifests round-trip unchanged; a hand-authored map/roadmap/search/
  wiki config opens in the new form editors instead of the raw
  textarea, with unknown keys preserved.
