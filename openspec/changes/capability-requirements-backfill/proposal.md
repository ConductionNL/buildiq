---
kind: code
---

# Proposal: capability-requirements-backfill

## Why

The parity matrix (`openspec/parity/capabilities.json`) has rows for behaviour that buildiq ships, but no requirement whose scenarios say that behaviour. A row counts as specified only when its `built.requirement` names a requirement heading that exists. For the rows below, the cited spec dirs were read and none of their scenarios say what the row says, so this change writes the missing requirements into the main specs the rows belong to.

## What changes

One added requirement per row, each with at least one scenario, written from the row's title and `built.evidence`:

- `app-create-from-spreadsheet` (Generate a working app from an uploaded spreadsheet or table): A maker creates a new app from an uploaded spreadsheet (REQ-BQCV-001), in `data-import-wizard`
- `app-publish` (Publish an app so its users can open it, and unpublish it again): An owner publishes and unpublishes an app (REQ-BQCV-002), in `openbuild-runtime`
- `data-relations` (Link one object type to another, for example a request to the person who made it): A maker links one object type to another (REQ-BQCV-003), in `openbuild-schema-designer`
- `data-export-records` (Export an app's records to CSV or Excel): A list page exports its records to CSV or Excel (REQ-BQCV-004), in `openbuild-page-designer`
- `data-search` (Search across an app's records by keyword): A list page searches its records by keyword (REQ-BQCV-005), in `openbuild-page-designer`
- `pg-list-table` (Add a list page that shows records in a table with sorting, filtering and paging): A list page shows records in a table with sorting, filtering and paging (REQ-BQCV-006), in `openbuild-page-designer`
- `pg-dashboard` (Add a dashboard page with tiles, counts and charts): A maker adds a dashboard page (REQ-BQCV-007), in `openbuild-page-designer`
- `pg-files-page` (Add a page that browses the files belonging to the app): A maker adds a files page (REQ-BQCV-008), in `openbuild-page-designer`
- `pg-chat-page` (Add a chat or conversation page to an app): A maker adds a chat page (REQ-BQCV-009), in `openbuild-page-designer`
- `pg-logs-page` (Add a page that shows an activity or log stream): A maker adds a log page (REQ-BQCV-010), in `openbuild-page-designer`
- `pg-settings-page` (Add a settings page where the app's users change the app's options): A maker adds a settings page (REQ-BQCV-011), in `openbuild-page-designer`
- `form-confirmation` (Show a confirmation message or send a confirmation after submitting a form): A form shows its confirmation text after a submit (REQ-BQCV-012), in `openbuild-page-designer`
- `form-inline-edit` (Edit a record's fields in place on its detail page and have the change saved): A detail page lets users edit record fields in place (REQ-BQCV-013), in `openbuild-page-designer`
- `acc-builder-audit` (See an audit trail of who changed an app's design and when): An owner sees who changed an app's design and when (REQ-BQCV-014), in `openbuild-rbac`
- `lc-rollback` (Roll an app back to an earlier version): A maker rolls a version back to an earlier manifest (REQ-BQCV-015), in `application-versions`
- `reuse-hybrid-app-registry` (See every installed app you have customised listed alongside the apps you built, marked as hybrid): Customised installed apps are listed beside built apps and marked hybrid (REQ-BQCV-016), in `unified-app-model`
- `logic-rule-set-export` (Export a rule set as a file): A rule set can be exported as a file (REQ-BQCV-017), in `business-rules-engine`
- `logic-running-case-keeps-version` (Change a workflow while cases already started keep following the version they started with): Changing a published flow leaves running flows on their version (REQ-BQCV-018), in `automation-designer`
- `form-digid-login` (Require citizens to sign in with DigiD, or businesses with eHerkenning, before filling in a form): A form can require sign-in at a trust level (REQ-BQCV-019), in `external-form-provisioning`
- `form-usage-stats` (See how a form is used, such as how many people start, submit or abandon it): A form's use is reported where it is served (REQ-BQCV-020), in `external-form-provisioning`

No code changes. Where the scenarios describe more than is built today (rows rated partial), the tasks below name the work.

## Impact

- Delta specs for `data-import-wizard`, `openbuild-runtime`, `openbuild-schema-designer`, `openbuild-page-designer`, `openbuild-rbac`, `application-versions`, `unified-app-model`, `business-rules-engine`, `automation-designer`, `external-form-provisioning`.
- The rows above record these requirements in `built.requirement`.
