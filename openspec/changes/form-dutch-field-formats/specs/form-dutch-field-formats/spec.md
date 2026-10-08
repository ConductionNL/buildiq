# Spec: form-dutch-field-formats

## Purpose

A maker picks a Dutch format for a form field, and the form checks it the same way in buildiq and on the portal.

## ADDED Requirements

### Requirement: The form designer offers Dutch formats for a field (REQ-BQDF-001)

The form designer's "Controle" block SHALL offer a "Formaat" select with BSN, IBAN, Kenteken, Telefoonnummer (Nederlands), Telefoonnummer (internationaal), Postcode, KvK-nummer and Vestigingsnummer, and SHALL write the chosen value to the published form field as `format` with the names `bsn`, `iban`, `nl-licence-plate`, `phone-nl`, `phone-international`, `postcode`, `kvk` and `kvk-branch`. When a format is set the designer SHALL NOT accept a pattern on the same field. The schema field editor's "Formaat" SHALL offer the same entries, and a form field on a schema property with a Dutch format SHALL show that format and SHALL NOT drop it.

#### Scenario: a maker adds an IBAN check
- **WHEN** a maker sets "Formaat" to IBAN on field `rekeningnummer` and publishes the form
- **THEN** the published form field carries `"format": "iban"`

#### Scenario: a format and a pattern on one field
- **WHEN** a maker has set "Formaat" to BSN
- **THEN** "Patroon (regex)" is disabled with "Het formaat controleert dit veld al."

#### Scenario: a format from the schema
- **WHEN** a schema property `bsn` has format BSN and a maker adds it to a form
- **THEN** the form field shows BSN, disabled, with "Komt uit het schema"

### Requirement: Buildiq's own forms check the Dutch formats on save (REQ-BQDF-002)

A form published as a buildiq app page SHALL check a field with a Dutch format on the server when it is saved, with the rules portaliq uses, and SHALL refuse a value that fails with the format's message in the user's language. A licence plate SHALL be stored without dashes in capitals and a postcode without a space in capitals.

#### Scenario: a wrong IBAN is refused
- **WHEN** a colleague saves the form with IBAN NL91ABNA0417164301
- **THEN** the save is refused with "Dit IBAN klopt niet. Controleer de cijfers."

#### Scenario: a licence plate with dashes
- **WHEN** a colleague saves "gz-482-k" in a Kenteken field
- **THEN** the stored value is "GZ482K"
