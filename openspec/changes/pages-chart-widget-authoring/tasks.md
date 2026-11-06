# Tasks: pages-chart-widget-authoring

- [ ] **T01**: Mount `CnChartWidgetForm` under a `chart` row in `WidgetBuilder.vue` and map `update:content` to `props.chartKind` and `dataSource` as design D2 describes, with pre-fill from the saved widget (REQ-BQCW-001, REQ-BQCW-002). Verify: vitest `tests/components/page-editor/WidgetBuilder.spec.js`, a case that picks "Bar" by category on `requests.status` and asserts the emitted widget validates against `$defs.widgetDef`, and a case that pre-fills from a saved chart.
- [ ] **T02**: "Add chart" button and the layout item for a new chart in `DashboardPageEditor.vue` (REQ-BQCW-003). Verify: vitest for the seeded widget and the added layout item; no second layout item when one already references the id.
- [ ] **T03**: Playwright `tests/e2e/page-chart.spec.ts`: a maker adds a bar chart of requests by status to a dashboard page, publishes, and the published page shows a bar per status.
- [ ] **T04**: English and Dutch strings for the button; update `docs/` on dashboard pages.
- [ ] **T05**: Run `openspec validate pages-chart-widget-authoring --strict`.
