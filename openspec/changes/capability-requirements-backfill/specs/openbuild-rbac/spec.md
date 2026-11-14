## ADDED Requirements

### Requirement: An owner sees who changed an app's design and when (REQ-BQCV-014)

The app detail page SHALL offer a change history that lists, newest first, every save of the app record and of its versions' manifests, read from the OpenRegister audit trail, with the user, the time and the version changed. Only users with a role on the app SHALL see it.

#### Scenario: An owner reads the design history

- **GIVEN** an app whose editor `sanne` saved the development version's manifest at 10:15
- **WHEN** an owner opens the change history on the app's detail page
- **THEN** the list shows an entry for the development version by `sanne` at 10:15

#### Scenario: A user without a role sees nothing

- **GIVEN** a user with no role on the app
- **WHEN** they request the app's change history
- **THEN** the request is refused
