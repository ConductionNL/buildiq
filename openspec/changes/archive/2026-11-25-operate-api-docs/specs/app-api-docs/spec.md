# Spec: app-api-docs

## Purpose

An integrator or a maker reads the API of one built app: which records it holds,
which calls read and change them, and which fields they take. Buildiq cuts
OpenRegister's per-register documents down to the schemas the app version uses,
shows the result inside Nextcloud, and lets it be downloaded as an OpenAPI file.

## ADDED Requirements

### Requirement: An app has an API tab per version (REQ-BQAD-001)

The app detail page SHALL have an "API" tab that shows, for the selected version,
each schema the version's pages use, with its list, read, create, update and
delete calls and its fields with type and rules. It SHALL show the base address
and SHALL say how to sign in with an app password. It SHALL be visible to the
app's owners, editors and viewers.

#### Scenario: An integrator reads the permit API

- **GIVEN** the app `vergunningen`, production version, whose pages use the schemas `permit` and `applicant`
- **WHEN** a viewer of the app opens the "API" tab
- **THEN** the tab lists `permit` and `applicant` with their calls and fields, and the base address of this instance

### Requirement: The document holds only what the app uses (REQ-BQAD-002)

The document SHALL include the schemas bound by the version's pages and widgets,
in the version's register and in the app's data registers, and SHALL leave out
every other schema of those registers. Pages bound to a connector SHALL be listed
as not documented here, with their endpoint path.

#### Scenario: A shared register does not flood the reference

- **GIVEN** `vergunningen` bound to the data register `bag` with 30 schemas, of which its pages use `address`
- **WHEN** a maker opens the "API" tab
- **THEN** the tab shows `address` from `bag` and none of the other 29 schemas

### Requirement: The reference stays inside the instance and downloads as a file (REQ-BQAD-003)

The tab SHALL render the reference without loading any page, script or document
from outside the instance, and "Download OpenAPI file" SHALL save the same cut
document as OpenAPI 3 JSON, titled after the app and carrying the version.

#### Scenario: A maker downloads the file for a supplier

- **GIVEN** the "API" tab of `vergunningen`, production version 1.4.0
- **WHEN** the maker clicks "Download OpenAPI file"
- **THEN** the browser saves a JSON file whose `info.title` is "Vergunningen" and `info.version` is "1.4.0", and no request left the instance while the tab was open
