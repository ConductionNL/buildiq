---
kind: code
---

# Proposal: data-restore-record-version

## Why

buildiq matrix, row `data-restore-record`, "Restore a record to an earlier
state.", rated `no`. No competitor rated `yes` and no tender, featureRequest or
roadmap row carries it. The lane builds it because the row is in buildiq's core
area (data) and because the gap is narrow: a record's history is shown and
cannot be restored. Two competitors rated `partial`, which marks where the bar
sits:

- Mendix: "the platform-supported Audit Trail module creates a log history of
  changes to objects (what, when, by whom); restoring an earlier state is not
  part of it and would need custom logic"
  (https://docs.mendix.com/appstore/modules/audit-trail/).
- Microsoft Power Apps: "Keep deleted Dataverse records for 1 to 90 days and
  restore deleted records; returning a live record to an earlier field state is
  not described, audit history only shows changes"
  (https://learn.microsoft.com/en-us/power-platform/admin/restore-deleted-table-records).

NocoBase, Budibase and Appsmith are rated `no`.

What buildiq does today, per the matrix `built.evidence`: "every hit is about
ApplicationVersion rollback (ApplicationVersionsTab.vue), theme preview revert
(ThemePickerDialog.vue), or Copilot manifest-snapshot rollback
(CopilotService.php), none about restoring a data RECORD's earlier state.
nextcloud-vue's CnAuditTrailTab.vue (the record history viewer) has no
restore/revert action in its template".

The server half already exists. OpenRegister routes
`POST /api/objects/{register}/{schema}/{id}/revert`
(`openregister/appinfo/routes.php:1453`), which takes a `datetime`, an
`auditTrailId` or a `version` (`lib/Controller/RevertController.php`), checks the
caller's update permission and the record's lock
(`lib/Service/Object/RevertHandler.php:148-167`), and saves the earlier state as
a new version. Its `content-versioning` spec (status done) requires that
"Rollback MUST create a new version (not delete intermediate versions)".
nextcloud-vue 2.57.1 even has the store action (`revertObject()`,
`src/store/plugins/lifecycle.js:156`). What is missing is a button in a built
app, and a way for the maker to decide whether app users get it.

## What changes

- The detail page editor gets a "Record history" section: show the history tab,
  and a second switch, "Let users restore an earlier version", off by default.
- Buildiq writes that choice into the page's sidebar as a history tab whose
  audit widget carries `allowRestore: true`.
- In a built app, an app user who may edit the record opens its history, picks
  an entry and restores the record to the state after that entry, after
  confirming. The history then shows the restore as the newest entry.
- A user who may not edit the record never sees the button, and a locked record
  refuses with the name of whoever holds the lock.

## Rows this closes

| matrix | row id | row name | own rating | what is missing |
|-|-|-|-|-|
| buildiq | data-restore-record | Restore a record to an earlier state. | no | a restore action on a record's history in built apps, and the maker's switch for it |

## Existing work it builds on

- `openspec/specs/openbuild-page-designer` (REQ-OBPD-005): the detail page
  editor with its sidebar shapes and tab list, which this change extends.
- `openspec/specs/page-designer-ui`: "Per-page-type config sub-editors emit
  validated slices"; the new section emits the sidebar slice.
- openregister `content-versioning` spec (done), requirement "The system MUST
  support version rollback", and the `revert` route that implements it.

## Sibling halves

- nextcloud-vue owes the button. `CnAuditTrailTab`
  (`src/components/CnObjectSidebar/CnAuditTrailTab.vue` at 2.57.1) lists entries
  with an expandable detail and no action. It needs an `allowRestore` prop,
  default `false`, that adds "Restore this version" to an expanded entry, asks
  for confirmation, calls the revert route with that entry's `auditTrailId`, and
  reloads the record and the list. The prop reaches it through a sidebar tab
  widget's `props`, which `CnObjectSidebar` already passes on
  (`widgetBindings()`, line 940; the `audit` widget key at line 253). No open
  nextcloud-vue change was found for it.
- openregister: none required. One thing to confirm there: `RevertHandler`
  saves through `ObjectEntityMapper::update()` (lines 177-181), not the object
  save path, so whether the restored state is validated against the current
  schema is OpenRegister's to state. Also, its `content-versioning` spec names
  the route `/api/revert/{register}/{schema}/{id}` while `routes.php:1453` serves
  `/api/objects/{register}/{schema}/{id}/revert`.

## Out of scope

- Restoring a deleted record (OpenRegister's `deleted#restore` covers it).
- Restoring a single field, or merging two versions.
- Restoring buildiq's own app versions, which `ApplicationVersionsTab.vue`
  already does.
