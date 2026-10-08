# Spec: form-library

## Purpose

A maker saves a form page or a registration form to a form library, finds forms
by category and search, and adds one to an app with the schema properties it
needs. Other organisations pick forms up by file or from GitHub.

## ADDED Requirements

### Requirement: A form can be saved to the library (REQ-BQGL-002)

The form page editor and the registration form editor SHALL offer "Save to form
library". Saving SHALL create a `form-template` object with the form's fields,
steps, logic, presets and confirmation text, the definitions of the schema
properties its fields bind to, a category and a publisher, and SHALL NOT include
object data. A form bound to a property its schema lacks SHALL be refused with
the property named.

#### Scenario: A maker shares a subsidy application form

- **GIVEN** a maker editing the registration form `Aanvraag energiesubsidie`
- **WHEN** they choose "Save to form library", pick category "Citizen engagement" and save
- **THEN** a `form-template` exists with the form and the definitions of its six bound properties, and no records

### Requirement: The app store lists library forms (REQ-BQGL-003)

The app store SHALL offer a "Forms" view next to "Templates" and "Blocks", listing
library forms with search and the category filter, together with forms found on
GitHub under the topic `buildiq-form`.

#### Scenario: A maker finds a form by name

- **GIVEN** three library forms, one named `Aanvraag energiesubsidie`
- **WHEN** a maker opens "Forms" and searches "subsidie"
- **THEN** only that form shows, with its category and publisher

### Requirement: A library form can be added to an app (REQ-BQGL-004)

"Use this form" SHALL let the maker pick an app and version, a target (a new form
page, or a registration form for a schema and type value) and a schema mapping.
It SHALL list the properties the target schema lacks and SHALL offer to add them
from the stored definitions. Nothing SHALL be written before the maker confirms.

#### Scenario: A second app reuses the form

- **GIVEN** app `subsidies` whose schema `aanvraag` lacks `verbruikKwh`
- **WHEN** a maker uses the form `Aanvraag energiesubsidie` as a registration form on `aanvraag`, and confirms "Add these properties"
- **THEN** `aanvraag` gains `verbruikKwh`, and the app has a registration form with the library form's fields

### Requirement: Forms travel between organisations (REQ-BQGL-005)

A library form SHALL export to a JSON file with the envelope `kind:
"form-template"` and a schema version, and SHALL import from such a file after
validating the envelope and the form config. The app store SHALL find forms in
GitHub repositories with the topic `buildiq-form` and a `form.json` at the root,
and installing one SHALL create a local library form.

#### Scenario: Another municipality imports the form

- **GIVEN** a maker in another organisation with the exported file of `Aanvraag energiesubsidie`
- **WHEN** they import it in the "Forms" view
- **THEN** the form appears in their library with its category and publisher

#### Scenario: A broken file is refused

- **GIVEN** a JSON file whose `kind` is `component-block`
- **WHEN** a maker imports it as a form
- **THEN** the import is refused with "This file is not a form export." and nothing is created
