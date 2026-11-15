# buildiq-page-designer Specification

**Status**: in-progress
**OpenSpec changes**: [publish-widgets-to-nc-dashboard](../../changes/publish-widgets-to-nc-dashboard/), [v2-widget-placement-editor](../../changes/v2-widget-placement-editor/)

## Purpose

Ships the visual Page Designer that replaces the textarea-only manifest editor
from `bootstrap-buildiq`, giving citizen developers a structured UI for
authoring `manifest.menu[]` and `manifest.pages[]`. Provides one sub-editor per
canonical page type (`index | detail | dashboard | logs | settings | chat | files |
form | custom`), each authoring only its own `pages[].config` sub-shape per the
`@conduction/nextcloud-vue/src/schemas/app-manifest.schema.json` v1.4.0+ contract.
A two-level menu-tree editor enforces the canonical nesting depth and exactly-one-of
rules; a sandboxed `CnAppRoot` live-preview pane mounts the in-flight manifest
without saving when chain spec #2 (in-memory manifest loader) is detected. The
Raw JSON tab from spec #1 stays as the secondary fallback (shared in-flight
state). Save flows through OR REST — no app-local controller for manifest writes
(ADR-022).

## Requirements

### Requirement: Menu tree editor with two-level nesting

The system SHALL provide a `MenuTreeEditor.vue` component that
authors the manifest's `menu[]` array. The editor SHALL support
drag-reordering of top-level entries and their `children[]`, with a
**maximum nesting depth of two** (a child MUST NOT itself declare
`children[]`). Each entry SHALL surface form fields for `id`,
`label` (bound as an i18n key, not a literal), `icon`, `route`,
`order`, `permission`, `target` (closed enum: `main` | `settings`,
default `main`), `href`, and `action` (closed enum: `user-settings`,
optional). The editor MUST enforce the canonical schema rule that
`action`, when set, makes `route` and `href` ignored, by surfacing a
disabled-with-tooltip state on those fields.

@e2e exclude visual-editor component spec — drag-reorder, third-level nesting block, and `action` field disabling `route`/`href` are MenuTreeEditor.vue component contracts verified by Vitest unit tests; no independent Playwright-testable URL surface for the menu tree editor in isolation

**ID:** REQ-OBPD-001

#### Scenario: Drag-reorder updates the manifest in order

- **WHEN** the editor lists three menu entries and the user drags the
  third entry to the first position
- **THEN** the in-flight manifest's `menu[]` array reflects the new
  ordering with `order` integers re-assigned monotonically (0, 1, 2)
- **AND** the change appears in the next validator pass

#### Scenario: Child nesting blocks third-level adds

- **WHEN** the user attempts to add a child entry inside a
  `menu[].children[].children[]` slot
- **THEN** the editor refuses the action with the i18n message
  `buildiq.page-designer.menu.error.nesting-depth`
- **AND** the manifest remains unchanged

#### Scenario: Action field disables route and href

- **WHEN** the user sets `action` on a menu entry to `user-settings`
- **THEN** the editor disables the `route` and `href` input fields
- **AND** displays an inline note explaining the canonical rule
- **AND** clears any pre-existing values from `route` and `href` in the
  manifest output

### Requirement: Page list editor with uniqueness and route-pattern validation

The system SHALL provide a `PageListEditor.vue` component that
authors the manifest's `pages[]` array. The editor SHALL support
adding, removing, and drag-reordering pages, and MUST enforce the
following invariants before allowing a save:

@e2e exclude visual-editor component spec — duplicate-id inline error marks, disabled Save button on duplication, and page-type-pick-then-sub-editor-mount are PageListEditor.vue component contracts verified by Vitest unit tests; the route to the page designer is covered by the buildiq-runtime Playwright tests

- Every `pages[].id` SHALL be unique within the manifest.
- Every `pages[].route` SHALL match the vue-router pattern grammar
  (`/`, `/:param`, `/segment/:param`, `/:catchAll(.*)?`).
- Adding a page SHALL prompt for the page `type` from the canonical
  closed enum (`index` | `detail` | `dashboard` | `logs` | `settings`
  | `chat` | `files` | `form` | `custom`) before any other field is
  shown, so the correct per-type sub-editor mounts immediately.

**ID:** REQ-OBPD-002

#### Scenario: Duplicate id blocks save with a marked error

- **WHEN** two pages in the list share the same `id` value
- **THEN** both rows display an inline error mark
- **AND** the manifest validator surfaces the duplication in the
  side-panel error list
- **AND** the parent editor's Save button is disabled

#### Scenario: Page-type pick mounts the matching sub-editor

- **WHEN** the user clicks "Add page" and selects `type: form`
- **THEN** the centre pane mounts `FormPageEditor.vue`
- **AND** the new page is appended to `pages[]` with `type: 'form'`
  and a placeholder `id` + `route` the user is prompted to fill in

### Requirement: Per-page-type config sub-editor for each of the nine canonical types

The system SHALL ship one Vue sub-editor component per canonical page
type declared in the
`@conduction/nextcloud-vue/src/schemas/app-manifest.schema.json`
v1.4.0+ page enum: `IndexPageEditor.vue`, `DetailPageEditor.vue`,
`DashboardPageEditor.vue`, `LogsPageEditor.vue`,
`SettingsPageEditor.vue`, `ChatPageEditor.vue`,
`FilesPageEditor.vue`, `FormPageEditor.vue`, `CustomPageEditor.vue`.
Each sub-editor SHALL author **only** the `pages[].config` block
appropriate to its type and MUST NOT emit keys outside that type's
config sub-shape declared in the canonical schema's `pages[].config`
description block. The page-list editor SHALL mount the sub-editor
whose name matches the selected page's `type` field.

@e2e exclude visual-editor component spec — type-swap unmounts `IndexPageEditor` and mounts `DashboardPageEditor`, resets config block, and side-panel validator — are sub-editor component-swap contracts verified by Vitest unit tests; no independent Playwright-testable URL surface for sub-editor type changes in isolation

**ID:** REQ-OBPD-003

#### Scenario: Switching page type swaps the sub-editor

- **WHEN** the user edits an existing `type: index` page and changes
  its type to `dashboard`
- **THEN** the centre pane unmounts `IndexPageEditor.vue` and mounts
  `DashboardPageEditor.vue`
- **AND** the previous `config` block is replaced by the
  dashboard-shaped default (`{ widgets: [], layout: [] }`)
- **AND** the side-panel validator confirms the new shape against the
  canonical schema

### Requirement: Index-page sub-editor: register, schema, columns, actions

`IndexPageEditor.vue` SHALL author the index-type `config` block per
the canonical schema. It SHALL expose:

@e2e exclude visual-editor component spec — `@self.*` metadata column picker, `ActionBuilder.vue` action list, sidebar/cardComponent sub-blocks, and string-shorthand vs typed-column lossless round-trip are IndexPageEditor.vue component contracts verified by Vitest unit tests; no independent Playwright-testable URL surface

- A **register picker** populated from the user's accessible
  OpenRegister registers (via OR REST).
- A **schema picker** filtered to the selected register, also via OR
  REST.
- A **column selector** that lists every property of the selected
  schema, plus the `@self.*` metadata virtual columns (`@self.uuid`,
  `@self.created`, `@self.updated`, `@self.owner`,
  `@self.organisation`, `@self.locked`). Columns SHALL be addable in
  either string shorthand or as a typed `column` object (the editor
  MUST round-trip both forms losslessly).
- An **action declarations** list reusing `ActionBuilder.vue` for
  each `actions[]` entry.
- Optional **sidebar** + **cardComponent** sub-blocks matching the
  canonical schema's index `sidebar` and `cardComponent` shapes.

**ID:** REQ-OBPD-004

#### Scenario: Column picker offers @self.* metadata fields

- **WHEN** the user opens the column-selector dropdown for an index
  page bound to `register: buildiq, schema: hello-message`
- **THEN** the dropdown lists each `hello-message` property AND the
  six `@self.*` metadata entries
- **AND** selecting `@self.created` adds the column to `columns[]` in
  string shorthand `"@self.created"`

### Requirement: Detail-page sub-editor: sidebar tabs and route param schema

`DetailPageEditor.vue` SHALL author the detail-type `config` block. It
SHALL expose:

@e2e exclude visual-editor component spec — `SidebarTabBuilder.vue` tab list overriding built-in sidebar tabs, exactly-one-of `widgets[]`/`component` validator, and route-param-schema auto-derive from route string are DetailPageEditor.vue component contracts verified by Vitest unit tests; no independent Playwright-testable URL surface

- **Register + schema picker** mirroring the index sub-editor.
- A **route-param schema** that auto-derives expected `$route.params`
  from the parent page's `route` string (e.g. `/messages/:id` →
  `{ id: <selected-schema-uuid-property> }`) and surfaces a warning if
  the route has no `:param` segment.
- A **sidebar config** block supporting both the legacy boolean shape
  (`sidebar: true|false`) and the v1.2.0+ object shape
  (`{ enabled, show, columnGroups[], facets, showMetadata, search }`).
- A **sidebarProps.tabs** list reusing `SidebarTabBuilder.vue` for each
  open-enum tab definition (`{ id, label, icon?, widgets?, component?,
  order? }`).

**ID:** REQ-OBPD-005

#### Scenario: Tab list overrides the built-in sidebar tabs

- **WHEN** the user adds three tabs (`overview`, `audit`, `relations`)
  via `SidebarTabBuilder.vue`
- **THEN** the manifest's `config.sidebarProps.tabs` contains exactly
  those three entries in the authored order
- **AND** the validator confirms each tab declares exactly one of
  `widgets[]` OR `component`

### Requirement: Form-page sub-editor with exactly-one-of submit handling

`FormPageEditor.vue` SHALL author the form-type `config` block. It
SHALL expose:

@e2e exclude visual-editor component spec — `submitHandler`/`submitEndpoint` exactly-one-of mutual-clear, `submitMethod` closed-enum validation from raw JSON, `FormFieldBuilder.vue` field list are FormPageEditor.vue component contracts verified by Vitest unit tests; no independent Playwright-testable URL surface

- A **field list** built from `FormFieldBuilder.vue` matching the
  canonical `formField` `$def`, including validation rules
  (`required`, `pattern`, `min`, `max`, `enum`).
- An **exactly-one-of** picker for `submitHandler` (a
  customComponents registry-name string) **OR** `submitEndpoint`
  (a URL string with `:paramName` segments). The editor MUST refuse
  to allow both to be set simultaneously.
- A **submitMethod** picker (closed enum: `POST` | `PUT` | `PATCH`;
  default `POST`).
- A **mode** picker (closed enum: `edit` | `create` | `public`;
  default `public`).
- Optional **submitLabel**, **successMessage**, **initialValue**
  inputs.

**ID:** REQ-OBPD-006

#### Scenario: Setting submitHandler clears submitEndpoint

- **WHEN** the user enters a `submitEndpoint` and then types a value
  into `submitHandler`
- **THEN** the editor clears `submitEndpoint` from the manifest
- **AND** the picker visibly reflects the active branch as
  `submitHandler`
- **AND** the validator passes

#### Scenario: Invalid submitMethod blocks save

- **WHEN** the user attempts (via raw JSON tab) to set
  `submitMethod: 'DELETE'` on a form page
- **THEN** the validator marks the page with an error referencing the
  closed enum
- **AND** the parent editor's Save button is disabled

### Requirement: Custom-page sub-editor reads the customComponents registry

`CustomPageEditor.vue` SHALL surface a **component-name picker**
populated from the consuming app's `customComponents` registry —
specifically, the keys of the `customComponents` prop passed to the
sandboxed `CnAppRoot` mounted by the live-preview pane (REQ-OBPD-008).
When the live-preview pane is unavailable (chain spec #2 not yet
shipped), the picker SHALL accept a free-text string and surface a
warning that the value cannot be validated until preview is enabled.
The sub-editor SHALL also expose a free-form JSON editor for the
`config` sub-shape, because the canonical schema explicitly allows
`type: custom` configs to be "any shape the custom component
expects".

@e2e exclude visual-editor component spec — registry-backed picker from live-preview `customComponents` prop keys, free-text fallback warning when in-memory loader is absent, and free-form JSON config editor are CustomPageEditor.vue component contracts verified by Vitest unit tests; no independent Playwright-testable URL surface

**ID:** REQ-OBPD-007

#### Scenario: Registry-backed picker lists known components

- **WHEN** the live-preview pane is active and the registry exposes
  three custom-component keys
- **THEN** the picker dropdown lists exactly those three keys
- **AND** selecting one writes the chosen key to `pages[].component`
- **AND** the free-form JSON editor opens with an empty `config: {}`

#### Scenario: Free-text fallback when preview is unavailable

- **WHEN** the live-preview pane is unavailable (in-memory manifest
  loader from chain spec #2 not detected)
- **THEN** the picker renders as a free-text input
- **AND** an i18n warning explains the validation-deferral
- **AND** the value writes through to `pages[].component` unchanged

### Requirement: Live-preview pane mounts a sandboxed CnAppRoot when available

The Page Designer SHALL provide an optional right-hand pane that
mounts a **sandboxed** `CnAppRoot` instance configured from the
in-flight (unsaved) manifest, so the user sees their edits render
live without saving. The pane SHALL be considered available **only
when** the in-memory `useAppManifest(appId, manifestObject)`
overload from chain spec #2 (`nextcloud-vue-in-memory-manifest`) is
detected at runtime. When the overload is absent, the pane SHALL
collapse to a "save & reload" affordance that opens
`/builder/:slug` in a new browser tab against the last saved
manifest, with an inline i18n note explaining the limitation.

@e2e exclude mixed spec — sandboxed `CnAppRoot` re-mount on in-flight manifest change (in-memory `useAppManifest` overload from chain spec #2) requires chain spec #2 (`nextcloud-vue-in-memory-manifest`) not yet shipped; fallback "Save & open preview" button is covered by the buildiq-page-designer Playwright tests

**ID:** REQ-OBPD-008

The sandboxed `CnAppRoot` SHALL:

- Use a unique `appId` of `buildiq-preview-{slug}` so its state
  does not collide with the production-mounted virtual app.
- Receive the manifest as an in-memory object (no fetch).
- Re-mount via a `:key` bound to the manifest's content hash, so any
  manifest edit re-renders the preview cleanly.

#### Scenario: Preview pane renders the in-flight manifest

- **WHEN** chain spec #2's in-memory manifest loader is available
- **AND** the user edits a page's `title` field
- **THEN** the right-hand pane re-renders the preview with the new
  title visible
- **AND** no PUT request is sent to OR

#### Scenario: Fallback when in-memory loader is unavailable

- **WHEN** chain spec #2's in-memory manifest loader is NOT detected
- **THEN** the right-hand pane displays a "Save & open preview" button
- **AND** an i18n note (`buildiq.page-designer.preview.unavailable`)
  explains the limitation
- **AND** clicking the button saves the manifest and opens
  `/builder/:slug` in a new tab

### Requirement: Save flow PUTs the manifest via OpenRegister REST

The Page Designer's Save action SHALL serialise the in-flight
manifest, validate it via
`@conduction/nextcloud-vue`'s `validateManifest` export, and PUT the
updated `Application` object via OpenRegister's existing REST API at
`/index.php/apps/openregister/api/objects/buildiq/application/{uuid}`
— the same path the spec #1 textarea editor already uses. The
designer MUST NOT introduce a new buildiq-side controller for
manifest writes (ADR-022).

@e2e exclude mixed spec — Save PUT to OR REST and dirty-indicator clear require editing manifest content and intercepting network requests; the OR REST write contract is verified by Newman; the Save-blocked-by-validator scenario requires injecting a validation error which is a Vitest unit test contract

**ID:** REQ-OBPD-009

#### Scenario: Save persists via OR REST

- **WHEN** the user clicks Save with a valid manifest
- **THEN** the editor sends a PUT to OR's
  `/api/objects/buildiq/application/{uuid}` endpoint with the full
  Application body and the updated `manifest` field
- **AND** the response is `200`
- **AND** the editor's "dirty" indicator clears

#### Scenario: Save is blocked when validator reports errors

- **WHEN** the validator has at least one open error in the
  side-panel error list
- **THEN** the Save button is disabled
- **AND** clicking the (disabled) button surfaces a tooltip
  enumerating the blocking error count

### Requirement: Raw JSON fallback tab preserves the spec-1 textarea

The Application edit view SHALL retain the textarea-based JSON
manifest editor shipped by spec #1 (`bootstrap-buildiq`) as a
secondary tab labelled "Raw JSON". The Design tab (the new
`PageDesigner.vue`) SHALL be the default tab on view load. The two
tabs SHALL share the same in-flight manifest state, so edits made in
one tab are visible in the other when the user switches tabs without
saving.

@e2e exclude mixed spec — tab-switch shared in-flight manifest state and dirty-indicator persistence across tabs are controlled-component state contracts verified by Vitest unit tests; the Design/Raw JSON tab UI is covered by the buildiq-runtime Playwright tests (REQ-OBR-005)

**ID:** REQ-OBPD-010

#### Scenario: Switching tabs preserves unsaved edits

- **WHEN** the user edits a page title in the Design tab and switches
  to the Raw JSON tab without saving
- **THEN** the textarea content reflects the unsaved page title
- **AND** the dirty indicator persists across the tab switch

#### Scenario: Raw JSON edits flow back to the designer

- **WHEN** the user edits the JSON directly in the Raw JSON tab and
  switches back to the Design tab
- **THEN** the designer re-parses the JSON and re-renders the
  relevant panes
- **AND** if the JSON is invalid, the designer surfaces a parse error
  in its side-panel error list and disables the Design tab inputs
  until the JSON is valid again

### Requirement: Debounced validator surface decorates editor panes inline

The system SHALL provide `useManifestValidator.js`, a composable
that wraps `validateManifest` from `@conduction/nextcloud-vue` and
re-runs the validator at most every 300ms of editor-state change.
Each validation error SHALL be surfaced **twice**: in the right-hand
error-list side panel (or a collapsible band when the live preview
pane occupies the right column), and as an **inline mark** on the
specific editor field whose JSON path matches the error path. The
composable MUST NOT block the editor on validation — the UI stays
responsive and the validator output catches up asynchronously.

@e2e exclude visual-editor component spec — JSON-path-to-inline-field-mark mapping (`pages[1].config.columns[0]` → second page row + column-selector highlight), click-to-focus link, and 300ms debounce coalescing are `useManifestValidator.js` composable contracts verified by Vitest unit tests; no independent Playwright-testable URL surface for inline error path injection

**ID:** REQ-OBPD-011

#### Scenario: Error path maps to inline field mark

- **WHEN** the validator reports an error at JSON path
  `pages[1].config.columns[0]`
- **THEN** the page-list editor highlights the second page row
- **AND** the index sub-editor's column-selector first row shows an
  inline error mark
- **AND** the side-panel error list contains the same error with a
  click-to-focus link to the column row

#### Scenario: Validator debounce coalesces rapid edits

- **WHEN** the user types eight characters into a field within 200ms
- **THEN** the validator runs at most once during that window
- **AND** the editor remains responsive (no input lag attributable to
  validation)

### Requirement: Author v2 widget placements from the page designer

The page designer SHALL let an author create, edit and delete entries in the selected
page's `widgets[]` array, the uniform v2 `widgetEntry` shape, without editing raw JSON.

For the selected page the designer SHALL show every placement in `widgets[]` grouped by
its `slot`, and SHALL offer, per placement, an edit action and a delete action, and per
slot an add action. A page whose `widgets[]` is empty or absent SHALL show an empty state
that invites the author to add a placement, not an error.

Creating a placement SHALL produce an entry carrying all six required fields
(`widgetKey`, `slot`, `gridX`, `gridY`, `gridWidth`, `gridHeight`), so a newly added
placement is valid against the canonical manifest schema the moment it is written.

Deleting a placement SHALL remove exactly that entry and SHALL leave every other entry in
`widgets[]` unchanged.

#### Scenario: Adding a placement to an empty page

@e2e tests/e2e/widget-placement-editor.spec.ts::adds a v2 placement to an empty page

- **WHEN** an author opens a page with no `widgets[]` and adds a widget through the
  placement editor
- **THEN** the page's `widgets[]` contains one entry
- **AND** that entry carries `widgetKey`, `slot`, `gridX`, `gridY`, `gridWidth` and
  `gridHeight`
- **AND** saving the page stores it

#### Scenario: Editing an existing placement

@e2e tests/e2e/widget-placement-editor.spec.ts::edits an existing v2 placement

- **WHEN** an author edits a placement produced earlier by a template, a saved block or the
  copilot
- **THEN** the editor opens pre-filled with that placement's current type and settings
- **AND** confirming the edit updates that entry in place
- **AND** the placement keeps the position it had in `widgets[]`

#### Scenario: Deleting a placement leaves its neighbours alone

@e2e tests/e2e/widget-placement-editor.spec.ts::deletes one placement and leaves the rest

- **WHEN** an author deletes one placement from a page carrying several
- **THEN** only that entry is removed from `widgets[]`
- **AND** every remaining entry keeps its own geometry and settings

### Requirement: Drag and resize authoring for the body slot

For the `body` slot the designer SHALL offer direct manipulation: an author drags a
placement to move it and drags its edge to resize it, and the resulting geometry is written
back to `gridX`, `gridY`, `gridWidth` and `gridHeight` on that entry.

A geometry change made this way SHALL update the working manifest immediately and SHALL
mark the designer dirty, exactly as any other page edit does. It SHALL NOT save by itself.

#### Scenario: Dragging a widget updates its grid position

@e2e exclude drag geometry is a component contract, asserted by a vitest spec that feeds the grid's layout-change payload into the editor and diffs the resulting widgets array, because pointer-accurate GridStack drags are not reliably reproducible in Playwright

- **WHEN** an author drags a body-slot placement to a new column and row
- **THEN** that entry's `gridX` and `gridY` match the new position
- **AND** the designer reports unsaved changes

#### Scenario: Resizing a widget updates its span

@e2e exclude drag geometry is a component contract, asserted by the same vitest spec as the drag scenario, for the same reason

- **WHEN** an author resizes a body-slot placement
- **THEN** that entry's `gridWidth` and `gridHeight` match the new span

### Requirement: Placements outside the body slot are authored through position fields

The designer SHALL offer an authoring path for placements in the `sidebar`,
`header-actions`, `footer`, `modal`, `tab:<id>` and `section:<id>` slots that does not
depend on dragging, because direct manipulation is available for the `body` slot only.

That path SHALL expose the placement's slot and its position and span as explicit numeric
fields, and SHALL write the same `widgetEntry` shape the body slot produces. Changing a
placement's slot SHALL be possible from this path, and SHALL re-apply the target slot's
geometry rules to the placement before it is stored.

#### Scenario: Positioning a sidebar placement without dragging

@e2e tests/e2e/widget-placement-editor.spec.ts::positions a sidebar placement through numeric fields

- **WHEN** an author adds a placement to the `sidebar` slot and sets its row and height
- **THEN** the stored entry carries `slot: "sidebar"` with the values entered
- **AND** no drag interaction was required

#### Scenario: Moving a placement to another slot re-applies that slot's rules

@e2e tests/e2e/widget-placement-editor.spec.ts::moves a placement to another slot

- **WHEN** an author moves a body-slot placement into the `header-actions` slot
- **THEN** the stored entry carries `slot: "header-actions"`
- **AND** its geometry satisfies that slot's rules before the page is saved

### Requirement: The editor cannot produce a placement that fails manifest validation

The designer SHALL enforce, while the author is editing, the placement rules that the
manifest schema cannot express as schema and that are otherwise only discovered when a save
is rejected.

The system SHALL keep `gridX + gridWidth` within the slot's resolved column count, which is
the page's `config.slotColumns` value for that slot when one is declared and the slot's
default otherwise, so the post-schema check on the placement can never fail.

The system SHALL keep `gridY` at 0 for every placement in the `header-actions` slot, and
SHALL keep `gridWidth` at 1 for every placement in the `sidebar` slot.

Where a value would break one of these rules, the editor SHALL constrain the value rather
than store it and report a failure later.

One page-level rule cannot be enforced by constraining a value, because it is a property of
the page rather than of the placement: a page of type `dashboard` carrying exactly one
custom widget that fills the body grid is rejected as a custom page in disguise. The
editor SHALL surface that state while the author is in it, and SHALL name the two
documented ways out, declaring the page as `custom` or adding a second widget, rather than
leaving the author to meet the rule as a rejected save.

#### Scenario: A placement cannot be pushed past the slot's last column

@e2e exclude geometry clamping is a pure function, asserted by a vitest spec over the slot-geometry helper across the default column count and a widened config.slotColumns, which covers more cases than a browser run can

- **WHEN** an author moves or widens a placement so that `gridX + gridWidth` would exceed
  the slot's resolved column count
- **THEN** the stored geometry stays within that count
- **AND** the manifest validates

#### Scenario: A widened slot allows a wider placement

@e2e exclude same pure-function vitest spec as above, parameterised on a page declaring config.slotColumns

- **WHEN** a page declares a larger `slotColumns` value for a slot
- **THEN** the editor allows a placement in that slot to span up to the declared count

#### Scenario: Header actions stay on the first row

@e2e tests/e2e/widget-placement-editor.spec.ts::keeps a header-actions placement on row zero

- **WHEN** an author places a widget in the `header-actions` slot
- **THEN** the stored entry has `gridY` equal to 0
- **AND** the editor does not offer a row control for that slot

#### Scenario: A lone full-width custom widget on a dashboard page is flagged where it happens

@e2e tests/e2e/widget-placement-editor.spec.ts::flags a lone full-width custom widget on a dashboard page

- **WHEN** an author adds a single custom widget filling the body grid to a page of type
  `dashboard` and adds nothing else
- **THEN** the editor says the page is a custom page in disguise
- **AND** it names both ways out, declaring the page as `custom` or adding a second widget

### Requirement: A custom page collects the placement note the ratchet requires

When the selected page has `type: "custom"`, the designer SHALL require a note on every
placement it writes, and SHALL store it as the placement's `_note`. The note documents why
a standard page type was not feasible.

The editor SHALL block the placement from being written while the note is empty, and SHALL
say what the note is for, so the author learns the reason at the point of writing rather
than from a rejected save.

On a page of any other type the note SHALL be optional, and an existing `_note` SHALL be
preserved.

#### Scenario: Adding a placement to a custom page requires a note

@e2e tests/e2e/widget-placement-editor.spec.ts::requires a note on a custom page placement

- **WHEN** an author adds a placement to a page of type `custom` and leaves the note empty
- **THEN** the placement cannot be confirmed
- **AND** the editor explains that a custom page must document why a standard page type was
  not feasible

#### Scenario: A non-custom page does not demand a note

@e2e tests/e2e/widget-placement-editor.spec.ts::adds a placement to a dashboard page without a note

- **WHEN** an author adds a placement to a page of type `dashboard`
- **THEN** the placement can be confirmed with no note
- **AND** no `_note` key is written onto the entry

### Requirement: Placement ids are minted once and unique within a page

Every placement the designer creates SHALL be given a kebab-case `id` matching the
manifest's id pattern, unique within that page's `widgets[]`.

The id SHALL be minted by the same generator the block insert path already uses, so one
page cannot end up with two id conventions. An `id` already present on a placement SHALL
NOT be regenerated by an edit, because a stored delta override keys `widgets[]` by id and a
changed id silently stops matching.

#### Scenario: Two placements of the same widget type do not collide

@e2e tests/e2e/widget-placement-editor.spec.ts::mints unique ids for two placements of one type

- **WHEN** an author adds the same widget type to a page twice
- **THEN** both entries carry an `id`
- **AND** the two ids differ

#### Scenario: Editing a placement keeps its id

@e2e tests/e2e/widget-placement-editor.spec.ts::keeps the id when a placement is edited

- **WHEN** an author edits a placement that already carries an `id`
- **THEN** the stored entry carries the same `id` as before

### Requirement: Saving a page preserves keys the placement editor does not surface

Saving a page edited through the placement editor SHALL preserve every key on every
placement and everywhere else in the page that the editor does not itself surface, so a
manifest written by a template, the copilot or a later library version round-trips
unchanged through an unrelated edit.

The editor SHALL NOT rebuild a placement from a fixed list of known keys, and SHALL NOT
write an empty value in place of a key that was absent.

#### Scenario: An unknown placement key survives an unrelated edit

@e2e exclude lossless round-trip is asserted by the existing manifest round-trip vitest suite, extended with a placement carrying keys the editor does not surface, which validates against the installed schema rather than a browser rendering

- **WHEN** a placement carries keys the editor does not surface and an author changes that
  placement's position
- **THEN** the saved entry still carries those keys with their original values

#### Scenario: Opening and saving a page changes nothing by itself

@e2e tests/e2e/widget-placement-editor.spec.ts::opens and saves a page without changing the manifest

- **WHEN** an author opens a page in the placement editor and saves it without editing
  anything
- **THEN** the saved manifest is byte-for-byte the manifest that was loaded

### Requirement: The type picker offers the authoring catalogue for the page's surface

The designer SHALL offer the full catalogue of widget types an administrator may place for
everybody, not the narrower set a user may add to their own dashboard. The designer is an
authoring surface: the author has the register, the schema and the data source in front of
them, which is exactly what the narrower set exists to avoid asking a user for.

The catalogue SHALL be filtered by the surface the selected page represents, so a type
declared for detail pages only is not offered on a dashboard page and the reverse.

#### Scenario: A detail-only type is not offered on a dashboard page

@e2e tests/e2e/widget-placement-editor.spec.ts::hides detail-only widget types on a dashboard page

- **WHEN** an author opens the type picker on a page of type `dashboard`
- **THEN** a type declared for detail pages only is absent from the list

#### Scenario: An administrator sees types a user picking for themselves would not

@e2e tests/e2e/widget-placement-editor.spec.ts::offers the full authoring catalogue

- **WHEN** an author opens the type picker in the page designer
- **THEN** the list includes types that require configuration only an administrator can
  supply
