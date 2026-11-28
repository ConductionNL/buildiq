# page-editor-coverage Specification

## Purpose
TBD - created by archiving change page-editor-coverage. Update Purpose after archive.
## Requirements
### Requirement: Sub-editor dispatch covers every renderer-shipped v2 page type

`SUB_EDITOR_MAP` in `src/views/PageDesigner.vue` SHALL map all thirteen
members of the canonical manifest v2 page-type enum
(`@conduction/nextcloud-vue/src/schemas/app-manifest-v2.schema.json`,
`$defs.page.properties.type`) that ship a renderer component or a
dedicated editor, adding `map` → `MapPageEditor`, `roadmap` →
`RoadmapPageEditor`, `search` → `SearchPageEditor` and `wiki` →
`WikiPageEditor` to the nine existing entries. `StubPageEditor` SHALL
remain the `subEditorFor()` fallback **only** for page types absent
from `SUB_EDITOR_MAP` (unknown/future types), and when it mounts the
Page Designer SHALL bind its required `title` and `message` props
(i18n strings naming the unrecognised type) — today the generic
`<component :is>` binding omits both, rendering an empty heading plus
a Vue required-prop warning.

@e2e exclude visual-editor component spec — `subEditorFor()` dispatch
table completeness and the stub's required-prop binding are
PageDesigner.vue component contracts verified by Vitest unit tests; the
per-type end-to-end flows are covered under REQ-PEC-003..006 by
`tests/e2e/spec-coverage/page-editor-coverage.spec.ts`

**ID:** REQ-PEC-001

#### Scenario: Selecting a wiki page mounts the wiki sub-editor, not the stub

- **WHEN** the user selects a `type: wiki` page in the page list
- **THEN** the centre pane mounts `WikiPageEditor.vue`
- **AND** no raw-JSON `StubPageEditor` textarea is rendered

#### Scenario: Unknown page type still falls back to the stub with its props bound

- **WHEN** the selected page carries a `type` value outside
  `SUB_EDITOR_MAP` (e.g. a future `"timeline"` type)
- **THEN** the centre pane mounts `StubPageEditor.vue`
- **AND** the stub renders a non-empty heading and message naming the
  unrecognised type
- **AND** the raw-JSON textarea round-trips the page's `config` block
  losslessly

### Requirement: Add-page picker offers the four new types with type-shaped defaults

`PAGE_TYPES` in `src/components/page-editor/PageListEditor.vue` SHALL
include `map`, `roadmap`, `search` and `wiki`, and `DEFAULT_CONFIGS`
SHALL seed each with a type-shaped default config block:
`map` → `{ center: [52.1326, 5.2913], zoom: 7, layers: [], markers: {} }`
(`center` is a required `CnMapPage` prop, so the default MUST ship
one); `roadmap` → `{}`; `search` → `{ register: '', schema: '',
facets: [] }`; `wiki` → `{ register: '', schema: '' }` (the canonical
enum description requires wiki configs to declare both). The existing
add flow (type pick first, id `<type>-page-<n>`, route `/<type>`)
SHALL apply to the new types unchanged.

**ID:** REQ-PEC-002

#### Scenario: Add page lists the four new types

- **WHEN** the user clicks "Add page" in the Page Designer's page list
- **THEN** the page-type select offers `map`, `roadmap`, `search` and
  `wiki` alongside the nine existing types

#### Scenario: Adding a map page seeds the map-shaped default config

- **WHEN** the user adds a page and picks type `map`
- **THEN** the new page's `config` equals
  `{ center: [52.1326, 5.2913], zoom: 7, layers: [], markers: {} }`
- **AND** the centre pane opens `MapPageEditor.vue` with the centre and
  zoom fields pre-filled from that default

### Requirement: Map-page sub-editor: viewport, layers and marker source

`MapPageEditor.vue` SHALL author the `type: "map"` config surface
verified against `CnMapPage.vue` / `CnMapWidget.vue`: numeric centre
latitude + longitude inputs writing `center` as a two-number array, a
`zoom` number input, a `height` text input, a `layers[]` row-list
builder (per row: `type` select from the closed set
`tile | wms | wfs | geojson`, `url` text input, raw `options` left to
the Raw JSON tab), and a marker fieldset writing the `markers` object:
a one-of radio between "Source URL" (writes
`markers.dataSource.url`) and "Register + schema" (writes the
canonical-but-reserved `markers.dataSource.{register, schema}` shape
via the shared `useRegisterPicker` dropdowns, with a persistent inline
hint that renderer support for register-bound markers is pending in
the library), plus `latField` / `lngField` / `popupField` inputs
(schema-property dropdowns when a register + schema are bound,
free-text otherwise) and a `clustering` checkbox. All surfaced keys
SHALL carry `InlineFieldMark` validation marks and register through
`validatedConfigKeys`.

**ID:** REQ-PEC-003

#### Scenario: Create, configure, save and render a map page

- **WHEN** the user adds a `map` page in the Page Designer, sets a
  centre and zoom, adds a `tile` layer with a URL, and sets a marker
  source URL
- **AND** saves the manifest
- **THEN** the save PUT succeeds and navigating to the page's route in
  the built app renders the map page surface
  (`[data-testid="cn-map-page"]`)
- **AND** reopening the page in the Page Designer shows the same
  centre, zoom, layer row and marker source values

### Requirement: Roadmap-page sub-editor: forge, repo and override URLs

`RoadmapPageEditor.vue` SHALL author the `type: "roadmap"` config
surface verified against `CnFeaturesAndRoadmapPage.vue`: a `repo` text
input (`owner/repo`), a forge fieldset writing `forge` as
`{ type, baseUrl? }` with `type` a select over the closed set
`codeberg | forgejo | gitea | github` and an optional `baseUrl` text
input, a `disabled` checkbox (admin opt-out mirroring the
`openregister::features_roadmap_enabled` flag surface), and text
inputs for `documentationUrl`, `suggestUrl`, `openbuiltUrl` and
`llmSkillsUrl`. The editor SHALL display a hint that every key
resolves manifest config > `features_roadmap_<key>` initialState >
built-in fallback, and that the `features[]` array (normally
server-provided) is edited via the Raw JSON tab. `features[]` SHALL
survive form edits untouched (lossless round-trip).

**ID:** REQ-PEC-004

#### Scenario: Create, configure, save and render a roadmap page

- **WHEN** the user adds a `roadmap` page in the Page Designer, sets
  `repo` to an `owner/repo` value and picks a forge type
- **AND** saves the manifest
- **THEN** the save PUT succeeds and navigating to the page's route in
  the built app renders the features-and-roadmap surface
  (`.cn-features-and-roadmap-view`)
- **AND** reopening the page in the Page Designer shows the same repo
  and forge values

### Requirement: Search-page sub-editor: scope, texts and facet declarations

`SearchPageEditor.vue` SHALL author the `type: "search"` config
surface verified against `CnSearchPage.vue`: register and schema scope
dropdowns via the shared `useRegisterPicker` (writing the generic
`register` / `schema` config keys the consumer-side `@search` wiring
scopes against), text inputs for `title`, `placeholder`,
`searchLabel`, `idleLabel` and `emptyLabel`, and a `facets[]` row-list
builder (per row: `key` text input, optional `label`, `multiple`
checkbox, and an options row-list of `{ value, label? }` pairs). The
editor SHALL display a hint that query execution is wired by the
consuming app via the page's `@search` contract, so a freshly built
page renders the search UI without live results. All surfaced keys
SHALL carry `InlineFieldMark` marks and register through
`validatedConfigKeys`.

**ID:** REQ-PEC-005

#### Scenario: Create, configure, save and render a search page

- **WHEN** the user adds a `search` page in the Page Designer, sets a
  placeholder text and adds one facet with two options
- **AND** saves the manifest
- **THEN** the save PUT succeeds and navigating to the page's route in
  the built app renders the search surface
  (`[data-testid="cn-search-page"]`) with the configured placeholder
  and the facet sidebar listing both options
- **AND** reopening the page in the Page Designer shows the same
  placeholder and facet rows

### Requirement: Wiki-page sub-editor: article binding, field mapping and sidebar tree

`WikiPageEditor.vue` SHALL author the `type: "wiki"` config surface
documented key-by-key in the canonical v2 schema's
`$defs.page.properties.config`: **required** `register` + `schema`
dropdowns via the shared `useRegisterPicker` (the enum description
mandates both for wiki pages — the editor SHALL mark them invalid when
empty), article field-mapping dropdowns backed by the bound schema's
properties for `contentField` (default `body`), `titleField` (default
`title`) and a free-text `idParam` (default `id`), a sidebar fieldset
writing `sidebarRegister` / `sidebarSchema` (picker dropdowns) plus
`treeField` and `sidebarTitleField` (schema-property dropdowns), and
empty-state text inputs for `emptyText`, `emptyDescription`,
`emptyBodyText` and `emptyBodyDescription`. Defaults SHALL be shown as
placeholder text, not written into the config, so an untouched field
emits no key (lossless minimal config).

**ID:** REQ-PEC-006

#### Scenario: Create, configure, save and render a wiki page

- **WHEN** the user adds a `wiki` page in the Page Designer, binds a
  register and schema, and sets `contentField` and `titleField`
- **AND** saves the manifest
- **THEN** the save PUT succeeds and navigating to the page's route in
  the built app renders the wiki surface
  (`[data-testid="cn-wiki-page"]`)
- **AND** reopening the page in the Page Designer shows the same
  register, schema and field-mapping values

### Requirement: Shared sub-editor contract: validation marks, pickers and lossless round-trip

Each of the four new sub-editors SHALL follow the established
sub-editor contract verbatim: props `config` / `pageType` / `appSlug`
/ `dataRegisters` / `parentRoute`, a single `update:config` emit,
`pageEditorValidationMixin` with a `validatedConfigKeys` computed
listing exactly the top-level config keys the form surfaces (wired to
`InlineFieldMark` via `markFor()` / `isInvalid()` — the REQ-OBPD-011
inline-mark machinery), register/schema/schema-property dropdowns via
`useRegisterPicker({ appSlug, dataRegisters })`, and a lossless
`update(key, value)` that clones `config`, deletes empty values,
touches only the edited key (plus any documented mutually-exclusive
partner), and never drops keys the form does not surface. Editing a
page in the form view and switching to the Raw JSON tab (and back)
SHALL show the identical config object.

@e2e exclude visual-editor component spec — prop/emit contract,
`validatedConfigKeys` registration, `useRegisterPicker` wiring,
one-of mutual-clear and unsurfaced-key preservation are component
contracts of the four new editors verified by Vitest unit tests
(`tests/components/page-editor/{Map,Roadmap,Search,Wiki}PageEditor.spec.js`);
no independent Playwright-testable URL surface beyond the REQ-PEC-003..006
flows

**ID:** REQ-PEC-007

#### Scenario: Unsurfaced config keys survive a form edit

- **WHEN** a `map` page's config carries an externally-authored key the
  editor does not surface (e.g. `attributionPosition: "bottomleft"`)
- **AND** the user changes the zoom field
- **THEN** the emitted config contains the new `zoom` value
- **AND** `attributionPosition` is preserved byte-identically

#### Scenario: Map marker-source branches are mutually exclusive

- **WHEN** the user has set a marker source URL on a `map` page and
  switches the marker-source radio to "Register + schema" and binds
  both
- **THEN** the emitted config's `markers.dataSource` contains
  `register` and `schema` and no `url` key
- **AND** the reserved-shape hint is visible

#### Scenario: Wiki register and schema are marked invalid when empty

- **WHEN** a `wiki` page's config has no `register` or `schema` value
- **THEN** both dropdowns render an `InlineFieldMark` error state with
  `aria-invalid` set

#### Scenario: Wiki field mappings offer schema properties once bound

- **WHEN** the user binds a register and schema on a `wiki` page and
  the schema declares properties `body`, `title` and `children`
- **THEN** the `contentField`, `titleField`, `treeField` and
  `sidebarTitleField` dropdowns list those schema properties
- **AND** leaving them untouched writes no field-mapping keys into the
  config

### Requirement: An index page can offer a calendar view (REQ-PEC-008)

The index page editor SHALL let the maker choose the view modes the page offers (`config.viewModes`, with `table` always included) and, when `calendar` is chosen, the date field (`config.calendar.dateField`, required), an end date field (`endDateField`) and a title field (`titleField`) from the page's schema. A calendar without a date field, or naming a property the schema lacks, SHALL be a designer finding.

**ID:** REQ-PEC-008

#### Scenario: Inspections on a calendar
- **GIVEN** an index page on schema Inspectie with a date property `inspectiedatum`
- **WHEN** the maker ticks Calendar and picks `inspectiedatum` as the date field
- **THEN** the page carries `viewModes: ["table", "calendar"]` and `calendar.dateField: "inspectiedatum"`
- **AND** in the built app the index page offers a Calendar view showing each inspection on its date

#### Scenario: Calendar without a date field
- **WHEN** the maker ticks Calendar and picks no date field
- **THEN** the designer lists a finding that the calendar needs a date field

### Requirement: An index page can let users order rows by dragging (REQ-PEC-009)

The index page editor SHALL have a switch that writes `manualOrder: true` on the page. Switched off, the key SHALL be removed. The order itself belongs to each user and SHALL NOT be stored by buildiq.

**ID:** REQ-PEC-009

#### Scenario: A personal order
- **GIVEN** the maker switches on drag ordering for the Taken page
- **WHEN** a user of the built app drags a task to the top and reloads
- **THEN** that user still sees the task at the top, and another user sees the default order

### Requirement: An index page can offer a board or a date axis view (REQ-PEC-010)

The index page editor SHALL offer `board` and `dateAxis` next to the other view modes in `config.viewModes`. With board chosen, the maker SHALL pick the status field (`config.board.statusField`, required) and may pick a swimlane field (`swimlaneField`). With date axis chosen, the maker SHALL pick a start and an end date field (`config.dateAxis.startField` and `endField`, both required) and may pick a lane field (`laneField`). A missing required field, or a field the schema lacks, SHALL be a designer finding. The board and the date axis themselves are drawn by the nextcloud-vue index page. On a board, a dropped card SHALL save the column's value into the record's status field through OpenRegister, so the schema's guards decide, and a refusal SHALL return the card with OpenRegister's reason.

**ID:** REQ-PEC-010

#### Scenario: Cases on a board by status
- **GIVEN** an index page on schema Zaak with a `status` field that lists its states
- **WHEN** the maker ticks Board and picks `status` as the status field
- **THEN** the page carries `viewModes: ["table", "board"]` and `board.statusField: "status"`
- **AND** in the built app a user switches the list to Board, sees one column per state and moves a case by dragging its card

#### Scenario: Hearings on a date axis
- **GIVEN** an index page on schema Zitting with the date fields `begin` and `einde` and a user field `behandelaar`
- **WHEN** the maker ticks Date axis, picks `begin` and `einde` and picks `behandelaar` as the lane
- **THEN** the page carries `dateAxis` with `startField: "begin"`, `endField: "einde"` and `laneField: "behandelaar"`
- **AND** in the built app each hearing shows as a bar in its handler's lane

#### Scenario: A board without a status field
- **WHEN** the maker ticks Board and picks no status field
- **THEN** the designer lists a finding that the board needs a status field
