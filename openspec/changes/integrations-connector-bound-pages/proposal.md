---
kind: code
depends_on: [openbuild-connector-widget-runtime]
---

# Proposal: integrations-connector-bound-pages

## Why

A maker can bind an index or dashboard page to an integriq endpoint in the page
designer, map the response to fields, and publish. The published page then
shows nothing from that endpoint. Three rows of the buildiq matrix record the
same break from three sides:

- buildiq matrix, row `int-rest-connector` ("Connect to any REST API and use it
  from an app", `partial`): "Break: nothing turns a page's
  dataSource.connector into that widget. grep 'connector-data' over src finds
  only runtimeRegistry.js; nextcloud-vue v2.55.1 CnIndexPage/CnPageRenderer/CnDashboardPage
  never read dataSource.connector". Integriq's lane decided the row on
  2026-09-27: "The missing half is buildiq's renderer: nextcloud-vue CnIndexPage
  ignores dataSource.connector. Not integriq's to build; the built.owner should
  read ConductionNL/buildiq." This change takes the row as buildiq's.
- buildiq matrix, row `data-external-api-source` ("Use records from an external
  API as an app's data source", `partial`): "an authored connector-bound page
  has no runtime renderer (see int-rest-connector and int-connector-field-map)".
- buildiq matrix, row `int-connector-field-map` ("Map the fields of an external
  source onto an app's fields", `partial`): "Same break as int-rest-connector:
  the projection runs only inside ConnectorDataView, which no authored page
  renders."

Every competitor that was read renders what it binds:

- NocoBase: "packages/plugins/@nocobase/plugin-action-custom-request/src/client-v2/CustomRequestActionModel.tsx:24
  "Custom request" button calling any REST URL from a page"
  (https://docs.nocobase.com/data-sources/data-source-rest-api/index.md:1).
- Budibase: "packages/server/src/integrations/rest.ts REST datasource; ...
  queries edited in packages/builder/src/components/integration/QueryViewer.svelte:449
  (with transformer) and bound to screens".
- Appsmith: "app/server/appsmith-plugins/restApiPlugin/src/main/java/com/external/plugins/RestApiPlugin.java:53
  RestApiPluginExecutor with auth types; ... query results bind into widgets
  such as the table".
- Mendix: "consumed REST services, including import of an OpenAPI/Swagger
  contract" (https://docs.mendix.com/refguide/consumed-rest-services/), and for
  the field map "import mappings map the fields of an XML or JSON structure from
  an external service onto entities and attributes"
  (https://docs.mendix.com/refguide/import-mappings/).
- Power Apps: "connectors provide tables of data or actions; standard or custom
  connectors" (https://learn.microsoft.com/en-us/power-apps/maker/canvas-apps/connections-list),
  and "dataflows ... map the source columns to Dataverse table columns"
  (https://learn.microsoft.com/en-us/power-apps/maker/data-platform/create-and-use-dataflows).

Five competitors rate `int-rest-connector` yes, four `data-external-api-source`
and two `int-connector-field-map`. No tender, featureRequest or roadmap row
carries these rows.

## What changes

- A runtime normaliser turns a page that carries `config.dataSource.connector`
  into something the renderer draws: an index page becomes a
  connector-list page, and a dashboard page gets a `connector-data` widget for
  its binding.
- A `connector-list` page entry in the runtime registry wraps the existing
  `ConnectorDataView` with the page's title, a search box over the projected
  fields, and the loading, error, stale and empty states the widget already has.
- The published runtime (`src/builder.js`) and the preview host
  (`src/views/BuilderHost.vue`) run the same normaliser, so the preview never
  shows a page the published app cannot.
- The dashboard widget picker offers `connector-data` as a widget a maker can
  place and bind, through the same origin toggle, picker and field mapper.
- A published-path Playwright spec proves a connector-bound index page renders
  its mapped rows.

## Rows this closes

| matrix | row id | row name | own rating | what is missing |
|---|---|---|---|---|
| buildiq | int-rest-connector | Connect to any REST API and use it from an app. | partial | the published page renders nothing from a bound endpoint |
| buildiq | data-external-api-source | Use records from an external API as an app's data source. | partial | same: an authored connector page has no renderer |
| buildiq | int-connector-field-map | Map the fields of an external source onto an app's fields. | partial | the mapping only projects inside a widget no page places |

## Existing work it builds on

- `openspec/specs/openconnector-api-sources/spec.md` (archived change
  `2026-06-14-openconnector-api-sources`): the `dataSource.connector` shape
  (REQ-OCAS-001), the builder step (REQ-OCAS-002), the field mapping
  (REQ-OCAS-003) and the runtime fetch path with caching (REQ-OCAS-006).
- `openbuild-connector-widget-runtime` (open, 0 of 16 tasks): one runtime
  registry seam for both hosts and a published-path test for the
  `connector-data` widget. This change uses that seam and adds the page path it
  does not cover.

## Sibling halves

- integriq: none owed. `endpoints#handlePath` (integriq `appinfo/routes.php:391`,
  cited in the row evidence) already serves the call, and credentials stay in
  the integriq source (REQ-OCAS-004).
- nextcloud-vue: none owed for this change. A native `dataSource.connector` in
  `CnIndexPage` would remove the normaliser later; it is not needed to render.

## Out of scope

- Writing back to an external API from a page (create, update, delete).
- Opening a detail page for an external record.
- Direct database connections; that is `data-external-database-sources`.
