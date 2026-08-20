## Context

OpenBuild's Page Designer dispatches the centre pane per `page.type`
via a closed `SUB_EDITOR_MAP` (`src/views/PageDesigner.vue:153-163`).
Nine types have dedicated form-based sub-editors; `map`, `roadmap`,
`search` and `wiki` — all shipped by the installed
`@conduction/nextcloud-vue` renderer (`defaultPageTypes` in
`src/components/CnPageRenderer/pageTypes.js`) and all members of the
canonical v2 closed page-type enum
(`src/schemas/app-manifest-v2.schema.json` `$defs.page.properties.type`)
— fall through `subEditorFor()` (`PageDesigner.vue:474`) to
`StubPageEditor.vue`, a raw-JSON textarea. They are also missing from
`PAGE_TYPES` / `DEFAULT_CONFIGS` in `PageListEditor.vue:87-110`, so the
"Add page" picker cannot create them at all.

**Codebase verification performed for this design** (all read at HEAD
before writing this document): `src/views/PageDesigner.vue`;
`src/components/page-editor/PageListEditor.vue`, `LogsPageEditor.vue`
(reference sub-editor contract), `StubPageEditor.vue`;
`src/mixins/pageEditorValidation.js`;
`src/composables/useRegisterPicker.js` (via its LogsPageEditor usage);
`src/views/FeaturesRoadmap.vue` (OpenBuild's own
`CnFeaturesAndRoadmapView` wrapper and its
`features_roadmap_*` loadState keys backed by the
`openregister::features_roadmap_enabled` flag on OpenRegister); and in
the `@conduction/nextcloud-vue` library:
`src/schemas/app-manifest-v2.schema.json` (`$defs.page` — type enum +
`config` property descriptions), `src/components/CnMapPage/CnMapPage.vue`
+ `CnMapWidget/CnMapWidget.vue`,
`CnFeaturesAndRoadmapPage/CnFeaturesAndRoadmapPage.vue`,
`CnSearchPage/CnSearchPage.vue`, `CnWikiPage/CnWikiPage.vue`,
`CnPageRenderer/CnPageRenderer.vue` (`resolvedProps` — `pages[].config`
keys are spread as props onto the mounted page component, merged under
route-param truth).

### Verified per-type config inventory

The renderer spreads `pages[].config` keys as props onto the page
component, so "which config keys does this type consume" equals "which
props does the page component declare". Read from the library at HEAD:

- **map** (`CnMapPage.vue` props + `CnMapWidget.vue` marker contract):
  `center` (`[lat, lng]`, **required**, validated as two finite
  numbers), `zoom` (number, default 7), `layers[]`
  (`{ type: 'tile'|'wms'|'wfs'|'geojson', url, options }`,
  `geojson` may inline `data`), `markers`
  (`{ features?, dataSource?, latField?, lngField?, popupField?,
  clustering?, iconColor?, iconUrl? }` — `dataSource.url` is fetched on
  mount; `dataSource.{register, schema}` is explicitly **RESERVED** in
  `CnMapWidget.vue` and skipped by the current fetch path), `height`.
- **roadmap** (`CnFeaturesAndRoadmapPage.vue` props; each resolves
  manifest `config.<key>` > `loadState('<appId>',
  'features_roadmap_<key>')` > hardcoded fallback): `repo`
  (`owner/repo`), `forge`
  (`{ type: 'codeberg'|'forgejo'|'gitea'|'github', baseUrl? }`),
  `features[]`, `disabled` (admin opt-out — the
  `openregister::features_roadmap_enabled` flag surfaces here via
  initialState), `openbuiltUrl`, `llmSkillsUrl`, `suggestUrl`,
  `documentationUrl`.
- **search** (`CnSearchPage.vue` props): `title`, `query`,
  `placeholder`, `ariaLabel`, `searchLabel`, `facets[]`
  (`{ key, label?, options: [{value, label?, count?}], multiple? }`),
  `activeFacets`, `results[]`, `totalCount`, `loading`, `emptyLabel`,
  `idleLabel`, `loadingLabel`, `facetsTitle`, `clearFacetsLabel`. The
  actual search execution is consumer-wired via `@search` (per the v2
  enum description); `register` / `schema` are generic v2 config
  carry-forward keys the search wiring can scope against.
- **wiki** (documented key-by-key in the v2 schema's
  `$defs.page.properties.config.properties`, each mapping to a
  `CnWikiPage` prop): `register` + `schema` (the enum description says
  wiki "MUST declare config.register + config.schema"), `contentField`
  (default `body`), `titleField` (default `title`), `idParam` (default
  `id`), `treeField` (default `children`), `sidebarTitleField`,
  `sidebarRegister`, `sidebarSchema`, `emptyText`, `emptyDescription`,
  `emptyBodyText`, `emptyBodyDescription`.

## Goals / Non-Goals

**Goals:**

- A dedicated, form-based sub-editor for each of `map`, `roadmap`,
  `search`, `wiki`, following the exact contract the nine existing
  editors share (see Decision 1).
- The four types creatable from the "Add page" picker with sensible
  type-shaped default configs.
- `StubPageEditor` demoted to what its name says: the fallback for
  types with no dedicated editor (unknown/future types only), and
  mounted with its required props actually bound.
- Lossless raw-JSON round-trip for every editor: keys the form does
  not surface survive edits byte-identically.

**Non-Goals:**

- No renderer/library changes. In particular, implementing the
  reserved `markers.dataSource.{register, schema}` fetch path in
  `CnMapWidget` stays a library concern (see Decision 3's trade-off).
- No live search execution wiring for `search` pages (consumer `@search`
  contract, out of designer scope).
- No changes to `useManifestValidator.js` — the canonical v2 schema
  already accepts the four types, so validation flows through
  unchanged.
- No backend/PHP/route changes of any kind.

## Decisions

### Decision 1: Clone the LogsPageEditor contract verbatim for all four editors

Every new editor is a sibling of `LogsPageEditor.vue` and copies its
structure mechanically:

- Props: `config` (Object, default `{}`), `pageType` (String, default =
  own type), `appSlug` (String), `dataRegisters` (Array, default `[]`),
  `parentRoute` (String). Emits: `update:config`.
- `mixins: [pageEditorValidationMixin]` + a `validatedConfigKeys`
  computed listing exactly the top-level config keys the form surfaces;
  templates use `<InlineFieldMark :error="markFor(key)" />` and
  `:aria-invalid="isInvalid(key)"` per field (REQ-OBPD-011 machinery,
  ADR-024 manifest editing conventions).
- Register/schema/schema-property dropdowns via
  `useRegisterPicker({ appSlug, dataRegisters })` in `setup()` —
  identical to LogsPageEditor's `picker` usage, including the
  `config.register` / `config.schema` watchers that refresh dependent
  dropdowns.
- Lossless `update(key, value)`: clone `config`, delete-on-empty, touch
  only the edited key (plus any documented mutually-exclusive partner),
  emit the clone. Unsurfaced keys are never dropped.

Rationale: this change is deliberately mechanical; a uniform contract
keeps each editor reviewable by diffing against LogsPageEditor and lets
the four Vitest specs share the shape of
`tests/components/page-editor/LogsPageEditor.spec.js`.

### Decision 2: Nested one-key sub-objects are edited through flat field groups, not nested JSON

`map.markers` and `roadmap.forge` are single-level nested objects. The
editors surface them as flat labelled field groups
(`markers.latField` → "Latitude field" input) and write back via the
same lossless clone (`update('markers', { ...config.markers,
latField })`). No nested raw-JSON sub-textareas — the Raw JSON tab
(REQ-OBPD-010) already covers arbitrary shapes. `map.layers[]` and
`search.facets[]` get small inline row-list builders (add/remove/edit
rows) local to their editor component, mirroring how
`SettingsSectionBuilder.vue` stays local to settings concerns; no new
shared field component is added under
`src/components/page-editor/fields/` because neither list shape is
reused by a second editor.

### Decision 3: Map marker source offers URL (working) and register+schema (reserved) branches

`CnMapWidget` today only fetches `markers.dataSource.url`;
`dataSource.{register, schema}` is documented as RESERVED and skipped.
The map editor still offers the LogsPageEditor-style one-of radio
("Source URL" / "Register + schema") because the register branch is the
manifest-forward shape and enables schema-property dropdowns for
`latField` / `lngField` / `popupField` (the "geo field selection" the
type exists for). The register branch renders a persistent inline hint
that renderer support for register-bound markers is pending in the
library, so an author who picks it knows the built page will show an
empty marker layer until the lib ships the fetch path. Trade-off:
slightly ahead of the renderer vs. hiding the canonical shape and
forcing raw-JSON authoring later; the hint keeps it honest.

### Decision 4: Roadmap editor treats `features[]` as raw-JSON-only

`CnFeaturesAndRoadmapPage.features` is a build-time feature manifest
(array of `{slug, title, summary, docsUrl}`) that in practice arrives
via initialState (see OpenBuild's own `src/views/FeaturesRoadmap.vue`,
which reads `loadState('openbuild', 'features_roadmap_features', [])`).
The editor surfaces the scalar overrides (`repo`, `forge.type`,
`forge.baseUrl`, `disabled`, `documentationUrl`, `suggestUrl`,
`openbuiltUrl`, `llmSkillsUrl`) as form fields and leaves `features[]`
to the Raw JSON tab, with a hint naming the initialState fallback
chain. Building a full feature-manifest list editor for a key that is
normally server-provided is not worth the surface.

### Decision 5: StubPageEditor keeps existing behaviour, gains its required props, loses its four wrongly-routed types

`subEditorFor()` stays `SUB_EDITOR_MAP[type] || 'StubPageEditor'` —
the map simply gains four entries. The `<component :is>` binding in
`PageDesigner.vue` additionally passes `title` / `message` (i18n
strings built from the unknown `page.type`) so the stub's required
props are satisfied when it does mount. No conditional template
branching per editor: the stub accepts and ignores the shared
sub-editor props it doesn't declare (Vue drops unknown attrs onto the
root element harmlessly), keeping the dispatch site a single generic
`<component>`.

### Decision 6: PAGE_TYPES / DEFAULT_CONFIGS defaults

`PageListEditor.vue` gains the four enum members and these defaults
(kept minimal but valid enough for the sub-editor to open with every
fieldset visible):

- `map`: `{ center: [52.1326, 5.2913], zoom: 7, layers: [], markers: {} }`
  — centre = the Netherlands centroid `CnMapPage`'s own docblock
  example uses; `center` is a required prop so the default must ship
  one.
- `roadmap`: `{}` — every key optional with initialState fallbacks.
- `search`: `{ register: '', schema: '', facets: [] }`.
- `wiki`: `{ register: '', schema: '' }` — the two keys the enum
  description marks as MUST.

## Risks / Trade-offs

- **Reserved map register branch (Decision 3)** — an author can write
  config the current renderer ignores. Mitigated by the persistent
  inline hint; revisit when the lib implements the reserved shape.
- **Search data wiring** — a saved search page renders the query UI
  but returns no results until the consumer wires `@search`; the
  editor states this in a hint. The e2e asserts the page *renders*
  (`[data-testid="cn-search-page"]`), not that results arrive.
- **REQ-OBPD-003 wording drift** — the archived page-designer spec
  says "nine canonical types". This change adds a new capability
  rather than rewriting it; a future consolidation pass can fold the
  two (tracked in the spec-file cross-reference, no task here).
- **Bundle size** — four more statically-imported sub-editors in the
  PageDesigner chunk; consistent with the existing nine, negligible
  against `vuedraggable`/gridstack already in the graph.

## Open Questions

- None blocking. If `CnMapWidget` ships the reserved register fetch
  before this lands, drop the Decision 3 hint text in the same PR.
