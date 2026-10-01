# copy-app-page-and-form Specification

## Purpose
A maker starts a variant from something they built: a whole app, one page, or
one registration form. A copy is independent of its source from the moment it
exists.

## Requirements

### Requirement: A maker copies an app (REQ-BQCP-001)

The app detail page SHALL offer "Copy app". It SHALL ask for a
name and a slug and create a new app with its own register, a copy of the
source's schemas and of its current manifest, owned by the maker. Records SHALL
NOT be copied.

#### Scenario: A maker starts a variant of the permit tracker

- **GIVEN** a maker who edits the app "Permit tracker" and is a Nextcloud administrator
- **WHEN** they choose "Copy app", enter "Event permits" and slug `event-permits`, and confirm
- **THEN** the app list shows "Event permits" with the same pages and schemas and no records, and editing it leaves "Permit tracker" unchanged

### Requirement: Copying an app has the gates of cloning a template (REQ-BQCP-002)

`POST /api/applications/{slug}/copy` SHALL require a signed-in Nextcloud
administrator who is an owner or editor of the source app, SHALL be rate
limited like `from-template`, and SHALL refuse a slug that is taken.

@e2e exclude a viewer needs a second Nextcloud user the CI stack does not have; the refusal is asserted in PHPUnit `CopyApplicationTest::testAViewerCannotCopyTheApp`, and the taken slug in `tests/e2e/copy-app-and-page.spec.ts`

#### Scenario: A viewer cannot copy an app

- **GIVEN** a user who can only view "Permit tracker"
- **WHEN** they call `POST /api/applications/permit-tracker/copy`
- **THEN** the response is 403 and no register or app is created

### Requirement: A maker copies a page (REQ-BQCP-003)

Each row of the page list SHALL offer "Copy page", which inserts below it a page
of the same type and configuration with a unique id, a unique route and the
title "Copy of" the source's title.

@e2e exclude a page copy only changes the page list in the designer; asserted in Vitest `tests/services/pageCopy.spec.js`

#### Scenario: A maker copies an intake form page

- **GIVEN** a maker in the page designer with a form page `intake` at route `/intake`
- **WHEN** they choose "Copy page" on it
- **THEN** a page `intake-copy` at `/intake-copy` titled "Copy of Intake" appears below it with the same fields, and no validation error shows

### Requirement: A maker copies a registration form as a draft (REQ-BQCP-004)

Each registration form SHALL offer "Copy form", which saves a draft with the
source's fields, steps, rules and presets for the same type value, named "Copy
of" the source, and never marked as the default.

@e2e exclude a CI stack has no registration form to copy; asserted in Vitest `tests/services/formCopy.spec.js` against the real registrationForm schema

#### Scenario: A maker copies the default citizen form

- **GIVEN** the default `client` form for building permits
- **WHEN** a maker chooses "Copy form" on it
- **THEN** a draft "Copy of client-intake" opens in the editor with the same fields, and the original stays the default
