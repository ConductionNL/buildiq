# Spec: data-model-diagram

## Purpose

A maker sees an app version's data model as a diagram: each schema a box with
its fields, each relation a line with its name and whether it links one or many.
OpenRegister computes the model from the schema definitions; buildiq draws it in
the schema designer and opens a schema from its box. The same picture is
available as a table for keyboard and screen-reader users.

## ADDED Requirements

### Requirement: The schema list has a diagram view (REQ-BQMD-001)

The schema list of an app version SHALL offer a "Diagram" view next to the list.
It SHALL draw one box per schema in the version's register, with the schema
title and its fields, and one line per relation, labelled with the relation name
and marked `1` or `n`. The view SHALL be kept in the route query so a link opens
it, and it SHALL NOT let the maker create or change a relation by drawing.

#### Scenario: A maker sees how permits, applicants and documents link

- **GIVEN** the app `vergunningen`, development version, with the schemas `permit`, `applicant` and `document`, where `permit` has the relation `applicant` (one) and the relation `documents` (many)
- **WHEN** a maker opens the schema list and chooses "Diagram"
- **THEN** three boxes show with their fields, a line from `permit` to `applicant` marked `1` and a line from `permit` to `document` marked `n`, and the address carries `view=diagram`

#### Scenario: Dragging does not make a relation

- **GIVEN** the diagram of `vergunningen`
- **WHEN** a maker drags from the `applicant` box to the `document` box
- **THEN** no line is added and the schemas are unchanged

### Requirement: Relations made in buildiq are drawn (REQ-BQMD-002)

The diagram SHALL draw every relation a maker made in the relation editor
(`x-openregister-relations` entries) and every field that points at another
schema (`$ref`, `items.$ref`, with its `inversedBy` as the second label on the
same line).

#### Scenario: A relation from the relation editor appears

- **GIVEN** a maker added the relation `applicant` from `permit` to `applicant` with cardinality one in the relation editor and saved
- **WHEN** they open the diagram
- **THEN** a line labelled `applicant` joins the two boxes, marked `1`

### Requirement: A box opens its schema (REQ-BQMD-003)

Clicking a box, or focusing it and pressing Enter, SHALL open that schema in the
designer for the same version. A schema from another register SHALL be drawn at
the edge, and one the maker may not read SHALL carry no title or fields and SHALL
NOT open.

#### Scenario: A maker opens the document schema from the diagram

- **GIVEN** the diagram of `vergunningen`, development version
- **WHEN** a maker tabs to the `document` box and presses Enter
- **THEN** the schema designer opens `document` for the development version

#### Scenario: A schema the maker may not read stays nameless

- **GIVEN** `permit` has a relation to a schema in a register the maker may not read
- **WHEN** the maker opens the diagram
- **THEN** that schema shows as an untitled box at the edge, and clicking it does nothing

### Requirement: The diagram is also a table (REQ-BQMD-004)

Under the canvas the view SHALL list each relation as a row with from, relation,
to, and one or many, and each schema without relations as its own row. Every row
SHALL have an "Open" button reachable by keyboard. Above 50 schemas the boxes
SHALL fold to their titles while the table stays complete.

#### Scenario: A screen-reader user reads the model

- **GIVEN** the diagram of `vergunningen`
- **WHEN** a maker who uses a screen reader tabs past the canvas
- **THEN** they reach a table with the rows "permit, applicant, applicant, one" and "permit, documents, document, many", each with an "Open" button

### Requirement: The diagram covers the app's data registers (REQ-BQMD-005)

The diagram view SHALL offer a register switch listing the version's own
register and each register in the app's `dataRegisters`, and SHALL draw the
chosen register's model.

#### Scenario: A maker looks at a bound data register

- **GIVEN** the app `vergunningen` with the data register `permits-db` bound
- **WHEN** a maker opens the diagram and picks `permits-db` in the register switch
- **THEN** the diagram shows the schemas of `permits-db` and their relations
