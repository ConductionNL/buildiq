---
kind: code
---

# v2-widget-placement-editor

## Why

The page designer cannot author the shape the runtime actually renders. Buildiq's
manifest v2 uses one uniform placement shape, the `widgetEntry` in a page's `widgets[]`
array, which replaced the v1 `widgetDef` plus `layoutItem` pair. The designer never caught
up: `src/components/page-editor/fields/WidgetBuilder.vue` still authors the v1 `widgetDef`
(`id` / `title` / `type`) through `DashboardPageEditor.vue`'s `config.widgets` and
`config.layout`, while `widgets[]` is only ever read. `WidgetSelectionPanel.vue` lists it
with checkboxes for "save as block" and `PageDesigner.vue` passes it to `BlockLibraryPanel`
as `targetWidgets`. Nothing hand-authors a v2 placement.

So every v2 placement in the fleet arrives from somewhere else: a template, a saved block
inserted through `src/services/blockInsert.js`, or the copilot. An author who wants to move
a widget one column to the left, change its span, put it in the sidebar or delete it has to
edit raw JSON. That is the gap.

The work is smaller than it looks, and that is the point of doing it now.
`@conduction/nextcloud-vue` already ships both halves: `CnWidgetGrid` has an `editable`
prop that renders a GridStack drag and resize grid and emits `layout-change` with the
updated entries, and `CnAddWidgetModal` already edits an existing placement through its
`editingWidget` prop, against a type catalogue the library exports as
`listWidgetTypes(surface)`. This change mounts what exists and persists what comes back.

## What Changes

- **A v2 placement editor in the page designer.** A new widget placement panel replaces
  the read-only widget listing in the designer's centre pane for the selected page. It
  lists every entry in that page's `widgets[]`, and can add, edit, reorder, reposition and
  delete them.
- **Two authoring paths, one shape.** The `body` slot gets a drag and resize canvas, by
  mounting the library's `CnWidgetGrid` with `editable` and persisting its `layout-change`
  payload. Every other slot (`sidebar`, `header-actions`, `footer`, `modal`, `tab:<id>`,
  `section:<id>`) gets a list editor with explicit position and span fields, because the
  library gates its drag grid on the `body` slot alone. Both paths write the same
  `widgetEntry` and share one validation path.
- **Add and edit go through the library modal.** `CnAddWidgetModal` supplies the type
  picker, the per-type sub-form and the appearance fields. The designer passes
  `surface` derived from the page type and leaves `userAddableOnly` off, because the
  designer is an administrator authoring for everybody, not a user picking for themselves.
- **The editor cannot produce an invalid placement.** The rules the schema cannot state as
  schema are enforced in the editor rather than discovered at save: `gridX + gridWidth`
  never exceeds the slot's resolved column count, `gridY` is pinned to 0 in
  `header-actions`, `gridWidth` is pinned to 1 in `sidebar`, and `_note` is collected as a
  required field on a page of type `custom`.
- **Ids come from the existing generator.** A placement the editor creates gets a
  kebab-case `id` minted by `blockInsert.js`'s `mintWidgetId`, collision-checked against
  the page's current `widgets[]`. No second id generator is introduced.
- **Saving stays lossless.** Keys on a placement that the editor does not surface, and
  keys elsewhere in the page config, survive a save untouched, following the discipline
  `DashboardPageEditor.vue`'s `update()` already documents.
- **The v1 editor is not removed.** `WidgetBuilder.vue` keeps authoring `config.widgets`
  for pages that still carry the v1 shape. Migrating v1 pages to v2 is a separate change.
- **BREAKING for nobody.** A page whose `widgets[]` is empty or absent behaves exactly as
  today.

### Where this sits in the chain

`publish-widgets-to-nc-dashboard` names this change in its `depends_on` and explains the
motivation from the other end: its decision D8 records that a promote toggle hung off the
v1 editor would attach `ncDashboard` to the wrong `$def` and promote widgets the v2 runtime
does not render. That change adds the `ncDashboard` property and the toggle. This change
adds neither, and only has to leave room for both.

## Capabilities

### New Capabilities

<!-- None. This change extends an existing authoring surface. -->

### Modified Capabilities

- `openbuild-page-designer`: the designer gains requirements for authoring v2
  `widgets[]` placements directly: adding, editing, repositioning, reordering and deleting
  a `widgetEntry`, the per-slot geometry rules it must enforce, the `_note` rule for custom
  pages, id minting, and the lossless save contract for keys it does not surface.

## Impact

**Code, this repo**

- New: a widget placement panel component and its slot-aware geometry helper under
  `src/components/page-editor/`, plus their vitest specs.
- Modified: `src/views/PageDesigner.vue` (a whole-array `widgets` write path beside the
  existing `onPagesUpdate` and `onInsertWidgets`), `src/components/page-editor/WidgetSelectionPanel.vue`
  (it keeps "save as block" and hands the listing to the new panel).
- Reused, not modified: `src/services/blockInsert.js`'s `mintWidgetId`.

**Dependencies**

- No new npm dependency. `CnWidgetGrid`, `CnAddWidgetModal` and the
  `dashboardWidgetRegistry` API are already exported by the installed
  `@conduction/nextcloud-vue`. `gridstack` is already a direct dependency and its CSS is
  already imported by the entry that hosts the designer.

**Data**

- No OpenRegister register or schema changes. A placement is a key inside the manifest
  JSON blob on an existing Application object.

**Other apps**

- None consume this. It is an authoring surface inside Buildiq.

**Irreversible**

- Nothing. Every placement the editor writes is an ordinary manifest key that an author can
  edit or remove again.
