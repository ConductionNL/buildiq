# Spec: page-action-authoring

## Purpose

A maker adds buttons to index and detail pages that do real work: change fields
on a record, move it to another status, start a flow, open a form, go to a page
or export. Buildiq authors the typed actions the nextcloud-vue renderer already
runs.

## ADDED Requirements

### Requirement: The action editor authors typed actions (REQ-BQAB-001)

The action editor SHALL let a maker pick what a button does from: change fields
on this record, start a flow, open a form, go to a page, open a link, and
export. It SHALL store each as the matching manifest action `type` with only the
keys that type allows, and SHALL refuse to save an action that fails the
manifest schema.

#### Scenario: A maker adds a button that starts a flow

- **GIVEN** a maker editing the `Requests` detail page of an app whose declared flows include "Intake check"
- **WHEN** they add a button "Run intake check", pick "Start a flow", choose "Intake check" and its start node, and save
- **THEN** the page's `headerActions` holds an action of type `run-node` with that flow and node, and the published page's Actions menu offers "Run intake check"

### Requirement: A detail page has buttons (REQ-BQAB-002)

The detail page editor SHALL offer a Buttons section that writes
`config.headerActions` with the same action editor as index pages.

#### Scenario: An app user runs the action from a record

- **GIVEN** the published `Requests` detail page with the "Run intake check" button
- **WHEN** an app user opens a request and chooses "Run intake check"
- **THEN** the flow node runs for that request and the page shows the result message

### Requirement: Status buttons follow the lifecycle (REQ-BQAB-003)

The detail page editor SHALL offer Status buttons that write
`config.lifecycleActions`, showing the transitions OpenRegister allows by
default. The action editor SHALL refuse an action that patches the lifecycle
field directly.

#### Scenario: A maker adds status buttons

- **GIVEN** a `Request` schema with the lifecycle submitted, approved, rejected
- **WHEN** the maker switches on Status buttons for the `status` field and publishes
- **THEN** an app user on a submitted request sees "Approve" and "Reject", and on an approved request sees neither

#### Scenario: A field patch cannot bypass the lifecycle

- **WHEN** a maker adds a "change fields" action that sets `status` to `approved`
- **THEN** the editor refuses it and says to use a status button instead

### Requirement: Old actions keep working (REQ-BQAB-004)

An action saved with the old `target` values SHALL open in the editor as its
typed equivalent and SHALL be saved in the typed form the next time the maker
saves the page.

#### Scenario: An app built last month opens cleanly

- **GIVEN** an index page whose action has `target: navigate` and a page route
- **WHEN** the maker opens the page in the page designer
- **THEN** the action shows as "Go to a page" with that page selected

### Requirement: A button can depend on the record (REQ-BQAB-005)

An action SHALL accept a `visibleWhen` condition authored with the form editor's
predicate builder, and the published page SHALL show the button only when the
condition holds for the record.

#### Scenario: Only submitted requests offer the check

- **GIVEN** "Run intake check" is visible when `status` equals `submitted`
- **WHEN** an app user opens an approved request
- **THEN** the Actions menu does not offer "Run intake check"
