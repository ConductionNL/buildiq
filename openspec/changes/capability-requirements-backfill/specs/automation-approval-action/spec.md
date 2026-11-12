## ADDED Requirements

### Requirement: An approval step holds the run until it is approved (REQ-BQCV-029)

An approval action in an automation SHALL stop the run at that step and put a pending approval in front of the named person or group. The run SHALL continue with the on-approve actions when one of them approves, and with the on-reject actions when one of them rejects. Nothing after the approval step SHALL run while the approval is pending.

#### Scenario: The run waits for the group and continues on approval

- **GIVEN** an automation on `request` whose actions are "ask the group managers for approval", then on approve "set status to approved"
- **WHEN** a request triggers the automation
- **THEN** the managers see a pending approval and the request's status is not changed yet
- **WHEN** a manager approves
- **THEN** the request's status is set to approved

#### Scenario: A rejection takes the other path

- **WHEN** a manager rejects the pending approval
- **THEN** the on-reject actions run and the on-approve actions do not
