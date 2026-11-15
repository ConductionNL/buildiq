# Spec: template-catalogue-ui

## Purpose

Adds to the `template-catalogue-ui` capability: the app store shows a
configured shared template catalogue as its own section, and a maker installs
a template from it.

## ADDED Requirements

### Requirement: The app store shows the shared catalogue as a section (REQ-BQRS-001)

The "Templates" view of the app store SHALL render a section "Shared catalogue"
between "Built-in templates" and "Apps on GitHub" when `GET
/api/store/templates` returns an outcome other than `not_configured`. The
section SHALL carry the catalogue's host name as its lead line and SHALL render
one card per returned template with its title, category, use case, description,
version and a primary "Install" button. When the outcome is `not_configured`,
the section SHALL NOT render and the page SHALL issue no further store request.

@e2e exclude no live remote catalogue in CI; covered by Vitest on SharedCatalogueSection with a mocked store response

#### Scenario: A configured catalogue shows its templates

- **GIVEN** an admin connected the catalogue `store.example.nl` in the Buildiq setup
- **AND** the catalogue holds the templates "Vergunningen volgen" and "Meldingen openbare ruimte"
- **WHEN** a maker opens the app store
- **THEN** a section "Shared catalogue" shows between "Built-in templates" and "Apps on GitHub"
- **AND** its lead line reads "From store.example.nl"
- **AND** it shows both templates, each with an "Install" button

#### Scenario: No catalogue configured

- **GIVEN** no catalogue is configured
- **WHEN** a maker opens the app store
- **THEN** no "Shared catalogue" section renders
- **AND** the built-in templates and GitHub apps show as before

### Requirement: The shared catalogue can be searched and filtered (REQ-BQRS-002)

The section SHALL offer a labelled search field "Search the catalogue" that
calls `GET /api/store/templates?q=<term>` after a 300 ms pause in typing. The
category filter of the "Templates" view SHALL narrow the catalogue cards by
their `category`, as it narrows the GitHub cards. An empty result SHALL show
"No templates in the catalogue match your search".

@e2e exclude no live remote catalogue in CI; covered by Vitest on SharedCatalogueSection with a mocked store response

#### Scenario: A maker searches the catalogue

- **GIVEN** a configured catalogue with a template "Vergunningen volgen"
- **WHEN** a maker types "vergunning" in "Search the catalogue"
- **THEN** the page calls the store search with `q=vergunning`
- **AND** the section shows "Vergunningen volgen"

#### Scenario: The category filter narrows the catalogue

- **GIVEN** catalogue templates in the categories "Field work" and "Citizen engagement"
- **WHEN** a maker picks "Field work" in the category filter
- **THEN** the section shows only the field work templates

### Requirement: Store errors stay inside the section (REQ-BQRS-003)

When the store search returns `store_unreachable` or `store_invalid_response`,
the section SHALL show a warning note "The shared catalogue could not be
reached. Try again later." and SHALL NOT show the registry URL, the token or
the underlying error. The built-in and GitHub sections SHALL keep working.

@e2e exclude no live remote catalogue in CI; covered by Vitest on SharedCatalogueSection with a mocked store response

#### Scenario: The catalogue is down

- **GIVEN** a configured catalogue that does not answer
- **WHEN** a maker opens the app store
- **THEN** the "Shared catalogue" section shows the warning note
- **AND** the built-in templates can still be used

### Requirement: A maker installs a catalogue template (REQ-BQRS-004)

"Install" on a catalogue card SHALL open `CloneTemplateDialog` in store mode
with that template, so a valid submit calls `POST
/api/store/templates/{slug}/install` and redirects to the new app. A
`template_not_found` answer SHALL show "This template is no longer in the
catalogue." in the dialog and SHALL refresh the section.

@e2e exclude no live remote catalogue in CI; covered by Vitest on CloneTemplateDialog store mode and PHPUnit on StoreController::install

#### Scenario: A maker installs a template from the catalogue

- **GIVEN** a catalogue card "Vergunningen volgen"
- **WHEN** a maker clicks "Install", names the app "Vergunningen Zuiddrecht" and confirms
- **THEN** the store install endpoint is called for slug `vergunningen-volgen`
- **AND** the maker lands on the new app

#### Scenario: The template was removed from the catalogue

- **GIVEN** a catalogue card whose template was removed from the catalogue since the page loaded
- **WHEN** a maker installs it
- **THEN** the dialog shows "This template is no longer in the catalogue."
- **AND** the section reloads without that card

### Requirement: Admins without a catalogue are pointed to the setup (REQ-BQRS-005)

When the store search returns `not_configured` and the user is a Nextcloud
admin, the built-in section SHALL show one note "Connect a shared template
catalogue in the Buildiq setup." with a button that opens the setup wizard at
the step `store`. Non-admin users SHALL NOT see the note.

@e2e exclude covered by Vitest on TemplateGallery with an admin and a non-admin initial state

#### Scenario: An admin sees the setup hint

- **GIVEN** no catalogue is configured
- **WHEN** an admin opens the app store
- **THEN** the note "Connect a shared template catalogue in the Buildiq setup." shows
- **AND** its button opens the setup wizard at "Remote template store"

#### Scenario: A maker does not see the setup hint

- **GIVEN** no catalogue is configured
- **WHEN** a maker who is not an admin opens the app store
- **THEN** no setup note shows

## REMOVED Requirements

### Requirement: Templates page renders the remote store as its primary surface

**Reason**: The app store was rebuilt with built-in templates, a category
filter, GitHub apps, blocks and forms, and the design board `BqStore` draws
every source as an equal section. A remote store as the primary surface
contradicts both.

**Migration**: REQ-BQRS-001 renders the remote store as the "Shared
catalogue" section. The search and install behaviour moves to REQ-BQRS-002 and
REQ-BQRS-004.
