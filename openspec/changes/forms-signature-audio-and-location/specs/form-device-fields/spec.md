# Spec: form-device-fields

## Purpose

A maker adds a signature, an audio recording or the user's position to a form,
and shows the user's position on a map page. The values land on the record, and
the page asks the browser only for the devices the app uses.

## ADDED Requirements

### Requirement: Forms offer signature, audio and location fields (REQ-BQSA-001)

The form field builder SHALL offer the field types signature, audio recording and
location, with their options: signature modes, a maximum length for audio, and
target latitude and longitude fields for location with an optional map pick.

#### Scenario: A maker adds a signature to an inspection form

- **GIVEN** a maker editing the form page `Opleveringsrapport` bound to schema `oplevering`
- **WHEN** they add a field of type signature named `handtekening`, allow drawing only, and save
- **THEN** the form config holds `handtekening` with type `signature` and typed signing off

### Requirement: The value lands in a matching property (REQ-BQSA-002)

A signature or audio field SHALL require a `file` property on the form's target
schema, and a location field SHALL require two number properties. When they are
missing the builder SHALL offer to add them to the schema. Manifest validation
SHALL refuse a field whose target properties are missing or of the wrong type,
and an audio length whose clip would pass the inline file cap.

#### Scenario: The builder adds the file property

- **GIVEN** schema `oplevering` without a `handtekening` property
- **WHEN** the maker adds the signature field and chooses "Add the property to oplevering"
- **THEN** `oplevering` gains a `file` property `handtekening`, and validation passes

#### Scenario: A clip that is too long is refused

- **GIVEN** an audio field
- **WHEN** the maker sets the maximum length above what fits in the inline file cap
- **THEN** validation marks the field and names the longest length that fits

### Requirement: Map pages show the user's position (REQ-BQSA-003)

The map page editor SHALL offer "Show my position" and "Center on my position
when the page opens", written as `config.showUserLocation` and
`config.centerOnUser`.

#### Scenario: A field worker sees where they are

- **GIVEN** a map page `Meldingen in de buurt` with "Show my position" on
- **WHEN** an app user opens it on a phone and allows location
- **THEN** the map shows their position among the report markers

### Requirement: The page allows only the devices the app uses (REQ-BQSA-004)

Saving a manifest SHALL store on the app version the device features it uses:
`microphone` for an audio field and `geolocation` for a location field or a map
showing the user's position. The page that serves the built app SHALL allow
`'self'` for exactly those features and SHALL leave the default policy for all
others. Audio and location fields SHALL be refused on forms in `public` mode.

#### Scenario: An app without audio does not get the microphone

- **GIVEN** an app whose forms use a location field and no audio field
- **WHEN** an app user opens the app
- **THEN** the page policy allows geolocation for the page itself and still refuses the microphone

#### Scenario: A public form cannot ask for a position

- **GIVEN** a form page in `public` mode
- **WHEN** the maker adds a location field
- **THEN** validation refuses it and says public forms belong to the portal
