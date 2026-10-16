# Design: pages-chart-widget-authoring

Read at buildiq development `974af862` and nextcloud-vue development `e487bc8`; buildiq pins `@conduction/nextcloud-vue` `^2.57.1`, and every library file named here exists at tag `v2.57.1`.

## What exists

- `src/components/page-editor/DashboardPageEditor.vue:17-29` mounts `WidgetBuilder` on `config.widgets` and `LayoutItemBuilder` on `config.layout`, and validates both keys through `pageEditorValidationMixin` (`:81`).
- `src/components/page-editor/fields/WidgetBuilder.vue:6-45` renders three text inputs per widget (id, title, type); `addWidget()` seeds `{ id: '', title: '', type: 'custom' }`; `updateField()` writes one key.
- nextcloud-vue:
  - `src/components/CnChartWidgetForm/CnChartWidgetForm.vue`, exported from the package root: chart type and breakdown selects, `CnRegisterSchemaSelect`, a date field with interval for a time series or a group field for categories, and a metric. It takes `editingWidget` (`{ content }`) or `value` and emits `update:content` with `{ chartKind, dataSource: { register, schema, filter, bucket | groupBy } }`. Its defaults are `DEFAULT_CONTENT` (`:119-127`).
  - `src/schemas/app-manifest.schema.json` `$defs.widgetDef`: `additionalProperties: false`, properties `id`, `title`, `type`, `props`, `dataSource` and the icon keys; no `content`. `dataSource` allows extra keys, so `bucket` and `groupBy` pass.
  - `src/components/CnDashboardPage/CnDashboardPage.vue`: `isChart()` (`:3601`) mounts `CnChartWidget` for `type: 'chart'`; `getChartProps()` (`:3710`) reads the chart type from `content.chartKind` or `props.chartKind`; `getWidgetDataSource()` (`:3629`) reads `content.dataSource` or the widget's own `dataSource`; `configWidget()` (`:1847-1866`) bridges `props` and `dataSource` into the form's `content` shape the same way.

## D1. Mount the library form, do not rebuild it

`WidgetBuilder` renders `CnChartWidgetForm` under a widget row whose `type` is `chart`. The form already knows the register and schema pickers, the field list and the breakdown rules, and the dashboard's own in-app editor uses it, so a maker sees the same form in both places.

## D2. Map the form's content onto the v1 widget definition

The v1 `widgetDef` refuses a `content` key. On `update:content` the builder writes `props.chartKind = content.chartKind` (keeping any other `props` keys) and `dataSource = content.dataSource`. For pre-fill it builds `{ content: { chartKind: props.chartKind, dataSource } }`, as `configWidget()` does. The renderer reads exactly those two keys, so the saved page draws the chart the maker configured.

## D3. "Add chart" seeds a complete widget and its layout item

The button adds `{ id: 'chart-<n>', title: '', type: 'chart', props: { chartKind: 'bar' }, dataSource: { register: '', schema: '' } }` with the first unused `n`, and `DashboardPageEditor` adds a layout item for that id at the first free row (`gridWidth` 6, `gridHeight` 4) when no layout item references it. A widget without a layout item is not rendered, which is the trap a maker hits today.

## D4. Validation stays the schema's

The saved widget passes through the existing `useManifestValidator` path. A chart without a register or schema is marked inline on the widget row with the message the form's own required fields use.
