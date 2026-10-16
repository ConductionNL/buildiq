---
kind: code
---

# Proposal: data-index-grid-and-saved-views

## Why

Two rows in buildiq's core area (data) are about working with many records at
once, and in both the renderer has more than a maker can switch on.

- buildiq matrix, row `data-grid-edit` ("Edit records inline in a
  spreadsheet-like grid", `no`): "nextcloud-vue v2.55.1 DOES ship a
  spreadsheet-like component (src/components/CnDataMatrix/CnDataMatrix.vue,
  click-to-edit cells with commitEdit/startEdit) but buildiq never imports or
  registers CnDataMatrix anywhere, and no buildiq page-editor type
  (src/components/page-editor/*) offers a grid view".
- buildiq matrix, row `data-saved-views` ("Save a filtered and sorted view of
  records and share it with others", `no`): "nextcloud-vue v2.55.1
  CnIndexPage.vue ships CnSavedViewsControl ... gated by the allowSavedViews
  prop, default false ... grep -rn allowSavedViews src/ in buildiq returns no
  hits, and IndexPageEditor.vue exposes no checkbox for it".

Competitor cells, quoted from the matrix:

- Grid editing, five competitors yes. NocoBase: "TableBlockModel.tsx:775
  "Enable quick edit" block setting, per column at ... TableColumnModel.tsx:525,
  edits a cell in place from the table". Budibase: "spreadsheet Grid with
  canAddRows and canEditRows; end users get the same grid via the 'gridblock'
  Table component". Appsmith: "isCellEditable per column, ...
  inlineEditingSaveOption (row or custom save)". Mendix: "the Editable property
  makes a data grid column editable inline without opening a data view page"
  (https://docs.mendix.com/refguide/columns/). Power Apps: "model-driven apps
  have editable grids"
  (https://learn.microsoft.com/en-us/power-apps/maker/data-platform/data-platform-create-business-rule).
- Saved views, one competitor yes. Budibase: "'Create view' (table or
  calculation view) ... the view page [viewId]/index.svelte:147-148 saves filter
  and sort and :143 GridManageAccessButton sets which role may rea[d]".

`data-grid-edit` is built on five competitor cells and the core area;
`data-saved-views` on the core area. No tender, featureRequest or roadmap row
carries either.

The third row this change was drafted for, `data-export-records`, turned out to
be built: a published app's index page already offers Export to Excel or CSV
through nextcloud-vue 2.57.1 (see design, "What exists"). The lane corrects that
row in the matrix instead of specifying it.

## What changes

- A new page choice in the page designer, "Grid", for a register and schema: a
  spreadsheet-like page where an app user edits cells in place. It is stored as
  a `custom` page with the `record-grid` component, so the manifest's closed
  page-type list does not change.
- A `record-grid` runtime page wraps nextcloud-vue's `CnDataMatrix`, loads the
  schema's records, saves each committed cell to the object, respects a lock
  held by a colleague, and shows fields the user may not change as read-only.
- The index page editor gets a "Saved views" section: a switch that lets app
  users save their own views (`allowSavedViews`), and a list where the maker
  defines views every app user gets (stored as public OpenRegister views for
  that page's scope).

## Rows this closes

| matrix | row id | row name | own rating | what is missing |
|---|---|---|---|---|
| buildiq | data-grid-edit | Edit records inline in a spreadsheet-like grid. | no | a page type or option that edits records in a grid |
| buildiq | data-saved-views | Save a filtered and sorted view of records and share it with others. | no | the switch for saved views, and views the maker shares with every user |

## Existing work it builds on

- `openspec/specs/openbuild-page-designer/spec.md` and
  `openspec/specs/page-designer-ui/spec.md`: the page list and the per-type
  sub-editors, including `CustomPageEditor`.
- `openspec/specs/page-editor-coverage/spec.md` (archived
  `2026-07-11-page-editor-coverage`): the closed page-type enum this change
  respects.
- hydra ADR-033 (collaborative editing): subscribe on view, lock on edit.

## Sibling halves

- nextcloud-vue: `CnSavedViewsControl` in 2.57.1 saves a view without a way to
  share it (no `isPublic` or `sharedWith` in the component), although
  OpenRegister's view holds both (`lib/Db/View.php:136`, `:201`). Sharing a view
  an app user saved is nextcloud-vue's control to extend. This change covers the
  maker-defined views, which buildiq writes itself.
- openregister: none owed; `views#create` and `views#update`
  (`appinfo/routes.php:1786-1787`) take a public view.

## Out of scope

- Formulas, ranges and totals across records in the grid.
- Adding or deleting rows from the grid; the grid edits existing records.
- A filtered export. The Export action exports the whole schema today; the
  filtered `allowExport` menu in nextcloud-vue needs a schema flag OpenRegister
  does not serve, noted for the coordinator.
