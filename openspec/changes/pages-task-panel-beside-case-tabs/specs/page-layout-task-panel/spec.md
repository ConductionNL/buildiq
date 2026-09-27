# Spec: page-layout-task-panel

## Purpose

An administrator decides per case type where the task list sits on the case
page: in a panel beside the tabs, as a tab of its own, or not at all, and on
which tabs a side panel shows. Buildiq stores and serves that choice through the
`buildiq-page-layout` leaf; the consuming app renders its own task list there.

## ADDED Requirements

### Requirement: A layout declares where the task panel sits (REQ-BQTP-001)

`pageLayout.taskList` SHALL accept a `panel` object with `placement` (one of
`side`, `tab`, `none`), `tabs[]` (tab ids) and `collapsed` (boolean). The
detail-page editor SHALL offer a placement picker and a checklist of the
layout's own tabs.

#### Scenario: An administrator puts the task panel beside two tabs

- **GIVEN** an administrator editing the `bouwvergunning` case layout in the detail-page editor, with tabs `overzicht`, `documenten` and `besluiten`
- **WHEN** they pick "Beside the tabs" and tick `overzicht` and `documenten`, then save
- **THEN** the saved layout carries `taskList.panel` with `placement` `side` and `tabs` `overzicht` and `documenten`

### Requirement: The panel names tabs the layout has (REQ-BQTP-002)

Saving a layout SHALL fail when `taskList.panel.tabs[]` names a tab id the
layout does not declare, and the error SHALL name that id. The check SHALL run
on every save path, not only in the editor.

#### Scenario: A removed tab is caught on save

- **GIVEN** a layout whose task panel shows on tab `besluiten`
- **WHEN** an administrator removes the `besluiten` tab and saves
- **THEN** the save fails and the editor says the task panel names a tab that no longer exists: `besluiten`

### Requirement: The leaf serves the panel with the task list (REQ-BQTP-003)

The `buildiq-page-layout` leaf SHALL serve `taskList.panel` inside `taskList`,
resolved by the same order as the columns: type-value layout, then the
schema-wide layout, then nothing. `placement: none` SHALL be served as declared
and SHALL NOT fall through.

#### Scenario: dossiq asks for the layout of a building permit case

- **GIVEN** a published `bouwvergunning` layout with the task panel beside `overzicht`
- **WHEN** dossiq's case page asks the `buildiq-page-layout` leaf for a case of that type
- **THEN** the response carries `taskList.panel.placement` `side` and `taskList.panel.tabs` `overzicht`

#### Scenario: A case type hides the task list

- **GIVEN** a schema-wide layout with a side panel, and a type layout for `melding` whose panel placement is `none`
- **WHEN** dossiq asks the leaf for a `melding` case
- **THEN** the response carries `placement` `none` and not the schema-wide side panel
