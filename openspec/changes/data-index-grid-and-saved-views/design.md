# Design: data-index-grid-and-saved-views

Read at buildiq development `d21e42f`, nextcloud-vue 2.57.1 as installed,
openregister development `ae898b0`. Buildiq runs Vue 3.5 (`package.json:68`).

## What exists

- Page authoring: `src/components/page-editor/PageListEditor.vue:164` lists the
  page types including `custom`; `src/views/PageDesigner.vue:261` maps `custom`
  to `CustomPageEditor`, which authors `{component, props}`.
  `src/components/page-editor/IndexPageEditor.vue` authors data source,
  register, schema, card component, columns (`ColumnBuilder`, lines 66-72),
  actions and the sidebar. It has no switch for saved views.
- Runtime: `src/runtimeRegistry.js:86` exports the registry both hosts pass to
  `CnAppRoot`; `CnPageRenderer` resolves a `custom` page's `component` against
  it.
- nextcloud-vue 2.57.1:
  - `CnDataMatrix.vue` (header comment lines 86-116) is an "Inline-edit data
    grid for matrix-shaped data (rows x columns). Each cell is click-to-edit;
    commits emit `@cell-edit({ rowId, colKey, value })` so the parent can
    persist". `commitEdit()` (lines 295-311) casts numbers and emits.
  - `useObjectLock.js:66` `useObjectLock(objectStore, register, schema, id)`,
    the ADR-033 lock composable.
  - `CnIndexPage.vue:1720-1723` `allowSavedViews` (default false) and
    `:1734` `savedViewsScope`; the views are OpenRegister's
    (`GET /apps/openregister/api/views`).
  - Export, for the record: `CnIndexPage.vue:1674-1677` `showMassExport`
    defaults to true, `CnActionsBar.vue:205-212` renders Export,
    `CnMassExportDialog.vue:136-141` offers Excel and CSV, and
    `selfModeActions.js:151-166` with `selfModeIO.js:58-61` downloads
    `GET /apps/openregister/api/objects/{register}/{schema}/export`. Buildiq sets
    none of these off, so a built index page exports today. The filtered menu
    (`allowExport`, `CnIndexPage.vue:3589-3590`) also needs
    `effectiveSchema.exportable`, and openregister's `lib/` has no `exportable`
    on a schema.
- openregister: `lib/Db/View.php:136` `isPublic`, `:201` `sharedWith`;
  `appinfo/routes.php:1784-1788` the views API.

## D1. The grid is a custom page, not a new page type

The manifest's page-type enum is closed (`app-manifest-v2.schema.json`
`$defs.page.properties.type`). A grid page is stored as
`{type: 'custom', component: 'record-grid', props: {register, schema, columns}}`.
The page list offers "Grid" as a choice that writes that shape, and a
`GridPageEditor` (register, schema, and the columns with an editable toggle per
column, reusing `ColumnBuilder`) replaces the raw JSON of `CustomPageEditor` for
this component.

## D2. The runtime page saves one cell at a time

`src/components/runtime/RecordGridPage.vue` loads the records through
nextcloud-vue's `useObjectStore` for the register and schema, maps each record
to a matrix row (`id` is the uuid, one key per column), and on `cell-edit`
saves that single property on that object. Before the first edit of a row it
takes the ADR-033 lock with `useObjectLock`; a lock held by someone else makes
that row read-only with the holder's name. A failed save puts the old value back
and says why. It is registered as `record-grid` with kind `page` and an ADR-049
`_note` (no built-in page type edits records in a grid).

## D3. Read-only follows the schema and the user

A column is editable only when the maker ticked it, the property is not
`readOnly` in the schema, and the user may update the object. The last check is
the server's: a refused save shows the refusal on the cell.

## D4. Two kinds of saved views

- "Let app users save their own views" sets `config.allowSavedViews: true`; the
  existing control does the rest.
- "Views for everyone": the maker names a view and sets its filters and sort in
  the editor; saving the app writes it as a public OpenRegister view in the
  page's `savedViewsScope`, so the control lists it for every user. The editor
  reads them back by scope, so renaming the page does not orphan them.

## Risks

- A large schema in a grid: the page pages records like an index page and never
  loads the whole schema.
- Two colleagues on one row: the lock (D2) and OpenRegister's own conflict check
  keep the second save from overwriting the first.

## What it does not do

- It does not change `CnIndexPage` or `CnDataMatrix`.
- It adds no row creation or deletion to the grid.
- It does not share a view an app user saved; that is nextcloud-vue's control.
