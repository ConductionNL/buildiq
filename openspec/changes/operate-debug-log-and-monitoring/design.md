# Design: operate-debug-log-and-monitoring

Read at buildiq development `d21e42f`, openregister development `ae898b0` and
`@conduction/nextcloud-vue` 2.57.1.

## Where it sits

- Preview: `src/views/PageDesigner.vue:160-182` renders the "Live preview"
  surface and mounts `PreviewSandbox` with its props.
  `src/components/page-editor/PreviewSandbox.vue` builds the `CnAppRoot` prop bag
  in `rootProps()` (lines 121-140) and creates a separate Vue app with a memory
  router (`createApp`, line 419; `mount`, line 430).
- Runtime: `src/builder.js` is the standalone entry for a published app, served
  at `/apps/buildiq/builder/{slug}` by `DashboardController::builder()`
  (`lib/Controller/DashboardController.php:125`); it creates the app at line 483.
- Store, nextcloud-vue 2.57.1: `useObjectStore` keeps a failed fetch in `errors`
  and logs it (`src/store/useObjectStore.js:613-617`); nothing else hears of it.
- Insights: `GET /api/applications/{appUuid}/versions/{versionUuid}/insights`
  (`appinfo/routes.php:100`) reaches `ApplicationInsightsService::computeInsights()`
  (`lib/Service/ApplicationInsightsService.php:255`) after
  `requireAuthorisedCaller()` (line 193): viewer or better for production, editor
  or better otherwise, administrators not granted by default. The detail page
  renders its KPI grid in `src/components/applicationDetail/ApplicationDetailDashboard.vue:35-206`
  with the shared 7d, 30d and 90d window.
- Retention: `RuleExecutionLogCleanup`, registered in `appinfo/info.xml:106-109`.
- Metrics: buildiq's `src/manifest.json:1181` declares `observability.health` and
  `observability.metrics` (`objectCount` sources today). OpenRegister's AppHost
  serves them, admin only, and supports `objectSum` (apphost-observability,
  "Declarative Metrics Execution").

## D1. One report stream, two listeners

The runtime reports once (the nextcloud-vue diagnostics channel, sibling half)
and buildiq decides what to do with it per host:

- In the preview, `PreviewSandbox` passes a `diagnostics` function in
  `rootProps()` that pushes entries into the designer's debug log, and sets
  `app.config.errorHandler` on the sandbox app so a render error lands in the log
  instead of only the console.
- In a published app, `builder.js` passes a function that folds entries into
  per-page counters and never keeps an entry's text.

The debug log and the health samples cannot disagree about what a request was,
because they read the same report.

## D2. The debug log stays in the browser

`src/components/page-editor/DebugLogPanel.vue` sits under the preview, with an
"All" and an "Errors" filter and a "Clear" button. It keeps the last 200 entries
in memory, per designer session. An entry has a time, the page id, a kind
(request, error, binding), and for a request the method, path, status, duration
and row count. Request bodies are never recorded. Clicking an entry with a page
id selects that page in the designer. Nothing is sent to the server.

Binding problems come from two places: the runtime (a column or field whose
property the schema does not have, a register or schema answering 404), and
buildiq's own manifest checks (`useManifestValidator`), which already mark
invalid fields inline. The panel lists both, so a maker sees every problem of
the previewed page in one list.

## D3. A health sample holds counts, never content

Every five minutes, and when the page is hidden, the runtime posts one sample per
page it used: `pageId`, the window start, `requests`, `failures4xx`,
`failures5xx`, `durationBuckets` (under 250 ms, under 1 s, under 3 s, 3 s and
over), `loads`, `loadMsSum` and `clientErrors`. There is no user id, no path with
query values, no request or response body, and no error message. A browser with
nothing to report posts nothing.

## D4. The server derives the scope and fails closed

`POST /api/applications/{slug}/health-samples` is `#[NoAdminRequired]`. The
controller resolves the app and version from the slug and the `_version` query
the runtime already carries, and refuses (404, same as an unknown app) a caller
who may not open that version, by the rule the manifest endpoint uses. It drops
unknown keys, caps each count at 10,000 per sample and 50 pages per post, and
takes a `#[UserRateLimit]`. A sample is written as one `app-health-sample` object
in the shared `buildiq` register, declared in a new fragment
`lib/Settings/register.d/75-app-health-samples.json`. Samples are append only, so
two browsers never overwrite each other.

## D5. Thirty days, then gone

`lib/BackgroundJob/AppHealthSampleCleanup.php`, a daily timed job registered next
to `RuleExecutionLogCleanup`, deletes samples older than 30 days (an app setting,
7 to 90).

## D6. The health panel reads through the insights gate

`ApplicationInsightsService` gains `computeHealth()`, called from the same
controller after the same `requireAuthorisedCaller()`, so a user who cannot see a
version's usage cannot see its health either. It sums the window's samples with
OpenRegister aggregation and returns failure rate, average load time, share of
requests over 1 s, and the five slowest pages by average load time. The detail
page shows it as a "Health" section under the KPI grid.

## D7. Operators get the same numbers as metrics

Three `objectSum` descriptors in `src/manifest.json` `observability.metrics`
(`app_requests_total`, `app_request_failures_total`, `app_client_errors_total`,
grouped by app and version) expose the samples on the AppHost metrics endpoint,
which stays administrator only. No provider class is needed.

## Risks

- Sample volume grows with users. Five-minute batches, one sample per page per
  batch, and 30-day retention bound it; the cleanup job and the cap per post keep
  it from running away.
- A counter is not a percentile. The histogram gives the share of slow requests,
  not an exact p95, and the panel says "share over 1 s" rather than a percentile.
- The debug panel is only as good as the reports nextcloud-vue sends. Until the
  channel ships, only buildiq's own checks and the sandbox error handler feed it.

## What it does not do

- It sends nothing from the designer's preview to the server.
- It stores no user, message or payload from a running app.
