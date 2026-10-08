---
kind: code
---

# Proposal: operate-report-download

## Why

**buildiq matrix, row `ops-reports`**, "See reports across all apps: apps by
status and type, versions and exports.", rated `partial`, `built.state`
`specified`. Drawn on board **BqRapportages** (canvas part 2,
`QAAxpcsFKBCvDUGbQbwtCa`). Added by decision 99 (8 Oct).

The reports are built, and the board's figures are all there: the
Applications report and the Versions and exports report, declarative pages in
`src/manifest.json` (`Reports` at line 635, `ApplicationsReport` at 667,
`DeliveryReport` at 904), now specified after the fact in main spec
`buildiq-reports`.

One thing on the board is missing: the "Downloaden" button in the report
header. A functional administrator who has to hand these numbers to a
manager or an audit has to copy them off the screen. `CnDashboardPage`, which
renders both reports, has no download (its only `Download` icon is the
install-the-missing-app call to action).

## What changes

- Each report page gets a "Download" button in its header that saves the
  figures of every widget on the page as one CSV file.
- The CSV has one row per figure: widget title, label (the group, or the
  count's own label), value. The table widgets add their visible rows.
- The file name is `buildiq-<report>-<yyyy-mm-dd>.csv`.

## Rows covered

- `ops-reports`

## Dependencies

- nextcloud-vue: `CnDashboardPage` needs a header download that serialises
  its widgets' resolved data (design D1). Until that lands, buildiq cannot
  offer the button on a declarative page.

## Out of scope

- PDF or spreadsheet formats, scheduled reports, mailing a report.
- New figures. The board's figures are all built.
