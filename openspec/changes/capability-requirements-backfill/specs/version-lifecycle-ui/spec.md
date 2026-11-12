## ADDED Requirements

### Requirement: A change goes live only when an owner releases it (REQ-BQCV-027)

Editors SHALL make changes on a draft version. A draft SHALL reach the app's users only when an owner of the app releases it to production; the release action SHALL be offered to owners only and SHALL be refused on the server for anyone else. Releasing is the approval: until an owner releases the draft, users keep seeing the production version.

#### Scenario: An editor's change waits for the owner

- **GIVEN** an editor who changed a page on the draft version 1.3.0 of an app whose production version is 1.2.0
- **WHEN** they open the version history
- **THEN** no release action is shown to them
- **AND** users of the app still see version 1.2.0

#### Scenario: The owner releases the draft

- **GIVEN** the same draft
- **WHEN** an owner releases 1.3.0
- **THEN** 1.3.0 becomes the production version and users see the changed page

#### Scenario: A release by someone else is refused

- **WHEN** an editor calls the release endpoint for 1.3.0 directly
- **THEN** the call is refused and 1.2.0 stays in production
