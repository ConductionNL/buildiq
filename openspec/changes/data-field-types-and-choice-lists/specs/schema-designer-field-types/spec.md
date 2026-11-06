# Spec: schema-designer-field-types

## Purpose

A maker picks a field's type by what it holds (a date, a choice, a file, a
user) and a choice field can draw its options from one shared list. The schema
designer writes the JSON Schema shapes OpenRegister validates and nextcloud-vue
renders, and never drops a property key it does not edit.

## ADDED Requirements

### Requirement: The designer offers named field types (REQ-BQFT-001)

The schema designer's type picker SHALL offer text, long text, number, whole
number, yes or no, date, date and time, choice, several choices, file, several
files, user, several users, relation, list and group. Each SHALL be stored as
the shape in the design's type table, and a stored property SHALL open with the
named type that matches it.

#### Scenario: A maker adds a date field

- **GIVEN** a maker editing the `Permit` schema in the schema designer
- **WHEN** they add a field `decidedOn`, pick "Date" and save
- **THEN** the stored property is `type: string` with `format: date`, and the published form shows a date picker for it

#### Scenario: An existing date field opens as a date

- **GIVEN** a schema whose property `startsOn` is `type: string, format: date`
- **WHEN** the maker opens it in the schema designer
- **THEN** the field shows the type "Date", not "string" with a free-text format

### Requirement: A choice field has inline or shared options (REQ-BQFT-002)

A choice field SHALL take either inline options (an ordered list of value and
label, stored as `enum` with labels) or a shared list (an OpenRegister concept
scheme, stored as `conceptScheme`). The designer SHALL NOT store both on one
property.

#### Scenario: A maker points a field at the shared list of municipalities

- **GIVEN** a maker adding a choice field `municipality` in the schema designer and a concept scheme `gemeenten` in OpenRegister
- **WHEN** they choose "Use a shared list", pick `gemeenten` and save
- **THEN** the property carries `conceptScheme: gemeenten` and no `enum`, and a concept added to `gemeenten` later is accepted without saving the schema again

### Requirement: A user field points at a Nextcloud user (REQ-BQFT-003)

A user field SHALL be stored with `referenceType: nextcloud-user` and hold a
Nextcloud user id; "several users" SHALL store an array of them.

#### Scenario: A record is assigned to a colleague

- **GIVEN** a maker added a user field `assignee` to the `Task` schema and published the app
- **WHEN** an app user creates a task and picks a colleague in `assignee`
- **THEN** the saved record holds that colleague's user id and the form showed a searchable list of users

### Requirement: A save keeps the keys the designer does not edit (REQ-BQFT-004)

Saving a schema from the designer SHALL keep every property key the field
editor does not own, such as `enum`, `title` and `x-` keys set by an import or
another tool. Changing a field's type SHALL still clear the previous type's own
slots.

#### Scenario: An imported enum survives a save

- **GIVEN** a schema imported with a property `status` carrying an `enum` of three values
- **WHEN** a maker opens the schema in the designer, renames another field and saves
- **THEN** `status` still carries its three values
