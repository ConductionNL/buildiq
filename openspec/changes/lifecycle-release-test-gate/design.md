# Design: lifecycle-release-test-gate

Read at buildiq development `d21e42f` and openregister development `ae898b0`.

## Where it sits

- Release: `POST /api/applications/{appSlug}/versions/{versionSlug}/release`
  (`appinfo/routes.php:91`) reaches `ApplicationVersionsController::release()`
  (`lib/Controller/ApplicationVersionsController.php:514-560`): signed-in check,
  owner check (`isOwnerStrict()`, line 531), then
  `ApplicationVersionService::releaseVersion()` (line 544; service at
  `lib/Service/ApplicationVersionService.php:428`), which answers 422 on failure.
- Promotion: `POST /api/applications/{appUuid}/versions/{versionUuid}/promote`
  (`appinfo/routes.php:110`) reaches `VersionPromotionController::promote()`
  (`lib/Controller/VersionPromotionController.php:113`) and
  `VersionPromotionService::promote()`.
- Rule sets: `RuleSet.ownerApp`, `TestCase` with `inputPayload` and
  `expectedResult` (`lib/Settings/register.d/10-business-rules.json`).
  `RuleSetVersioningService::runTestGate()` (`lib/Service/RuleSetVersioningService.php:154`)
  returns the names of failing cases; `promoteToActive()` already refuses on
  failures. `RulesController::testAll()` (`lib/Controller/RulesController.php:236`)
  is the HTTP form. The rule-set page has no menu entry
  (`src/manifest.d/20-business-rules.json`, `menu: []`).
- App detail: the `VirtualAppDetail` page in `src/manifest.json` (from line 442)
  declares `sidebarTabs` (manifest, history, diff, icons, exports, audit, lines
  467-514), each a registered component.
- Validation, openregister: `ValidateObject::validateObject()`
  (`lib/Service/Object/ValidateObject.php:1694`) validates without saving; it is
  not on `Contract\ObjectServiceInterface`.

## D1. One suite service, three kinds of check

`lib/Service/AppTestSuiteService.php::run(application, version)` returns a report:
each check with kind, name, outcome (passed, failed, skipped) and a message.

- Structure: walk the version's `manifest.pages[]` and `menu[]`. For each page,
  resolve `config.register` and `config.schema` through OpenRegister; for index
  columns and detail fields, check the property exists on the schema; for each
  menu item, check its route names a page. This is the release-time twin of the
  designer's own checks.
- Rule sets: find `RuleSet` objects whose `ownerApp` is the app, load their
  `TestCase` objects and call `runTestGate()`.
- Record tests: a new `appRecordTest` schema (fragment
  `lib/Settings/register.d/24-app-record-tests.json`) with `application`,
  `schema`, `name`, `record`, `expect` (`accept` or `refuse`) and
  `expectedFields[]`. Each runs through OpenRegister's validate-only call (sibling
  half) against the version's schema. Until that call exists, record tests report
  `skipped` with the reason, never `passed`.

## D2. The gate sits in the service, not the button

`ApplicationVersionService::releaseVersion()` and `VersionPromotionService::promote()`
(for a promotion whose target is the production version) call the suite before
they write. A failed suite throws a typed exception the controllers turn into 422
with the report, so the API and every screen are gated alike. An owner may pass
`override: {reason}`; the release then proceeds, and the reason, the user and the
failures are stored on the run. The override is owner-only, the same check
`release()` already applies.

## D3. Runs are kept as records

Every run is stored as an `appTestRun` object (same fragment): version, time,
trigger (manual, release, promotion), counts, the report and any override. The
Tests tab shows the latest run per version. Runs follow the retention of
`RuleExecutionLog` (the `RuleExecutionLogCleanup` pattern).

## D4. A Tests tab on the app

A new `ApplicationTestsTab` component, registered like the other sidebar tabs, lists
the three kinds with their checks, offers "Run tests" for the selected version,
lets an owner or editor add, edit and remove record tests, and links each rule
set to its sandbox (`RuleSetTestSandboxModal`). The rule sets become reachable
from the app without typing a URL.

## Risks

- Structure checks on a big app make many lookups. The service caches schemas per
  run and runs checks in manifest order; a release waits for it, bounded by the
  number of pages.
- A strict gate can block an urgent fix. The owner override exists for that and
  is recorded.
- Record tests stay `skipped` until OpenRegister's validate-only call ships. The
  tab says so.

## What it does not do

- It does not render pages or drive a browser.
- It does not test flows or agents.
