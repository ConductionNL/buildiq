---
kind: code
---

# Proposal: operate-debug-log-and-monitoring

## Why

Two rows ask the same question at two moments: what went wrong, and how slow was
it. While a maker builds (a debug log beside the preview) and after the app is
live (health numbers per app). Both need the same thing from the page runtime: a
report of each request and each error. No tender, featureRequest or roadmap row
carries either; the competitor cells are the demand.

buildiq matrix, row `lc-debug-log`, "Debug a page's queries and bindings in an
error log inside the builder.", rated `no`. The row is competitor-derived
(origin
https://github.com/appsmithorg/appsmith/blob/v2.4.2/app/client/src/components/editorComponents/Debugger/index.tsx#L16).
Two competitors rated `yes`:

- Appsmith: "app/client/src/components/editorComponents/Debugger/index.tsx:16
  DebuggerTrigger in the editor bottom bar
  (app/client/src/components/BottomBar/index.tsx:2) with logs and errors tabs".
- Microsoft Power Apps: "Live monitor traces events as they happen in a canvas
  app while authoring in Power Apps Studio, and Monitor also debugs the published
  app" (https://learn.microsoft.com/en-us/power-apps/maker/monitor-canvasapps).

buildiq matrix, row `ops-performance-monitor`, "Monitor the performance and
errors of built apps.", rated `no`. Two competitors rated `yes`:

- Mendix: "monitoring and logging through third-party tools; tsv #26383
  performance monitoring"
  (https://docs.mendix.com/developerportal/deploy/private-cloud/).
- Microsoft Power Apps: "Monitor gives detailed information on app performance
  and actionable steps; Application Insights can collect telemetry"
  (https://learn.microsoft.com/en-us/power-apps/maker/common/monitor-app-performance).

What buildiq does today, per the matrix `built.evidence`:

- `lc-debug-log`: "No in-builder error log for page data and bindings (grep -iE
  'debugger|error log' over src/views and src/components/page-editor: no hits);
  automation runs have a partial run log (logic-run-log)".
- `ops-performance-monitor`: "grep for errorTracking/Sentry/'performance
  monitor'/APM/'slow quer' across lib/ and src/: no hits. ops-usage-insights
  (view/create counts per version/window) is the closest built capability but it
  is usage analytics, not error/performance monitoring."

The pieces around the gap exist. The page designer runs a live preview as its
own Vue app (`src/components/page-editor/PreviewSandbox.vue:419`), and a
published app runs from `src/builder.js` (created at line 483). Neither hears
about a request: nextcloud-vue's object store keeps a failure in its own
`errors` state and writes it to the browser console
(`src/store/useObjectStore.js:613-617` and eight more sites at 2.57.1), and it
reports no timings to anyone. The app detail page shows usage numbers from
`ApplicationInsightsService`, and buildiq's manifest already feeds OpenRegister's
AppHost metrics endpoint (`src/manifest.json:1181`, `observability`).

## What changes

- The page designer gets a "Debug" panel under the live preview. It lists each
  request the preview makes (method, path, status, time, rows) and each error,
  with the page it came from, and names binding problems: a column or field that
  points at a property the schema lacks, and a register or schema that does not
  exist. The log lives in the maker's browser only.
- A published app reports health samples every five minutes: per page, the
  number of requests and failures, a small histogram of request times, page load
  time and the number of errors. No user, no request body, no query values, no
  error text.
- Buildiq stores the samples in its register, keeps them 30 days, and shows a
  "Health" panel on the app detail page: failure rate, average load time, share
  of slow requests and the slowest pages, per version and window.
- The same numbers appear on the AppHost metrics endpoint for operators who use
  Prometheus.

## Rows this closes

| matrix | row id | row name | own rating | what is missing |
|-|-|-|-|-|
| buildiq | lc-debug-log | Debug a page's queries and bindings in an error log inside the builder. | no | a log of the preview's requests, errors and binding problems in the page designer |
| buildiq | ops-performance-monitor | Monitor the performance and errors of built apps. | no | health samples from running apps, a health panel per app, and metrics for operators |

## Existing work it builds on

- `page-designer-live-preview-pane` (open) and `openspec/specs/openbuild-page-designer`
  (REQ-OBPD-008): the sandboxed preview the debug panel sits under.
- `openspec/specs/application-insights`: the insights endpoint, its auth gate
  ("Auth gate mirrors buildiq-version-routing") and its window toggle, which the
  health panel reuses.
- `openspec/specs/settings-and-observability`: buildiq's settings and
  observability surface.
- `openspec/specs/business-rules-engine`: `RuleExecutionLog` and its retention job
  (`lib/BackgroundJob/RuleExecutionLogCleanup.php`), the pattern the sample
  retention follows.
- openregister `apphost-observability` (spec, done): "Declarative Metrics
  Execution" with the `objectSum` source kind.

## Sibling halves

- nextcloud-vue owes a diagnostics channel. `CnAppRoot` takes an optional
  `diagnostics` function and provides it; the object store reports each request
  (method, path without query values, status, duration, row count), the page
  renderer reports render errors and unknown components, and the index and detail
  pages report a column or field whose property the schema lacks. Without a
  function it does nothing. No open nextcloud-vue change covers it. Buildiq's
  half is the two listeners: the debug panel and the health reporter.
- openregister: none. The `objectSum` metric kind and the objects API are enough.

## Out of scope

- Server-side tracing of OpenRegister queries (SQL timings). That is
  OpenRegister's.
- Alerts and notifications on thresholds.
- Session replay, user-level tracking, or storing error messages from app users.
- Debugging flows and automations, which have their own run logs.
