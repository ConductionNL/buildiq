# Tasks: pages-chart-widget-authoring

- [x] **T01**: Mount `CnChartWidgetForm` under a `chart` row in `WidgetBuilder.vue` and map `update:content` to `props.chartKind` and `dataSource` as design D2 describes, with pre-fill from the saved widget (REQ-BQCW-001, REQ-BQCW-002). Verify: vitest `tests/components/page-editor/WidgetBuilder.spec.js`, a case that picks "Bar" by category on `requests.status` and asserts the emitted widget validates against `$defs.widgetDef`, and a case that pre-fills from a saved chart.
- [x] **T02**: "Add chart" button and the layout item for a new chart in `DashboardPageEditor.vue` (REQ-BQCW-003). Verify: vitest for the seeded widget and the added layout item; no second layout item when one already references the id.
- [ ] **T03**: Playwright `tests/e2e/page-chart.spec.ts`: a maker adds a bar chart of requests by status to a dashboard page, publishes, and the published page shows a bar per status.
- [x] **T04**: English and Dutch strings for the button; update `docs/` on dashboard pages.
- [ ] **T05**: Run `openspec validate pages-chart-widget-authoring --strict`.

Built: T01 mounts `CnChartWidgetForm` under each `chart` row in `WidgetBuilder.vue`, with the mapping in `fields/chartWidget.js` (form content to `props.chartKind` and `dataSource`, never `content`; pre-fill the way `configWidget()` does); verified by `tests/components/page-editor/WidgetBuilder.spec.js` and `chartWidget.spec.js`, which validate the saved widget against the installed library's `$defs.widgetDef`. T02 adds "Add chart" to `DashboardPageEditor.vue`, adding the widget and its layout item in one config change; verified in `DashboardPageEditor.spec.js`. T04 adds "Add chart" in English and Dutch and a Charts section to `docs/elements/widgets.md`. T03 (Playwright) and T05 (`openspec validate`) are left: the first is browser work, the second needs the openspec CLI.

