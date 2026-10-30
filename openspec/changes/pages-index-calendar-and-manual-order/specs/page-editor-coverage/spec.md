# page-editor-coverage Specification (delta)

## ADDED Requirements

### Requirement: An index page can offer a calendar view (REQ-PEC-008)

The index page editor SHALL let the maker choose the view modes the page offers (`config.viewModes`, with `table` always included) and, when `calendar` is chosen, the date field (`config.calendar.dateField`, required), an end date field (`endDateField`) and a title field (`titleField`) from the page's schema. A calendar without a date field, or naming a property the schema lacks, SHALL be a designer finding.

**ID:** REQ-PEC-008

#### Scenario: Inspections on a calendar
- **GIVEN** an index page on schema Inspectie with a date property `inspectiedatum`
- **WHEN** the maker ticks Calendar and picks `inspectiedatum` as the date field
- **THEN** the page carries `viewModes: ["table", "calendar"]` and `calendar.dateField: "inspectiedatum"`
- **AND** in the built app the index page offers a Calendar view showing each inspection on its date

#### Scenario: Calendar without a date field
- **WHEN** the maker ticks Calendar and picks no date field
- **THEN** the designer lists a finding that the calendar needs a date field

### Requirement: An index page can let users order rows by dragging (REQ-PEC-009)

The index page editor SHALL have a switch that writes `manualOrder: true` on the page. Switched off, the key SHALL be removed. The order itself belongs to each user and SHALL NOT be stored by buildiq.

**ID:** REQ-PEC-009

#### Scenario: A personal order
- **GIVEN** the maker switches on drag ordering for the Taken page
- **WHEN** a user of the built app drags a task to the top and reloads
- **THEN** that user still sees the task at the top, and another user sees the default order
