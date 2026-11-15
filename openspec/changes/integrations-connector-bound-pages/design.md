# Design: integrations-connector-bound-pages

Read at buildiq development `d21e42f`, nextcloud-vue 2.57.1 as installed.

## What exists

- Authoring: `src/components/page-editor/DataSourceOriginToggle.vue:12-44`
  switches a page between OpenRegister and OpenConnector and hosts
  `ConnectorSourcePicker` and `ConnectorFieldMapper`; it writes
  `config.dataSource.connector` on the page. `IndexPageEditor.vue:13-14` and
  `DashboardPageEditor.vue:13-14` mount it; `IndexPageEditor.vue:209`
  `connectorActive()` hides the register and schema pickers when a connector is
  bound, and `onDataSourceUpdate()` (IndexPageEditor.vue:404,
  DashboardPageEditor.vue:112) stores the block.
- Runtime fetch: `src/composables/useConnectorDataSource.js:44` resolves a
  binding; `fetchRaw()` (lines 84-93) calls
  `GET /apps/integriq/api/endpoint/{path}` through `fleetAppPath('integriq', ...)`
  with the session, then projects `itemsPath` and `fields` with
  `src/services/selectors.js`.
- Runtime widget: `src/components/runtime/ConnectorDataView.vue` renders the
  rows with loading, error, stale and retry states; registered at
  `src/runtimeRegistry.js:90` as `'connector-data': widget(ConnectorDataView,
  ['body', 'sidebar'])`.
- The published mount: `src/builder.js:279` `normalizeManifestPages()` fixes page
  configs before the shell mounts; the shell gets `registry: { ...runtimeRegistry }`
  and `pageTypes: { ...defaultPageTypes }` (builder.js, shellProps). The preview
  host `src/views/BuilderHost.vue:41` passes `runtimeRegistry` too.
- nextcloud-vue 2.57.1: `CnPageRenderer.vue` resolves page types through
  `pageTypes` and a `custom` page's `component` through the registry
  (lines 8-12, 744-757). `CnIndexPage` binds `register` and `schema` only; no
  component in the library reads `dataSource.connector`.

So the binding is authored and stored, the fetch and projection work, and the
widget renders, but no page the maker builds ever reaches the widget.

## D1. Normalise the manifest, do not fork the page types

The manifest v2 page-type enum is closed, so a new page type is not an option.
A normaliser in a new module `src/services/connectorPages.js` rewrites, in
memory and never in the stored manifest:

- an `index` page with `config.dataSource.connector` into a `custom` page whose
  `component` is `connector-list`, carrying the original `title`, `route`,
  `id` and the `dataSource` in `props`;
- a `dashboard` page with `config.dataSource.connector` by prepending a
  `connector-data` widget bound to that `dataSource` to its widget layout.

`normalizeManifestPages()` in `builder.js` calls it, and so does `BuilderHost.vue`
before it hands the manifest to its `CnAppRoot`. The stored manifest keeps the
author's shape, so the page designer still opens the page as an index page with
its origin toggle on OpenConnector.

## D2. One list page component, reusing the widget

`src/components/runtime/ConnectorListPage.vue` renders the page title, a search
box that filters the projected rows client-side on the mapped fields, and
`ConnectorDataView` for the rows. It is registered in `src/runtimeRegistry.js`
as `connector-list` with kind `page` and an ADR-049 `_note` saying no built-in
page type can bind a connector (gate-29 custom-widget ratchet).

## D3. The dashboard widget becomes placeable

`connector-data` is registered but no editor offers it. The dashboard widget
picker lists it, and choosing it opens the same origin toggle, picker and field
mapper for that widget's own `dataSource`. The widget's config shape does not
change.

## D4. Same registry for both hosts

`openbuild-connector-widget-runtime` adds `getRuntimeRegistry()`. The new page
entry goes into that registry, so the parity guard it adds covers the page too.
If that change has not landed, this change's first task adds the accessor.

## Risks

- A large endpoint response is filtered client-side. The runtime already caches
  per binding (`connectorCache`), and paging stays a follow-up.
- An endpoint that errors shows the widget's error state with Retry, never a
  blank page. A stale cached response is marked stale.

## What it does not do

- No writes to an external API, no detail page for an external record.
- No change to `dataSource.connector`'s shape or its validation.
- No credential ever reaches buildiq (REQ-OCAS-004 stays as it is).
