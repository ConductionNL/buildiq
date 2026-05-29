# Notifications

## ADDED Requirements

### Requirement: Export job outcome notifications

The OpenBuild `exportJob` schema SHALL declare `x-openregister-notifications`
rules that notify the job's manage-ACL holders when an export job
transitions to `succeeded` or `failed`, with bilingual (nl/en) subjects.

#### Scenario: Export job succeeds

- **WHEN** an `exportJob` object transitions through the `succeeded` action
- **THEN** the OpenRegister notification engine dispatches an `nc-notification` to the object's manage-ACL holders with a nl/en subject referencing `{{applicationVersion}}`

#### Scenario: Export job fails

- **WHEN** an `exportJob` object transitions through the `failed` action
- **THEN** the engine dispatches an `nc-notification` to the object's manage-ACL holders with a nl/en subject referencing `{{applicationVersion}}` and `{{errorMessage}}`

### Requirement: Application version lifecycle notifications

The OpenBuild `ApplicationVersion` schema SHALL declare
`x-openregister-notifications` rules that notify the version's manage-ACL
holders when it transitions to `published` or `archived`, with bilingual
(nl/en) subjects.

#### Scenario: Version published

- **WHEN** an `ApplicationVersion` object transitions through the `published` action
- **THEN** the engine dispatches an `nc-notification` to the object's manage-ACL holders with a nl/en subject referencing `{{semver}}` and `{{name}}`

#### Scenario: Version archived

- **WHEN** an `ApplicationVersion` object transitions through the `archived` action
- **THEN** the engine dispatches an `nc-notification` to the object's manage-ACL holders with a nl/en subject referencing `{{semver}}` and `{{name}}`
