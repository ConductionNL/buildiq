# Spec: page-custom-code

## Purpose

An app owner writes a component in HTML, CSS and JavaScript, or a JavaScript
expression for a column or a form field, when the built-in parts are not enough.
The code runs in a sandboxed frame that cannot reach the user's session, and it
talks to the page only through a small set of messages.

## ADDED Requirements

### Requirement: An owner writes a code component (REQ-BQCC-001)

The custom page editor, and the widget editors of dashboard and detail pages,
SHALL offer "Write your own" with editors for HTML, CSS and JavaScript and a
checklist of inputs (`object`, `rows`, `route`, `user`). Saving SHALL write
`config.code` and SHALL NOT keep `config.component` beside it.

#### Scenario: An owner builds a floor plan widget

- **GIVEN** an app owner editing the detail page of `gebouw`, with code on pages allowed
- **WHEN** they add a widget, pick "Write your own", write HTML and JavaScript that draws the rooms from `object.ruimtes`, tick input `object`, and save
- **THEN** the page config holds the widget with `code` and inputs `object`, and the preview draws the rooms for a sample record

### Requirement: An owner writes expressions for columns and form fields (REQ-BQCC-002)

The column builder SHALL offer an "fx" toggle that replaces the property with a
JavaScript expression over `row`, `user` and `route`. The form field editor SHALL
offer the same for the default value and for visibility over `values`. Saving
SHALL write `columns[].expression`, `expressionDefault` or
`visibleWhen.expression`.

#### Scenario: A column shows a computed total

- **GIVEN** an app owner editing the index page of `bestelling`
- **WHEN** they add a column, switch on "fx" and enter `row.aantal * row.prijs`
- **THEN** the preview shows that column with the product of the two fields for each row

### Requirement: Maker code runs only in the sandbox (REQ-BQCC-003)

Code components and expressions SHALL run only in an iframe with
`sandbox="allow-scripts"` and without `allow-same-origin`, whose document buildiq
serves from a route that checks the caller may use the app and answers with a
policy that allows no network requests. No maker code SHALL run in the host page
or on the server.

#### Scenario: Code cannot call the API as the user

- **GIVEN** a code component whose script requests `/ocs/v2.php/cloud/user`
- **WHEN** an app user opens the page
- **THEN** the request is blocked by the frame's policy and carries no session, and the page keeps working

### Requirement: The frame asks, the page decides (REQ-BQCC-004)

A code component SHALL change anything only by posting `navigate`, `notice` or
`setField`. The page SHALL check each message against the app's manifest and the
record's schema, SHALL perform `setField` with the user's own session, and SHALL
drop any other message.

#### Scenario: A field change respects the user's rights

- **GIVEN** an app user with read-only access to `gebouw` 7, on a page with a code component that posts `setField` for `status`
- **WHEN** the component posts the message
- **THEN** OpenRegister refuses the write, the record is unchanged, and the page shows the refusal

### Requirement: Only owners write code, and only when allowed (REQ-BQCC-005)

Saving a manifest that adds or changes `config.code` or any expression key SHALL
require the `owners` role on the app and the instance setting "Allow code on
pages", checked on the server. The copilot and agents SHALL NOT write those keys.
With the setting off, code components SHALL render a placeholder and expressions
SHALL NOT run.

#### Scenario: An editor cannot add code

- **GIVEN** a colleague with the `editors` role on app `permits`
- **WHEN** they save a page with a new code component
- **THEN** the save is refused with "Only app owners can add code to a page." and the stored manifest is unchanged

#### Scenario: The copilot does not write code

- **GIVEN** a maker asking the copilot for "a custom chart in JavaScript"
- **WHEN** the plan is validated
- **THEN** the plan holds no `code` or expression key, and the review says code is written by app owners
