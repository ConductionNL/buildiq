---
retrofit: true
---

# page-designer-ui Specification

## Purpose

The Page Designer is OpenBuild's three-pane visual editor for an Application
version's manifest. The `PageDesigner` view is a controlled component (manifest
prop in, `update:manifest` / `save-and-preview` events out) that orchestrates a
page list, a menu tree, a per-page-type config sub-editor, undo/redo history,
and an inline validation surface. The route-level hosts (`PageDesignerHost`,
`BuilderHost`) resolve a slug + active version, load the manifest from
OpenRegister, and persist edits. Nine sub-editors under
`src/components/page-editor/` each own one `page.type`; reusable field builders
under `.../fields/` edit list-shaped config (columns, actions, form fields,
layout items, widgets, sidebar tabs/sections).

This capability is observed behaviour of the `PageDesigner`,
`PageDesignerHost`, `BuilderHost`, the `page-editor/*` sub-editors, and the
`page-editor/fields/*` builders. It is the frontend half of the
`openbuild-page-designer` backend capability.

## Requirements

### Requirement: Controlled designer orchestrates pages, menu, undo/redo and save

@e2e exclude retrofit component-contract spec — `subEditorFor`, `selectedPage`, `undo`/`redo`/`canUndo`/`canRedo` history, `emitManifest`, `onPagesUpdate`, `canSaveAndPreview`, `onKeydown` are controlled-component contracts verified by Vitest unit tests; the designer route is covered by the openbuild-page-designer Playwright tests

The `PageDesigner` view SHALL expose the manifest's `pages` and `menu` as
computed surfaces, dispatch the centre pane to a sub-editor by page type
(`subEditorFor`, `selectedPage`, `selectPage`), maintain an undo/redo history
(`undo`, `redo`, `canUndo`, `canRedo`) over edits, emit `update:manifest` on
every page/menu/config change (`emitManifest`, `onPagesUpdate`, `onMenuUpdate`,
`onConfigUpdate`), gate and emit the save action (`saveAndPreview`,
`canSaveAndPreview`), and bind keyboard shortcuts (`onKeydown`). The `menu` and
`pages` accessors SHALL derive from the controlled manifest prop.

#### Scenario: Edit a page's config

- **WHEN** the active sub-editor emits a config slice
- **THEN** `PageDesigner` merges it into the manifest and emits `update:manifest`

#### Scenario: Undo a change

- **WHEN** the user triggers undo with available history
- **THEN** the manifest reverts to the previous snapshot and `canRedo` becomes true

### Requirement: Route hosts resolve slug plus version and persist the manifest

@e2e exclude retrofit component-contract spec — `routeSlug`, `resolveVersion`, `versionNotFound`, `manifestOptions`, `placeholderManifest`, `cacheKey`, `onManifestUpdate`, `save` are host-component lifecycle contracts verified by Vitest unit tests; slug+version resolution and 404 path are covered by the openbuild-page-designer Playwright tests

`PageDesignerHost` and `BuilderHost` SHALL resolve the route slug
(`routeSlug`, `slug`, `appId`, `appUuid`, `applicationUuid`) and the active
version from `?_version=` (`resolveVersion`, `versionSlug`), load the
Application + manifest on `created`/`load`, render a version-not-found state on
404 (`versionNotFound`), supply manifest options / placeholder manifest
(`manifestOptions`, `placeholderManifest`, `cacheKey`), receive
`onManifestUpdate`, and persist edits back to OpenRegister with a PUT (`save`).
A builder deep-link URL SHALL be derived (`builderUrl`).

#### Scenario: Unknown version

- **WHEN** the resolved `?_version=` slug is unknown or unauthorised
- **THEN** the host renders the version-not-found state instead of the designer

#### Scenario: Persist edits

- **WHEN** the designer emits an updated manifest
- **THEN** the host PUTs it to OpenRegister and reflects the saved state

### Requirement: Per-page-type config sub-editors emit validated slices

@e2e exclude retrofit component-contract spec — `validatedConfigKeys`, `fetchRegisters`, `fetchSchemas`, `fetchSchemaProperties`, `sidebarShape`, `submitShape`, `sourceShape`, `setSubmitHandler`, `setSubmitEndpoint` are sub-editor emit contracts verified by Vitest unit tests; type-specific sub-editor mounting is covered by the openbuild-page-designer Playwright tests

Each sub-editor SHALL bind its slice of `page.config`, emit an `update`
upward on edit, and expose a validated-key set. The sub-editors are
`IndexPageEditor`, `DetailPageEditor`, `FormPageEditor`,
`FilesPageEditor`, `DashboardPageEditor`, `ChatPageEditor`, `LogsPageEditor`,
`CustomPageEditor`, `SettingsPageEditor`, and `StubPageEditor`. Each editor
SHALL expose a
`validatedConfigKeys` set marking which keys passed validation. Register/schema
backed editors SHALL fetch their option lists (`fetchRegisters`,
`fetchSchemas`, `fetchSchemaProperties`) and the detail/index editors SHALL
manage the sidebar shape (`sidebarShape`, `setSidebarShape`, `updateSidebar`,
`updateSidebarKey`, `onSidebarToggle`, `sidebarEnabled`). Form/log/chat editors
SHALL manage their transport/submit/source shape (`submitShape`, `sourceShape`,
`transportShape`, `setSubmitHandler`, `setSubmitEndpoint`, `setSourceShape`,
`setTransport`). Editors with a setup composable SHALL wire it in `setup`.

#### Scenario: Edit a config field

- **WHEN** the user edits a config field in a sub-editor
- **THEN** the editor emits the updated config slice and recomputes
  `validatedConfigKeys`

#### Scenario: Populate register/schema pickers

- **WHEN** a register/schema-backed sub-editor mounts
- **THEN** it fetches the register, schema, and property option lists

### Requirement: Reusable field builders edit list-shaped config

@e2e exclude retrofit component-contract spec — `addColumn`/`removeColumn`, `addAction`/`removeAction`, `moveUp`/`moveDown`, `onReorder`, `updateField`, `duplicateIds`, `invalidRoutes`, `hasError` are field-builder emit contracts verified by Vitest unit tests; add/reorder/duplicate-id validation is covered by the openbuild-page-designer Playwright tests

Each field builder SHALL expose a local working copy of its list, support
add/remove/reorder of rows, edit per-row fields, and emit the updated list
upward. The field builders are `ColumnBuilder`, `ActionBuilder`,
`FormFieldBuilder`, `LayoutItemBuilder`, `WidgetBuilder`, `SidebarTabBuilder`,
`SidebarSectionBuilder`, and `SettingsSectionBuilder`; the menu/page list
editors are `MenuTreeEditor` and `PageListEditor`. Each builder SHALL hold a
local working copy of its list (`localColumns`, `localActions`, `localFields`,
`localItems`, `localWidgets`, `localTabs`, `localSections`), support
add/remove/reorder of rows (`addColumn`/`removeColumn`,
`addAction`/`removeAction`, `addField`/`removeField`, `addItem`/`removeItem`,
`addWidget`/`removeWidget`, `addTab`/`removeTab`, `addSection`/`removeSection`,
`addChild`/`removeChild`/`addEntry`/`removeEntry`, `moveUp`/`moveDown`,
`onReorder`/`onTopLevelReorder`/`onChildrenReorder`), edit per-row fields
(`updateField`, `updateChildField`, `updateActionField`, `updateTabField`,
`updateColumns`, `updateWidget`, `onKeyChange`, `onLabelInput`, `updateNum`),
and emit the updated list upward (`emit`). Row-render helpers (`rowKey`,
`rowLabel`, `schemaPropertyKeys`, `bodyKind`, `setBodyKind`, `stringifyProps`,
`onPropsInput`, `onWidgetPropsInput`) SHALL shape per-row display. The page-list
editor SHALL validate uniqueness and route patterns (`duplicateIds`,
`invalidRoutes`, `hasError`, `confirmAdd`, `startAdd`, `cancelAdd`).

#### Scenario: Add and reorder a row

- **WHEN** the user adds a row and moves it up or down
- **THEN** the builder updates its local list and emits the reordered list

#### Scenario: Reject duplicate page ids

- **WHEN** the user adds a page whose id duplicates an existing one
- **THEN** the page-list editor flags the error and blocks confirmation

### Requirement: Inline validation surface and config-field registration

@e2e exclude retrofit component-contract spec — `registerConfigField`, `unregisterConfigField`, `configPathPrefix`, `configErrorFor`, `validatorErrors`, `onDepthViolation`, `registryKeys`, `stringifyProps` are provide/inject validator contracts verified by Vitest unit tests; inline validation surface is covered by the openbuild-page-designer Playwright tests

`PageDesigner` SHALL provide a `pageEditorValidator` to descendant sub-editors
(`provide`), let fields register/unregister for validation
(`registerConfigField`, `unregisterConfigField`, `configPathPrefix`,
`configErrorFor`), aggregate validator errors for the right-hand panel
(`validatorErrors`), enforce menu-nesting depth limits (`onDepthViolation`),
and wire its reactive state in `setup`. The `CustomPageEditor` SHALL expose the
customComponents registry keys (`registryKeys`, `otherKeys`,
`validatedConfigKeys`) and stringify free-form props (`stringifyProps`,
`onPropsInput`, `handler`).

#### Scenario: Inline validation mark

- **WHEN** a registered config field fails validation
- **THEN** the field shows its error and it is aggregated into `validatorErrors`

#### Scenario: Depth-limit guard

- **WHEN** a menu edit would exceed the two-level nesting limit
- **THEN** the designer rejects it via `onDepthViolation`
