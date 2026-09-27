# Spec: app-interface-languages

## Purpose

A maker offers a built app in more than one language. The app's labels are
written in one language and translated per label into others; each app user sees
the app in their own Nextcloud language where a translation exists, and in the
written language where it does not. Translations travel with the app wherever the
app goes.

## ADDED Requirements

### Requirement: A maker sets the app's languages (REQ-BQML-001)

The app settings SHALL let a maker with the owner or editor role set the language
the labels are written in and add or remove extra languages from Nextcloud's
language list. Removing a language SHALL ask for confirmation and SHALL drop its
translations.

#### Scenario: A maker adds English to a Dutch app

- **GIVEN** the app `vergunningen`, written in Dutch
- **WHEN** a maker adds English in the app settings and saves
- **THEN** the version's manifest lists Dutch as the written language and English as an extra language

### Requirement: A maker translates every label in one view (REQ-BQML-002)

The Translations view SHALL list each distinct label text of the version once,
with where it is used, and one column per extra language. It SHALL show how many
labels each language is missing and SHALL filter to missing labels. Labels that a
page takes from a schema property title SHALL be listed as coming from the schema
and SHALL NOT be editable here. Saving SHALL be undoable like any designer edit.

#### Scenario: A maker finds what is left to translate

- **GIVEN** `vergunningen` with 64 label texts, 60 of them translated into English
- **WHEN** a maker opens the Translations view and chooses "Missing only"
- **THEN** the view shows 4 rows and "English: 4 missing"

### Requirement: An app user sees the app in their language (REQ-BQML-003)

A published app SHALL show each label in the user's Nextcloud language when the app
has a translation for it, and SHALL show the written text for any label without
one. The fallback SHALL be per label.

#### Scenario: A case handler with English set sees English labels

- **GIVEN** `vergunningen` written in Dutch with English translations for all labels but the column "Kadastraal perceel"
- **WHEN** a case handler whose Nextcloud language is English opens the permit list
- **THEN** the menu, the page title and the columns show in English, and that one column shows "Kadastraal perceel"

### Requirement: The preview can switch language (REQ-BQML-004)

The live preview SHALL offer the app's languages and SHALL show the previewed page
in the chosen one, without changing the maker's own Nextcloud language.

#### Scenario: A maker checks the English page

- **GIVEN** a maker in the page designer of `vergunningen` with their Nextcloud language Dutch
- **WHEN** they pick English in the preview's language switch
- **THEN** the preview shows the page with English labels, and the designer around it stays Dutch

### Requirement: Translations travel with the app (REQ-BQML-005)

Translations SHALL be part of the version, so promotion and GitHub sync carry them.
An export SHALL write them into the exported app as one Nextcloud `l10n` file per
language.

#### Scenario: An exported app speaks English

- **GIVEN** `vergunningen` with English translations
- **WHEN** a maker exports it and an administrator installs the export on another instance
- **THEN** a user with English as Nextcloud language sees the exported app's labels in English
