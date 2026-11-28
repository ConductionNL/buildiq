---
kind: code
---

# Proposal: pages-chart-widget-authoring

## Why

buildiq matrix, row `pg-charts` ("Show records as bar, line or pie charts.", buildiq rated `partial`, built.state `built`): "renderer: nextcloud-vue v2.55.1 CnChartWidget.vue supports type area/line/bar/pie/donut/radialBar ... buildiq authoring: src/components/page-editor/fields/WidgetBuilder.vue only exposes 3 free-text inputs per widget -- id, title, type (lines 6-45) -- with no fields for chartKind, series, categories, labels or colors". The row's note: "a builder user cannot configure a working chart through the UI alone".

The row came to buildiq from nextcloud-vue. The nextcloud-vue OpenSpec-pass lane recorded `pg-charts` as `existing` for the library ("CnChartWidget renders bar, line, pie and more from a record aggregation (dataSource bucket or groupBy), and CnChartWidgetForm is the in-app editor for chart type and breakdown. The missing half is buildiq's WidgetBuilder, which offers only id, title and type") in its `openspec/parity/gap-decisions.json` (nextcloud-vue PR #1269). The sibling pass then moved the row's `built.owner` to `ConductionNL/buildiq` (buildiq PR #989). This change is that missing half.

All five competitors rate it yes, quoted from the matrix:

- NocoBase: "packages/plugins/@nocobase/plugin-data-visualization/src/client-v2/flow/models/ChartOptionsBuilder.service.ts:10 chart types line, bar, barHorizontal, pie, doughnut, scatter, area, funnel ... Reached on: page, Add block, Charts".
- Budibase: "componentStructure.ts:88-99 Chart category bar, line, area, candlestick, pie, donut, histogram, gauge ... Reached on: design > add component > Chart".
- Appsmith: "app/client/src/widgets/ChartWidget/constants.ts:4 LINE_CHART, :5 BAR_CHART, :6 PIE_CHART ... Reached on: chart widget on a page".
- Mendix: "corpus/mendix.tsv #26386 (2026-04-10): Data Grid & Charts: advanced data grids, charts and visualization components".
- Power Apps: "https://learn.microsoft.com/en-us/power-apps/maker/model-driven-apps/model-driven-app-overview (2026-09-26): charts are a model-driven app component".

No tender, featureRequest or roadmap row carries it.

## What changes

- A maker adds a chart to a dashboard page from the widget list with one button, "Add chart".
- For a widget of type `chart`, the widget list shows the library's chart form: chart type, breakdown (over time or by category), register and schema, date or category field, interval and metric.
- The editor writes what the form produces into the manifest's widget definition: `props.chartKind` and `dataSource`.
- A new chart gets a layout item, so it shows on the page without a second step.

## Rows this closes

| matrix | row id | row name | own rating | what is missing |
|---|---|---|---|---|
| buildiq | pg-charts | Show records as bar, line or pie charts. | partial | picking a chart type and binding it to records in the page designer |

## Sibling halves

None. nextcloud-vue 2.57.1 renders the chart and ships the form this change mounts.

## Out of scope

- Charts over an integriq endpoint (`props.endpointSource`); `openbuild-connector-widget-runtime` owns that binding.
- Typed forms for the other dashboard widget types. They store their settings in a `content` block that the v1 widget definition does not allow, so they need their own decision.
