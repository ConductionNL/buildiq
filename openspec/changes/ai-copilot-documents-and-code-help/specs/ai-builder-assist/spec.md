# Spec: ai-builder-assist

## Purpose

A maker writing a FEEL condition, a decision table row, a field mapping or a
webhook payload can ask the AI to write it from a plain request, or to explain
what is already there. The proposal is checked before the maker sees it, and it
only lands when the maker accepts it and saves the editor.

## ADDED Requirements

### Requirement: Expression editors offer an Ask AI button (REQ-BQCH-001)

The condition field of the condition-action rule editor, the rule rows of the
decision table editor, the field mapping of a record step and the payload
template of a webhook step SHALL each show a button named "Ask AI". The button
SHALL render nothing while the copilot health probe reports the copilot
unavailable.

#### Scenario: A maker sees the button next to a rule condition

- **GIVEN** a maker editing a condition-action rule, on an instance with a text2text provider
- **WHEN** the condition-action rule editor opens
- **THEN** an "Ask AI" button sits beside the "Condition (FEEL)" field

#### Scenario: No provider, no button

- **GIVEN** an instance where `GET /api/copilot/health` answers unavailable
- **WHEN** a maker opens the decision table editor
- **THEN** no "Ask AI" button renders and the editor works as before

### Requirement: The assist proposes a checked value (REQ-BQCH-002)

`POST /api/copilot/assist` in `write` mode SHALL return a proposal only after the
server has checked it: a FEEL condition SHALL parse with `FeelParser`, each cell
of a decision row SHALL pass the cell grammar, a field mapping SHALL be JSON
whose keys are properties of the target schema, and a payload template SHALL be
JSON. After one failed repair round-trip the endpoint SHALL answer 422
`assist_invalid` with the checker's message.

#### Scenario: A maker gets a condition from a plain request

- **GIVEN** a maker in the condition-action rule editor of a rule set whose payload has `applicant.age`
- **WHEN** they click "Ask AI", type "applicants younger than 18" and send it
- **THEN** the dialog shows the proposal `applicant.age < 18` with a one-line explanation, and Accept and Reject buttons

#### Scenario: A mapping to a property that does not exist is refused

- **GIVEN** a record step whose target schema `permit` has no property `ownerEmail`
- **WHEN** the model answers twice with a mapping that writes `ownerEmail`
- **THEN** the endpoint answers 422 `assist_invalid` naming `ownerEmail`, and the dialog shows no proposal

### Requirement: The assist explains the current value (REQ-BQCH-003)

In `explain` mode the endpoint SHALL return a plain-language description of the
`current` value and SHALL NOT return a replacement value.

#### Scenario: A maker asks what a decision row does

- **GIVEN** a decision table row with cells `>= 18` and `in ('NL', 'BE')` and decision `approve`
- **WHEN** the maker picks "Explain" in the Ask AI dialog
- **THEN** the dialog shows a sentence such as "Approve applicants aged 18 or over from the Netherlands or Belgium" and offers no Accept button

### Requirement: The assist writes nothing and respects app access (REQ-BQCH-004)

The endpoint SHALL require an `appSlug` and SHALL answer 403 when the caller is
not an owner or editor of that app. It SHALL resolve a target schema only among
that app's schemas. It SHALL NOT save a rule, table or automation. Accepting a
proposal SHALL change only the editor's staged value.

#### Scenario: Reject leaves the rule untouched

- **GIVEN** a maker who received a proposal for a rule condition
- **WHEN** they click Reject and then close the editor without saving
- **THEN** the stored rule keeps its previous condition

#### Scenario: A viewer cannot use the assist

- **GIVEN** a colleague with only viewer access to app `permits`
- **WHEN** they call `POST /api/copilot/assist` with `appSlug` `permits`
- **THEN** the endpoint answers 403 and no text task is scheduled
