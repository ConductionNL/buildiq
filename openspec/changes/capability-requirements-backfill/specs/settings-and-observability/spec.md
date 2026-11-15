## ADDED Requirements

### Requirement: An administrator configures the builder on the admin settings page (REQ-BQCV-026)

Buildiq SHALL register a section in the Nextcloud admin settings. On it an administrator SHALL see the builder's current settings and change them, and a change SHALL be saved to the app configuration and used by the builder from then on. Users who are not administrators SHALL NOT be able to change these settings.

#### Scenario: An administrator changes a builder setting

- **GIVEN** an administrator on the Buildiq section of the admin settings
- **WHEN** they change the register the builder uses and save
- **THEN** the new value is stored in the app configuration and the page shows it after a reload

#### Scenario: A user who is not an administrator cannot change them

- **GIVEN** a signed-in user who is not an administrator
- **WHEN** they send a settings update to the builder
- **THEN** the update is refused and no setting changes
