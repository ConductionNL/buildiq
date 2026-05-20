# openbuilt-page-designer Specification

## Purpose
TBD - created by archiving change openbuilt-page-editor. Update Purpose after archive.
## Requirements
### Requirement: REQ-OBPD-001 Menu tree editor with two-level nesting

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
  `openbuilt.page-designer.menu.error.nesting-depth`
- **AND** the manifest remains unchanged

#### Scenario: Action field disables route and href

- **WHEN** the user sets `action` on a menu entry to `user-settings`
- **THEN** the editor disables the `route` and `href` input fields
- **AND** displays an inline note explaining the canonical rule
- **AND** clears any pre-existing values from `route` and `href` in the
  manifest output

### Requirement: REQ-OBPD-002 Page list editor with uniqueness and route-pattern validation

The system SHALL provide a `PageListEditor.vue` component that
authors the manifest's `pages[]` array. The editor SHALL support
adding, removing, and drag-reordering pages, and MUST enforce the
following invariants before allowing a save:

- Every `pages[].id` SHALL be unique within the manifest.
- Every `pages[].route` SHALL match the vue-router pattern grammar
  (`/`, `/:param`, `/segment/:param`, `/:catchAll(.*)?`).
- Adding a page SHALL prompt for the page `type` from the canonical
  closed enum (`index` | `detail` | `dashboard` | `logs` | `settings`
  | `chat` | `files` | `form` | `custom`) before any other field is
  shown, so the correct per-type sub-editor mounts immediately.

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

### Requirement: REQ-OBPD-003 Per-page-type config sub-editor for each of the nine canonical types

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

#### Scenario: Switching page type swaps the sub-editor

- **WHEN** the user edits an existing `type: index` page and changes
  its type to `dashboard`
- **THEN** the centre pane unmounts `IndexPageEditor.vue` and mounts
  `DashboardPageEditor.vue`
- **AND** the previous `config` block is replaced by the
  dashboard-shaped default (`{ widgets: [], layout: [] }`)
- **AND** the side-panel validator confirms the new shape against the
  canonical schema

### Requirement: REQ-OBPD-004 Index-page sub-editor: register, schema, columns, actions

`IndexPageEditor.vue` SHALL author the index-type `config` block per
the canonical schema. It SHALL expose:

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

#### Scenario: Column picker offers @self.* metadata fields

- **WHEN** the user opens the column-selector dropdown for an index
  page bound to `register: openbuilt, schema: hello-message`
- **THEN** the dropdown lists each `hello-message` property AND the
  six `@self.*` metadata entries
- **AND** selecting `@self.created` adds the column to `columns[]` in
  string shorthand `"@self.created"`

### Requirement: REQ-OBPD-005 Detail-page sub-editor: sidebar tabs and route param schema

`DetailPageEditor.vue` SHALL author the detail-type `config` block. It
SHALL expose:

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

#### Scenario: Tab list overrides the built-in sidebar tabs

- **WHEN** the user adds three tabs (`overview`, `audit`, `relations`)
  via `SidebarTabBuilder.vue`
- **THEN** the manifest's `config.sidebarProps.tabs` contains exactly
  those three entries in the authored order
- **AND** the validator confirms each tab declares exactly one of
  `widgets[]` OR `component`

### Requirement: REQ-OBPD-006 Form-page sub-editor with exactly-one-of submit handling

`FormPageEditor.vue` SHALL author the form-type `config` block. It
SHALL expose:

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

### Requirement: REQ-OBPD-007 Custom-page sub-editor reads the customComponents registry

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

### Requirement: REQ-OBPD-008 Live-preview pane mounts a sandboxed CnAppRoot when available

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

The sandboxed `CnAppRoot` SHALL:

- Use a unique `appId` of `openbuilt-preview-{slug}` so its state
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
- **AND** an i18n note (`openbuilt.page-designer.preview.unavailable`)
  explains the limitation
- **AND** clicking the button saves the manifest and opens
  `/builder/:slug` in a new tab

### Requirement: REQ-OBPD-009 Save flow PUTs the manifest via OpenRegister REST

The Page Designer's Save action SHALL serialise the in-flight
manifest, validate it via
`@conduction/nextcloud-vue`'s `validateManifest` export, and PUT the
updated `Application` object via OpenRegister's existing REST API at
`/index.php/apps/openregister/api/objects/openbuilt/application/{uuid}`
— the same path the spec #1 textarea editor already uses. The
designer MUST NOT introduce a new openbuilt-side controller for
manifest writes (ADR-022).

#### Scenario: Save persists via OR REST

- **WHEN** the user clicks Save with a valid manifest
- **THEN** the editor sends a PUT to OR's
  `/api/objects/openbuilt/application/{uuid}` endpoint with the full
  Application body and the updated `manifest` field
- **AND** the response is `200`
- **AND** the editor's "dirty" indicator clears

#### Scenario: Save is blocked when validator reports errors

- **WHEN** the validator has at least one open error in the
  side-panel error list
- **THEN** the Save button is disabled
- **AND** clicking the (disabled) button surfaces a tooltip
  enumerating the blocking error count

### Requirement: REQ-OBPD-010 Raw JSON fallback tab preserves the spec-1 textarea

The Application edit view SHALL retain the textarea-based JSON
manifest editor shipped by spec #1 (`bootstrap-openbuilt`) as a
secondary tab labelled "Raw JSON". The Design tab (the new
`PageDesigner.vue`) SHALL be the default tab on view load. The two
tabs SHALL share the same in-flight manifest state, so edits made in
one tab are visible in the other when the user switches tabs without
saving.

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

### Requirement: REQ-OBPD-011 Debounced validator surface decorates editor panes inline

The system SHALL provide `useManifestValidator.js`, a composable
that wraps `validateManifest` from `@conduction/nextcloud-vue` and
re-runs the validator at most every 300ms of editor-state change.
Each validation error SHALL be surfaced **twice**: in the right-hand
error-list side panel (or a collapsible band when the live preview
pane occupies the right column), and as an **inline mark** on the
specific editor field whose JSON path matches the error path. The
composable MUST NOT block the editor on validation — the UI stays
responsive and the validator output catches up asynchronously.

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

