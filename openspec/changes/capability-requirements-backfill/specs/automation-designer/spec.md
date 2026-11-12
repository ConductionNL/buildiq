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
