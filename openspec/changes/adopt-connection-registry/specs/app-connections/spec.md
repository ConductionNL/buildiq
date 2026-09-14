# app-connections Specification Delta

**Status**: proposed
**Scope**: buildiq
**OpenSpec changes**:
- [adopt-connection-registry](../../)

## Purpose

Admins see Buildiq's outside connections on one page, with a status the app can back.

## ADDED Requirements

### Requirement: REQ-BIQ-CONN-001 Buildiq declares its outside connections in one static file

Buildiq SHALL declare its outside connections in `lib/Settings/connections.json` in the shape of hydra connection-registry design D2 (hydra REQ-CONN-001). The file SHALL validate against integriq's `connections.schema.json`, and its `app` SHALL equal the id in `appinfo/info.xml`. It SHALL declare `store`, `github`, `documents` and `rule-webhooks`. `store` SHALL require `registry_url` and SHALL link to the `#section-store` anchor on the Buildiq admin page, and that anchor SHALL exist. `github`, `documents` and `rule-webhooks` SHALL be `reportedOnly`, because no app-config key tells integriq whether they work. Buildiq SHALL NOT declare an LLM connection, because the copilot uses Nextcloud's Task Processing API and Buildiq chooses no provider.

#### Scenario: The declaration names this app and passes integriq's schema
@e2e exclude A static file with no browser surface; tests/Unit/Settings/ConnectionsDeclarationTest.php validates it against the vendored schema, checks the app id, the unique keys and that every settings anchor exists.

- **GIVEN** `lib/Settings/connections.json`
- **WHEN** it is validated against integriq's `connections.schema.json`
- **THEN** it SHALL validate
- **AND** its `app` SHALL equal the id in `appinfo/info.xml`
- **AND** every key SHALL be unique
- **AND** every `settingsUrl` anchor SHALL exist under `src/`

#### Scenario: A saved registry URL reads configured
@e2e tests/e2e/integrations-page.spec.ts

- **GIVEN** integriq has synced Buildiq's declaration
- **WHEN** an admin saves a registry URL in the Buildiq admin settings
- **THEN** the Template store row SHALL read Configured with "Required settings are filled."

### Requirement: REQ-BIQ-CONN-002 A store settings save asks integriq to look again

When `SettingsService::updateSettings()` writes `registry_url`, `registry_register` or `registry_token`, Buildiq SHALL send `ConnectionRefreshRequestedEvent` with app `buildiq` and key `store` (hydra REQ-CONN-004), and SHALL clear the store's report memory. A save that writes none of those keys SHALL send nothing. The event SHALL be named by string and sent only when the class exists. It SHALL NOT change the result of the save.

#### Scenario: Saving the registry URL asks for a refresh
@e2e exclude The event is not observable from a browser; tests/Unit/Service/SettingsServiceConnectionRefreshTest.php asserts the refresh, the written keys and the unchanged result.

- **GIVEN** integriq is installed
- **WHEN** an admin saves `registry_url`
- **THEN** Buildiq SHALL send a refresh request for `store`

#### Scenario: A save that writes no store key sends nothing
@e2e exclude The event is not observable from a browser; tests/Unit/Service/Connection/ConnectionReporterTest.php and tests/Unit/Service/SettingsServiceConnectionRefreshTest.php assert that only store keys refresh.

- **GIVEN** integriq is installed
- **WHEN** an admin saves only `register`, or leaves the token field empty
- **THEN** Buildiq SHALL send no refresh request

### Requirement: REQ-BIQ-CONN-003 Buildiq reports what its connection calls met

Buildiq SHALL report with `ConnectionStatusReportedEvent` what a store search, a GitHub catalogue search, a GitHub push or pull, a document generation call and a rule webhook met, mapped as in the change's design D2. It SHALL report only outcomes about the connection, and SHALL NOT report outcomes about one credential, one app or one request. A message SHALL carry at most the host of an address. It SHALL report a different status only after five minutes have passed since the last report, and the same status only after an hour. Without integriq it SHALL read, store, send and log nothing. A report SHALL never throw into the call it observes or change that call's response.

#### Scenario: A store that cannot be reached reads error
@e2e tests/e2e/integrations-page.spec.ts

- **GIVEN** an admin saved a registry URL where nothing answers
- **WHEN** a user searches the template store
- **THEN** Buildiq SHALL report `store` as `error` with a message naming the host
- **AND** the search SHALL answer with the same outcome as before this change

#### Scenario: A GitHub search without the credential broker reads limited
@e2e exclude The CI instance cannot remove OpenRegister's broker class; tests/Unit/Service/Connection/ConnectionObservationsTest.php and tests/Unit/Controller/ConnectionReportCallersTest.php assert the mapping and the report.

- **GIVEN** OpenRegister's credential broker is not installed
- **WHEN** a catalogue search reaches GitHub
- **THEN** Buildiq SHALL report `github` as `limited`, saying push and pull need the broker

#### Scenario: A document generation route that does not exist reads error
@e2e exclude Generation fires from an automation run as the app owner, which the e2e suite does not drive; tests/Unit/Service/DocumentGenerationConnectionReportTest.php asserts the report and that no call is made.

- **GIVEN** no route answers to the Filinq generate route name
- **WHEN** an automation generates a document
- **THEN** Buildiq SHALL report `documents` as `error` naming the route
- **AND** it SHALL make no HTTP call

#### Scenario: A rule webhook reports only the host
@e2e exclude A webhook needs a receiver outside the instance; tests/Unit/Service/RuleActionDispatcherConnectionReportTest.php and tests/Unit/Service/Connection/ConnectionObservationsTest.php assert the report and that no path, query or user info reaches it.

- **GIVEN** a rule posts to `https://user:secret@hooks.example.nl/path?token=abc`
- **WHEN** the receiver answers HTTP 503
- **THEN** Buildiq SHALL report `rule-webhooks` as `error` naming `hooks.example.nl`
- **AND** the message SHALL NOT contain the path, the query or the user info

#### Scenario: Calls that meet the same thing report once an hour
@e2e exclude Timing is not observable from a browser; tests/Unit/Service/Connection/ConnectionReporterTest.php drives the clock.

- **GIVEN** a store search reported `configured` ten minutes ago
- **WHEN** another search gets an answer
- **THEN** Buildiq SHALL send no report

#### Scenario: Without integriq nothing is sent
@e2e exclude The CI instance installs integriq; tests/Unit/Service/Connection/ConnectionReporterTest.php asserts that nothing is sent, stored or logged when the class is absent.

- **GIVEN** integriq is not installed
- **WHEN** a store search runs or an admin saves `registry_url`
- **THEN** no event SHALL be sent and nothing SHALL be logged
- **AND** no report memory SHALL be written to app config

### Requirement: REQ-BIQ-CONN-004 An admin reads the connections on an Integrations page

Buildiq SHALL render an `index` page at `/settings/integrations` over `integriq/app_connection`, reached from the settings gear and preset to `app` equal to `buildiq` through its menu entry's `query` (hydra REQ-CONN-006). The page and its menu entry SHALL be admin only. The page SHALL require Integriq, and the menu entry SHALL only render when integriq is installed. The status column SHALL name all six statuses, `limited` included. The page SHALL NOT offer a generic Add button. Its Add integration action SHALL open `/apps/integriq/connections?app=buildiq&link=1`.

#### Scenario: The page lists only the rows of buildiq
@e2e tests/e2e/integrations-page.spec.ts

- **GIVEN** buildiq and integriq are installed and integriq has synced the declaration
- **WHEN** an admin opens the Integrations page
- **THEN** the page SHALL list the four declared connections
- **AND** every listed row SHALL have `app` equal to `buildiq`

#### Scenario: Add integration goes to integriq
@e2e tests/e2e/integrations-page.spec.ts

- **GIVEN** the Integrations page
- **WHEN** the admin chooses Add integration
- **THEN** the browser SHALL open integriq's Connections overview with `app=buildiq` and `link=1`

#### Scenario: A connection that works in part reads Limited
@e2e exclude The CI instance ships the credential broker and a store no search reaches, so no row reads limited there; tests/vitest/connectionRegistry.spec.js asserts the label in English and Dutch.

- **GIVEN** a row whose status is `limited`
- **WHEN** the page renders it
- **THEN** the cell SHALL read Limited, or Beperkt on a Dutch instance
