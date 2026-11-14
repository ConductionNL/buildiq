## ADDED Requirements

### Requirement: Changing a published flow leaves running flows on their version (REQ-BQCV-018)

A flow bound to an app SHALL NOT be edited in place once published. Editing it SHALL create a draft version, and publishing that draft SHALL make it the version new runs start on, while every run already started SHALL keep running on the version it started with until it ends.

#### Scenario: A changed flow does not move a running case

- **GIVEN** a published flow at version 3 with one run in progress
- **WHEN** a maker opens the flow from the automations page, creates a draft, changes a step and publishes it
- **THEN** the flow's current version is 4 and a new run starts on version 4
- **AND** the run that was in progress continues on version 3

#### Scenario: A published flow is not edited in place

- **GIVEN** a published flow
- **WHEN** a maker opens it in the flow editor
- **THEN** the editor refuses direct edits and offers to create a draft version

### Requirement: A status change starts an automation (REQ-BQCV-028)

An automation whose trigger is a lifecycle transition SHALL run when a record of the bound schema moves through that transition, and only then. Its condition SHALL be evaluated against the record after the transition, and its actions SHALL run when the condition holds.

#### Scenario: A record moves to the status the automation listens for

- **GIVEN** an automation on the schema `request` with the trigger "transition submitted to in review" and the action "send a notification to the group reviewers"
- **WHEN** a request moves from submitted to in review
- **THEN** the automation runs once and the reviewers get the notification

#### Scenario: Another transition does not start it

- **WHEN** a request moves from in review to closed
- **THEN** the automation does not run
