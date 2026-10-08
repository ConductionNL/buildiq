# buildiq-reports

## ADDED Requirements

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
