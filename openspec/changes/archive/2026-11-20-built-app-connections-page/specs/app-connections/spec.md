## ADDED Requirements

### Requirement: REQ-BIQ-CONN-005 Every app built in buildiq has a connections page with health

Buildiq SHALL show, for every application built in it, a Connections page on the application detail. The page SHALL list the app's sources (the integriq sources its manifest binds as data sources) and its integrations (integriq `app_connection` rows whose `app` is the application's slug), one row each, with the kind, the name, a health status and the time the status was last checked. The status SHALL use integriq's connection statuses (`configured`, `limited`, `unconfigured`, `simulated`, `disabled`, `unavailable`, `error`), and SHALL read Not checked when integriq holds no result for that row. The page SHALL list only that application's connections. Only users who may edit the application, and admins, SHALL reach the page; the server SHALL check this before it reads any connection.

#### Scenario: The page lists the app's sources and integrations
- **GIVEN** an app "Vergunningen" built in buildiq whose manifest binds two integriq sources, and integriq holds one `app_connection` row with `app` equal to the app's slug
- **WHEN** a maker of the app opens its Connections page
- **THEN** the page SHALL list three rows: two sources and one integration
- **AND** each row SHALL show its status and the time it was last checked

#### Scenario: A source in error reads Error
- **GIVEN** integriq last checked one of the app's sources and recorded the status `error` with the message "401 Unauthorized"
- **WHEN** the maker opens the Connections page
- **THEN** that row SHALL read Error with the message and the time of the check

#### Scenario: A row without a check reads Not checked
- **GIVEN** a source the app binds that integriq has never checked
- **WHEN** the page renders it
- **THEN** the status cell SHALL read Not checked, or Niet gecontroleerd on a Dutch instance

#### Scenario: Another app's connections stay off the page
- **GIVEN** two apps built in buildiq, each with its own sources
- **WHEN** a maker opens the Connections page of the first app
- **THEN** no row of the second app SHALL be listed

#### Scenario: A user who may not edit the app is refused
- **GIVEN** a user who is neither an admin nor allowed to edit the app
- **WHEN** the user requests the app's connections
- **THEN** the server SHALL answer 403 before it reads any connection

#### Scenario: Without integriq the sources are listed without health
- **GIVEN** integriq is not installed
- **WHEN** a maker opens the Connections page
- **THEN** the page SHALL list the sources the manifest names with the status Not checked
- **AND** the page SHALL say that health checks need integriq
