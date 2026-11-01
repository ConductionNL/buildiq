# Spec: record-history-restore

## Purpose

A maker decides per detail page whether app users see a record's history and
whether they may restore the record to an earlier state. Buildiq writes that
choice into the page's sidebar; nextcloud-vue renders the history and the
restore button; OpenRegister checks the user's rights and saves the earlier
state as a new version, so nothing in the history is lost.

## ADDED Requirements

### Requirement: A maker shows the record history on a detail page (REQ-BQRR-001)

The detail page editor SHALL offer a "Record history" section with the switch
"Show record history". Turning it on SHALL add one sidebar tab with the id
`history` whose widget is `audit`; turning it off SHALL remove that tab and no
other.

#### Scenario: A maker adds the history tab to the permit page

- **GIVEN** a maker editing the detail page `permit-detail` of the app `vergunningen`, with no sidebar set
- **WHEN** they turn on "Show record history" and save
- **THEN** the page config has `sidebar.tabs` with a tab `history` holding the widget `audit` with `allowRestore` false, and an app user sees a "History" tab on a permit

### Requirement: Restore is the maker's choice and off by default (REQ-BQRR-002)

The section SHALL offer "Let users restore an earlier version", available only
while the history is shown and off by default. Turning it on SHALL set
`allowRestore: true` on the `history` tab's audit widget. A page saved before this
requirement, or with the switch off, SHALL give app users no restore button.

#### Scenario: A maker lets users restore permits

- **GIVEN** the `permit-detail` page with the history tab on and restore off
- **WHEN** the maker turns on "Let users restore an earlier version" and publishes
- **THEN** the `history` tab's audit widget carries `allowRestore: true`

### Requirement: An app user restores a record to an earlier state (REQ-BQRR-003)

In a built app whose history tab allows restore, an app user who may edit the
record SHALL be able to pick a history entry, confirm, and restore the record to
the state after that entry. The restore SHALL be saved by OpenRegister as a new
version, the record SHALL show the restored values, and the history SHALL show the
restore as its newest entry without removing the entries after the chosen one.

#### Scenario: A case handler undoes a wrong status change

- **GIVEN** a permit whose status went from "in review" to "rejected" by mistake, on a page that allows restore
- **WHEN** a case handler with edit rights opens the "History" tab, expands the entry that set "in review", chooses "Restore this version" and confirms
- **THEN** the permit shows status "in review", and the history lists the restore first with the "rejected" entry still below it

### Requirement: Only a user who may edit the record can restore it (REQ-BQRR-004)

The restore button SHALL NOT show for a user who may not edit the record. The
server SHALL refuse a restore from a user without update permission, and SHALL
refuse a restore of a record locked by someone else; the app SHALL show the
refusal as a message and change nothing.

#### Scenario: A reader sees the history without the button

- **GIVEN** a user who may read permits but not edit them, on a page that allows restore
- **WHEN** they open a permit's "History" tab and expand an entry
- **THEN** the entry shows its changes and no "Restore this version" button

#### Scenario: A locked record refuses the restore

- **GIVEN** a permit locked by colleague Sanne, on a page that allows restore
- **WHEN** a case handler with edit rights confirms a restore
- **THEN** the app says the record is locked by Sanne and the permit is unchanged
