# Tasks: operate-report-download

- [ ] **T01** (nextcloud-vue, cross-repo): add opt-in `config.download` and `config.downloadName` to `CnDashboardPage`: a header button "Download" that is disabled until every widget settled and saves the CSV of design D2 from the widgets' resolved data. Vitest for counts, donut groups, table rows, a failed widget and the disabled state. Release, then raise buildiq's `@conduction/nextcloud-vue` within the current major.
- [ ] **T02**: In `src/manifest.json` set `"download": true` and `"downloadName": "buildiq-applications"` on `ApplicationsReport`, and `"download": true`, `"downloadName": "buildiq-delivery"` on `DeliveryReport` (REQ-BQREP-004). Verify: the manifest validator test passes.
- [ ] **T03**: Write `tests/e2e/reports-download.spec.ts`: open each report, click "Download", read the saved file and assert the header row and one count row (REQ-BQREP-004).
- [ ] **T04**: Set row `ops-reports` to `building` in `openspec/parity/capabilities.json` when T02 is merged, and to `built` with `buildiq: yes` when T03 is green.
