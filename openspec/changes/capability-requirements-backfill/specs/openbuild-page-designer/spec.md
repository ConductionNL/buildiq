## ADDED Requirements

### Requirement: A list page exports its records to CSV or Excel (REQ-BQCV-004)

A list page in a built app SHALL offer an Export action in its actions menu, on by default. Export SHALL let the user pick Excel (.xlsx) or CSV (.csv) and SHALL download the records of the page's schema in that format through OpenRegister's export. A maker SHALL be able to switch the action off per page.

#### Scenario: A user downloads the records as CSV

- **GIVEN** a published app with a list page bound to the schema permit that holds 25 records
- **WHEN** a user opens the actions menu, chooses Export and picks CSV
- **THEN** the browser downloads a CSV file with one row per permit

#### Scenario: A maker switches export off

- **GIVEN** the same list page
- **WHEN** a maker turns the export action off and saves
- **THEN** the published page shows no Export action

### Requirement: A list page searches its records by keyword (REQ-BQCV-005)

A list page in a built app SHALL show a search box. Typing a keyword SHALL query the records of the page's register and schema through OpenRegister and show only the records that match, and clearing the box SHALL show all records again. A maker SHALL be able to set the search placeholder in the list page editor.

#### Scenario: A user finds a permit by keyword

- **GIVEN** a list page bound to the schema permit with 30 records, two of which mention "Dorpsstraat"
- **WHEN** a user types Dorpsstraat in the search box
- **THEN** the list shows those two records

#### Scenario: Clearing the search shows everything

- **GIVEN** the same search
- **WHEN** the user clears the search box
- **THEN** the list shows all 30 records again

### Requirement: A list page shows records in a table with sorting, filtering and paging (REQ-BQCV-006)

A maker SHALL be able to add a list page, bind it to a register and schema and pick its columns in the list page editor. The built app SHALL show the records in a table with those columns, let the user sort by a column, filter through the page's facets and move between pages of results.

#### Scenario: A maker adds a list of permits

- **GIVEN** an app with the schema permit holding 60 records
- **WHEN** a maker adds a list page bound to permit with the columns title, status and created, and saves
- **THEN** the built app shows a table with those three columns and the first page of permits
- **AND** the table offers paging to the remaining records

#### Scenario: A user sorts and filters

- **GIVEN** the same list page with a status facet
- **WHEN** a user sorts by created and filters on status open
- **THEN** the table shows only open permits, newest or oldest first as chosen

### Requirement: A maker adds a dashboard page (REQ-BQCV-007)

The page designer SHALL let a maker add a page of type dashboard and, in its editor, add widgets (counts, charts, lists) and place them on the layout grid. The built app SHALL render the page with those widgets in that layout.

#### Scenario: A maker adds a dashboard page

- **GIVEN** an app with the schema permit
- **WHEN** a maker adds a dashboard page with a count widget of open permits and a chart widget, places both on the layout and saves
- **THEN** the built app's dashboard page shows the count tile and the chart in the placed positions

### Requirement: A maker adds a files page (REQ-BQCV-008)

The page designer SHALL let a maker add a page of type files and set in its editor which folder of the app it browses. The built app SHALL render the page as a file browser over that folder.

#### Scenario: A maker adds a files page

- **GIVEN** an app with documents stored for it in Files
- **WHEN** a maker adds a files page, picks the app's folder and saves
- **THEN** the built app shows a files page that lists the files and folders in that folder

### Requirement: A maker adds a chat page (REQ-BQCV-009)

The page designer SHALL let a maker add a page of type chat and set in its editor where the conversation comes from and where new messages go. The built app SHALL render the page as a conversation that shows the messages and lets the user post one.

#### Scenario: A maker adds a chat page

- **GIVEN** an app with a messages source
- **WHEN** a maker adds a chat page, sets its conversation source and saves
- **THEN** the built app shows the conversation on that page
- **AND** a user can post a message that then appears in the conversation

### Requirement: A maker adds a log page (REQ-BQCV-010)

The page designer SHALL let a maker add a page of type logs and set in its editor which register and schema its activity comes from. The built app SHALL render the page as a stream of activity entries for those records, newest first.

#### Scenario: A maker adds a log page

- **GIVEN** an app with the schema permit and recent changes to permits
- **WHEN** a maker adds a logs page bound to permit and saves
- **THEN** the built app shows a list of the recent changes to permits, newest first

### Requirement: A maker adds a settings page (REQ-BQCV-011)

The page designer SHALL let a maker add a page of type settings and define its sections and options in its editor. The built app SHALL render the page so its users can change those options, and a saved change SHALL be kept for the next visit.

#### Scenario: A maker adds a settings page

- **GIVEN** an app that needs a default reply period option
- **WHEN** a maker adds a settings page with a section holding the option reply period and saves
- **THEN** the built app's settings page shows the reply period option
- **AND** a user who changes it and saves sees the new value on the next visit

### Requirement: A form shows its confirmation text after a submit (REQ-BQCV-012)

The form-page sub-editor and the registration form editor SHALL let a maker enter a confirmation text (`config.successMessage` on a form page, `confirmationText` on a registration form). After a successful submit the built form SHALL show that text to the person who submitted it, and in public mode it SHALL replace the form.

#### Scenario: A visitor sees the confirmation after submitting

- **GIVEN** a public form page whose success message is "Thank you, we received your request."
- **WHEN** a visitor fills in the required fields and submits
- **THEN** the page shows "Thank you, we received your request."
- **AND** the form is no longer shown

#### Scenario: A registration form carries its own confirmation

- **GIVEN** a registration form with confirmation text "Your application is in."
- **WHEN** the form is served to a portal
- **THEN** the served form carries that confirmation text

### Requirement: A detail page lets users edit record fields in place (REQ-BQCV-013)

A built app's detail page SHALL show the record's data widget as editable unless the maker set the widget to read-only (`editable: false`). A user who may update the record SHALL be able to click a field, change it and save, and the change SHALL be stored on the record in OpenRegister.

#### Scenario: A user corrects a field on the detail page

- **GIVEN** a detail page for schema `permit` with the default data widget, and a user who may update permits
- **WHEN** the user clicks the field `address`, changes it and saves
- **THEN** the permit record holds the new address
- **AND** the detail page shows the new value without a reload

#### Scenario: A read-only widget offers no editing

- **GIVEN** a detail page whose data widget has `editable: false`
- **WHEN** a user clicks a field
- **THEN** no edit control opens
