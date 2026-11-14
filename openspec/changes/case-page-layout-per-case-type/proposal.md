---
kind: code
---

## Why

In GZAC an admin decides per case type which tabs, widgets and header fields
a case page shows (`gz/pages/CaseDefinition-Dossierdetails-Tabbladen.md`,
`gz/pages/CaseDefinition-Header.md` in the dossiq competitor analysis,
`concurrentie-analyse/procest/_round2/`). In dossiq a developer declares them
once per page in `src/manifest.json`; every case type gets the same page
(finding B09, M1 11.6 to 11.8). A layout per schema and per type value is a
buildiq concern: buildiq already authors detail pages, sidebar tabs and
widgets in its page designer (`openbuild-page-designer`).

Decision D11 of the analysis asks buildiq to write its half now. dossiq is
the first consumer; any app with a type field on a schema is the next.

## What changes

- A `pageLayout` schema in buildiq's register: a detail-page layout bound to
  a target `register` and `schema`, optionally to one value of a type property
  (`typeProperty`, `typeValue`). It carries `header` (title field, chips),
  `tabs[]` (leaf ids and widget ids in order) and `widgets[]` per slot, in
  the manifest detail-page shape.
- A layout editor: the existing detail-page sub-editor gains an "applies to"
  section, so an admin edits a layout for `dossiq/case` where `caseType =
  bouwvergunning` without touching dossiq's manifest.
- A `data-provider` leaf `buildiq-page-layout` (ADR-066): for a host object it
  returns the most specific layout, type value over schema over none. A
  consumer places the leaf; when buildiq is absent nothing is returned and the
  consumer keeps its manifest layout.

## Discovery wave 3: what CT-6 adds

Round 4 discovery names this change a second time. The depth study
`procest/_round4/discovery/casetype-configurability.md` in
ConductionNL/market-intelligence, written 2026-09-14, groups the gap as
cluster CT-6, "The form is not a form", rows B11, B16, C13 and C16.
`_round4/discovery/build-plan.md` gives CT-6 to buildiq at size L, in wave 3,
and rests it on decision D16. The study's own words on the change: it "does
not exist", it is "named in `openspec/changes/competitor-parity-2026-09/proposal.md:270-272`
and nowhere else". It exists now, and this is its wave 3 scope.

Row C16, "Page layout or widgets per case type", carries matrix rows 11.6,
11.7 and 11.8 and reads `no` for dossiq: "one global layout in
`src/manifest.json`; `ManifestController` adds a nav child per case type".
Valtimo is the only passer the second read found. Its verdict on the row, in
full: "yes `V-ed` Tabbladen (4 tab types), widget tab editor, Header widget,
Dossierlijst columns, all per case type and at runtime", against "no `Z-um`
dashboard cards are per user, case tabs are fixed" for ZAC, "no, there is no
UI" for OpenZaak Catalogi, and "no `C-cc` tabs render conditionally on data,
they are not configured" for OpenCase. The second read's summary line is
"Valtimo alone".

Row B16, "Grouping, order and layout on the form", names the widget grid:
"yes `V-ed` widget wizard: width Klein, Medium, Groot, Xtra Groot, 'columns
as tabs (Kolom 1, add column)', drag order". dossiq reads `no`:
"`propertyDefinition` has no order, group or section; `PropertiesTab.vue:20`
renders in fetch order".

Three capabilities from the second read's uncovered list sit on the same
object, so they are folded in here instead of getting a change of their own:

| id | capability | passer |
|---|---|---|
| D-casetype-21 | task-list columns and search fields configured per case type, "medium, nothing else in the corpus does this" | Valtimo `CaseDefinition-Taken.md`, D-valtimo-30 |
| D-casetype-22 | the document upload form configured per case type, each metadata field visible, read only or defaulted, "medium, it is the intake form for every document on the case" | Valtimo `V-zgwp`, D-valtimo-42 |
| D-casetype-26 | widget display conditions and a high-contrast flag, both as configuration | Valtimo `V-cc` `Condition`, and D-valtimo-38 calls high contrast "the only accessibility affordance in the corpus that is configuration rather than a stylesheet" |

dossiq reads `unread` on all three. The depth study says why: the 54 rated
rows were checked against the dossiq tree, and these twenty-two were not.

## The candidates, and which half is buildiq's

Two of the three fold-ins also reached the consolidated candidate list, and
the consolidator put them in clusters this change does not own. Naming both
halves here stops two lanes building one thing.

| candidate | relevance | driven | cluster and owner | buildiq's half |
|---|---|---|---|---|
| C-tasks-and-phases-33, "Task list columns and search fields per case type" | must | valtimo | 53 "The task as a first-class record", owner dossiq | where the per-case-type column set is stored and how it is served. dossiq keeps the task record and the task list |
| C-access-and-privacy-37, "High contrast marking on a widget" | could | valtimo | 58 "The case page and the list as a place", owner nextcloud-vue | the flag on the stored widget. nextcloud-vue renders it |

The document upload form has no consolidated candidate. D-casetype-22 is its
only citation, and the sweep recorded it as uncovered rather than rating it.

Decision D6 was answered relevance-led, so a `must` enters whatever its
passer count. That admits C-tasks-and-phases-33, a `must` with one driven
passer. Its lane's clause, verbatim: "six identical task rows with an empty
context column is the defect this tab exists to fix, and nothing else in the
corpus configures a task list per case type" (`tasks-and-phases.tsv:27`).

## The decision this rests on

D16, "Is the citizen's form the same definition as the internal one"
(`_round4/discovery/decisions.md`). Ruben took option 1 for the flag and
option 2 for the form: "The field carries whether the citizen may see and
change it, because that is a property of the field. The form carries which
fields appear in which order for which channel, because that is a property
of the form. Decide this before the layout work starts, or the flag lands in
the wrong place twice."

This change is the layout half, and it is now unblocked. The form half is
`forms-per-case-type`. The field flag is a `propertyDefinition` key, and it
belongs to dossiq and openregister.

## What wave 3 adds

- Four tab kinds on `tabs[]`, so a tab holds a leaf, a widget grid, a field
  group or a related list, and an admin orders them per case type.
- A widget grid inside a tab: a width per widget and a drag order, in the
  four-step width vocabulary Valtimo ships.
- Display conditions per widget, evaluated against the host object, and a
  high-contrast flag beside them.
- The header widget as its own slot, with its own fields and chips.
- A task-list column set and search field set per case type, served by the
  same leaf.
- A document upload field set per case type, each metadata field visible,
  read only or defaulted.

Size L, as the build plan rates CT-6.

## How dossiq consumes it

dossiq places the `buildiq-page-layout` leaf once. Its case page asks for the
detail layout, its task list asks for the column set, and its upload dialog
asks for the upload fields, each for the case type on the object in hand.
Nothing in dossiq's `src/manifest.json` changes, and no case-type editor
screen moves: the layout is buildiq's object and dossiq never writes it. On
an instance without buildiq no provider answers and every one of the three
surfaces keeps the manifest shape it has today.

## Out of scope

- `CnDetailPage` accepting a runtime layout over its manifest config. That is
  a `@conduction/nextcloud-vue` change and is listed as a dependency.
- Rights per case type (B13, OpenRegister RBAC).
- Field order, grouping and placeholder on `propertyDefinition`. Rows B11 and
  B16 split there: the field's own keys are dossiq's, in its CT-1 change, and
  the study sizes that half S.
- The task record and the task list component, cluster 53 in dossiq and
  cluster 58 in nextcloud-vue. This change stores and serves the column set,
  it does not render it.
- Rights per audience or per case type, and the portal flag on a field. D16
  puts the flag on the field.
