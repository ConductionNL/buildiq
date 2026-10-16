---
kind: code
depends_on: [case-page-layout-per-case-type]
---

# Proposal: pages-task-panel-beside-case-tabs

## Why

dossiq matrix, row `3.18`, "Task panel beside the case tabs", rated `partial`
for dossiq and provided by buildiq. The row is built by buildiq's
`case-page-layout-per-case-type` (buildiq#844): a case type already declares
its task-list columns and search fields, and the `buildiq-page-layout` leaf
serves them (`lib/Service/PageLayoutPresenter.php:67`). What the layout cannot
say is where the task list sits. Three competitors put it beside the tabs, as a
panel that stays in view while the handler moves between tabs:

- Valtimo/GZAC: "gzac@13.4.1
  frontend/projects/valtimo/case/src/lib/components/case-detail/case-detail.component.html:73,112-125
  as-split right area with valtimo-case-detail-task-list beside the tabs;
  enableTaskPanel in frontend/src/environments/environment.ts:244 | round 2
  drive: procest/valtimo/round2/case-detail-anatomy.md:30 "the **Taken** panel,
  only on tabs whose "Takenlijst zichtbaar" is on"".
- xxllnc Zaaksysteem: "zaaksysteem@b7354824
  backend/perl-api/client/src/intern/views/case/zsCaseView/zsCasePhaseView/zsCasePhaseSidebar/zsCaseTasks/template.html:1-5
  and index.js:40 the phase sidebar embeds the task list (external-components
  phase/:n/tasks) beside the phase form".
- ZAC: "zac@1.0.316 src/main/app/src/app/zaken/zaak-view/zaak-view.component.html:174
  zac-zaak-taken card in the right-hand column beside the case details tabs".

GZAC also decides per tab whether the panel shows. That is configuration, and
configuration of the case page is buildiq's (the dossiq competitor-parity
umbrella gives the case page layout to buildiq, and dossiq renders it). No
tender, featureRequest or roadmap row carries this row; the three competitor
cells are the demand.

## What changes

- `pageLayout.taskList` gains a `panel` object: `placement` (`side`, `tab` or
  `none`), `tabs[]` (the tab ids the side panel shows on; empty means every
  tab) and `collapsed` (whether it opens folded).
- The leaf serves `taskList.panel` with the rest of `taskList`, by the same
  resolution order as the columns: type value, then schema-wide, then nothing.
- The task-list panel in the detail-page editor (task 6.5 of
  `case-page-layout-per-case-type`, not built yet) gets a placement picker and a
  tab checklist, fed from the layout's own `tabs[]`.
- Saving refuses a `tabs[]` entry that names a tab the layout does not have.

## Rows this closes

| matrix | row id | row name | own rating | what is missing |
|---|---|---|---|---|
| dossiq | 3.18 | Task panel beside the case tabs | partial | where the task list sits (beside the tabs or as a tab) and on which tabs it shows, per case type |

## Existing work it builds on

- `case-page-layout-per-case-type` (open, 12 of 28 tasks): `pageLayout`
  (`lib/Settings/register.d/51-page-layouts.json`), `taskList` with columns and
  search fields (REQ-OBPL-008), the `buildiq-page-layout` leaf
  (`lib/Integration/PageLayoutLeafProvider.php`) and its presenter
  (`lib/Service/PageLayoutPresenter.php`). Its task 6.5 builds the task-list
  panel this change extends.
- `screen-overrides-as-a-patch-with-fall-through` (open): an override patches
  the layout by key, so `taskList.panel` is patched like any other part.

## Sibling halves

- dossiq: its case detail page reads `taskList.panel` from the leaf and places
  its own task list beside the tabs, on the tabs named, folded or open. With
  nothing served it keeps today's behaviour. That consumer task belongs in
  dossiq's `competitor-parity-2026-09` umbrella; this change does not write it.

## Out of scope

- The task list itself, its data and its actions. Buildiq never reads or writes
  a task object (REQ-OBPL-008).
- Any page other than a detail page with a published `pageLayout`.
