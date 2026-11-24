# buildiq-reports Specification

**OpenSpec changes**: [operate-report-download](../../changes/archive/2026-11-24-operate-report-download/)

## Purpose

@e2e exclude after-the-fact spec of declarative manifest pages (src/manifest.json Reports, ApplicationsReport, DeliveryReport) rendered by the shared nextcloud-vue CnReportsPage and CnDashboardPage; buildiq ships no page code to drive, and the first buildiq e2e for reports is a task of operate-report-download

Give an administrator of buildiq one place to read how the builder is used
across every app on the instance: how many apps exist and how far along they
are, and what has been versioned and exported where. Written after the fact
(8 Oct 2026, decision 99) for row `ops-reports` and board **BqRapportages**;
the pages landed in commit 37ad4814b without a spec.

The reports are declarative pages in buildiq's own manifest over buildiq's own
register (ADR-022: OpenRegister's aggregation does the counting). Every filter
is scalar equality, the only kind OpenRegister's aggregation endpoint
evaluates.

## Requirements

### Requirement: A Reports page lists the reports (REQ-BQREP-001)

buildiq SHALL have a page "Reports" at `/reports`, reached from the menu entry
"Reports" in the settings section of buildiq's navigation. The page SHALL show
one card per report, grouped by category: "Applications" under "Apps" and
"Versions and exports" under "Versions and delivery". Each card SHALL open its
report.

#### Scenario: The Reports page offers both reports

- **GIVEN** a buildiq administrator
- **WHEN** they choose "Reports" in the navigation
- **THEN** the page shows the cards "Applications" and "Versions and exports"
- **AND** choosing "Applications" opens `/reports/applications`

### Requirement: The Applications report counts apps across the instance (REQ-BQREP-002)

The page at `/reports/applications` SHALL show, over schema `built-app` of
register `buildiq`: three counts (Published, Draft, Archived by `status`), a
donut "By status" grouped by `status`, a donut "Virtual or hybrid" grouped by
`appType`, a donut "Templates by use case" over schema `application-template`
grouped by `category`, and a table "Most recent" of the eight newest apps with
the columns App, Type and Status and a link "View all apps".

#### Scenario: Counts follow the app statuses

- **GIVEN** five published apps, two draft apps and one archived app
- **WHEN** an administrator opens the Applications report
- **THEN** the counts read Published 5, Draft 2, Archived 1

#### Scenario: An empty instance says so

- **GIVEN** no app exists
- **WHEN** an administrator opens the Applications report
- **THEN** the donuts and the table show "No apps yet"

### Requirement: The Versions and exports report shows delivery (REQ-BQREP-003)

The page at `/reports/delivery` SHALL show: the count "Versions published"
over schema `applicationVersion` with `status` published, the counts "Exports
succeeded" and "Exports failed" over schema `export-job` by `status`, donuts
"Versions by status" (`status`), "Admin or user scope" (`scope`) and "Exports
by target" (`target`), and a table "Most recent exports" of the eight newest
export jobs with When, Target and Status.

#### Scenario: A failed export is counted

- **GIVEN** eleven succeeded export jobs and one failed export job
- **WHEN** an administrator opens Versions and exports
- **THEN** "Exports succeeded" reads 11 and "Exports failed" reads 1
- **AND** the failed job is in "Most recent exports" with status failed

### Requirement: A report can be downloaded as CSV (REQ-BQREP-004)

The Applications report and the Versions and exports report SHALL each show a
"Download" button in the page header. Clicking it MUST save one CSV file
named `buildiq-<report>-<yyyy-mm-dd>.csv` with a header row
`widget,label,value` and one row per figure on the page: each count, each
group of each donut, and each visible row of each table. The button MUST be
disabled while any widget is still loading.

@e2e tests/e2e/reports-download.spec.ts

#### Scenario: Downloading the Applications report

- **GIVEN** five published apps, two draft apps and one archived app
- **WHEN** an administrator opens the Applications report and clicks "Download"
- **THEN** a file `buildiq-applications-2026-10-08.csv` is saved on 8 October 2026
- **AND** it has the row `Published,Published,5` and the row `By status,draft,2`

#### Scenario: The button waits for the figures

- **GIVEN** the Versions and exports report whose export counts are still loading
- **WHEN** an administrator looks at the header
- **THEN** "Download" is disabled
