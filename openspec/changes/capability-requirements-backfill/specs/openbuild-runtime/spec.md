## ADDED Requirements

### Requirement: An owner publishes and unpublishes an app (REQ-BQCV-002)

The app's detail page SHALL show an owner a Publish action on a draft app and an Unpublish action on a published app. Publish SHALL set the app's status to published, which gives it its navigation entry and opens it to its users. Unpublish SHALL set the status back to draft and remove the navigation entry, without deleting the app, its versions or its data. A user who is not an owner of the app SHALL NOT see either action, and the endpoints SHALL refuse the call with 403.

#### Scenario: An owner publishes an app

- **GIVEN** a draft app the user owns
- **WHEN** the owner chooses Publish on the app's detail page
- **THEN** the app's status is published
- **AND** the app appears in the top navigation for its users

#### Scenario: An owner unpublishes an app

- **GIVEN** a published app the user owns
- **WHEN** the owner chooses Unpublish
- **THEN** the app's status is draft and its navigation entry is gone
- **AND** its versions, pages and records are unchanged

#### Scenario: A viewer cannot publish

- **GIVEN** a user with only the viewer role on a draft app
- **WHEN** they call the publish endpoint
- **THEN** the call is refused with 403 and the status stays draft
