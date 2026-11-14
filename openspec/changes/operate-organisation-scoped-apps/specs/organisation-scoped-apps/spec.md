# Spec: organisation-scoped-apps

## Purpose

Several organisations share one Nextcloud and each runs its own apps. An app
belongs to the organisation that made it; when it is scoped to that organisation,
nobody else sees it, opens it or reads its records. Apps that existed before keep
working for everyone.

## ADDED Requirements

### Requirement: An app has an organisation scope (REQ-BQOS-001)

An app SHALL have an organisation scope, "All organisations" or "This
organisation only". An app without one SHALL behave as "All organisations". When
OpenRegister's multitenancy is on, a new app SHALL start as "This organisation
only", held by its creator's active organisation, and its owner SHALL be able to
change the scope in the app settings. When multitenancy is off, the setting SHALL
NOT be offered.

#### Scenario: A maker of the omgevingsdienst creates an app

- **GIVEN** an instance with multitenancy on and a maker whose active organisation is "Omgevingsdienst Noord"
- **WHEN** the maker creates the app `toezicht`
- **THEN** its settings show "This organisation only", held by "Omgevingsdienst Noord"

#### Scenario: An app from before the change stays shared

- **GIVEN** the app `vergunningen`, created before this change, with no scope
- **WHEN** a user of any organisation opens it
- **THEN** it opens as it did before

### Requirement: A scoped app is invisible to other organisations (REQ-BQOS-002)

A scoped app SHALL be listed, opened, shown in the navigation and returned by the
MCP tools only for members of its organisation. For anyone else every one of those
paths SHALL answer as for an app that does not exist. The check SHALL come before
the role check and SHALL fail closed when the scope cannot be read.

#### Scenario: A link to another organisation's app finds nothing

- **GIVEN** the scoped app `toezicht` of "Omgevingsdienst Noord", and a user of "Gemeente Zuid"
- **WHEN** that user opens `/apps/buildiq/builder/toezicht`
- **THEN** they see the not-found page, `toezicht` is not in their app list or navigation, and the manifest endpoint answers 404

### Requirement: A scoped app's records stay with its organisation (REQ-BQOS-003)

A scoped app's registers, schemas and records SHALL be held by its organisation in
OpenRegister, so members of other organisations cannot read them through
OpenRegister's own API either.

#### Scenario: Records cannot be read around the app

- **GIVEN** the scoped app `toezicht` with records in its production register
- **WHEN** a user of "Gemeente Zuid" asks OpenRegister's objects API for that register's records
- **THEN** OpenRegister returns none of them

### Requirement: Slugs stay unique without leaking (REQ-BQOS-004)

App slugs SHALL stay unique on the whole instance. A slug held by a scoped app of
another organisation SHALL be refused with the same message as any taken slug,
without naming that organisation.

#### Scenario: A taken slug says nothing about who took it

- **GIVEN** the scoped app `toezicht` of "Omgevingsdienst Noord"
- **WHEN** a maker of "Gemeente Zuid" creates an app with the slug `toezicht`
- **THEN** the wizard says "This slug is taken. Choose another." and names no organisation

### Requirement: Installs land in the installer's organisation (REQ-BQOS-005)

Installing an app from the store or from a GitHub repository, and importing an
archive, SHALL create the app held by the installer's active organisation, with the
same default scope as a new app.

#### Scenario: Two organisations install the same template

- **GIVEN** the store template `meldingen`, and makers of "Gemeente Zuid" and "Gemeente Oost"
- **WHEN** each installs it under the slugs `meldingen-zuid` and `meldingen-oost`
- **THEN** each organisation sees only its own app and its own records
