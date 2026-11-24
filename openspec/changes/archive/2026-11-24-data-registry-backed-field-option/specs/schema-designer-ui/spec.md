# schema-designer-ui

## ADDED Requirements

### Requirement: A maker declares that a field takes its value from a registry (REQ-BQRF-001)

The field editor SHALL show a section "Value source" for fields of type
`string` and `object`, with the choices "Typed in" and "From a registry". It
MUST NOT add a registry-specific entry to the type picker. "From a registry"
SHALL show a provider picker filled from integriq's `GET /api/property-sources`
(the provider label, with the identifier it keys on as a secondary line) and a
mode choice "Look it up every time" (`live`) or "Use it as a starting value"
(`default`). Saving SHALL write `x-openregister-property-source` with
`provider`, `mode` and `config` on the property. Choosing "Typed in" SHALL
remove the key.

@e2e tests/e2e/schema-designer-registry-field.spec.ts

#### Scenario: A KvK-backed field is declared

- **GIVEN** integriq is installed and publishes the provider `kvk` labelled "KvK organisation", keyed on `kvkNummer`
- **WHEN** a maker edits the field `kvkNummer` of type `string`, chooses "From a registry", picks "KvK organisation" and "Look it up every time", and saves the schema
- **THEN** the property `kvkNummer` carries `x-openregister-property-source` with `provider` "kvk", `mode` "live" and `config` `{}`

#### Scenario: The section is not offered for a number

- **WHEN** a maker edits a field of type `integer`
- **THEN** no "Value source" section is shown

#### Scenario: Going back to typed in removes the declaration

- **GIVEN** a field with a `kvk` declaration
- **WHEN** the maker chooses "Typed in" and saves
- **THEN** the property no longer carries `x-openregister-property-source`

### Requirement: An existing declaration survives a session without integriq (REQ-BQRF-002)

When integriq is not installed or `GET /api/property-sources` fails, the
section SHALL show the note "Registry lookups need the integriq app." For a
field that already carries a declaration, the section SHALL show its provider
and mode as read-only text, and a save MUST write the declaration back
unchanged, including its `config`. The editor MUST resolve integriq's path
through `fleetAppPath('integriq', ...)` and MUST NOT hard-code an app id.

@e2e exclude disabling integriq on the shared e2e instance breaks other suites; covered by Vitest on FieldEditor with the provider call failing

#### Scenario: A declaration is kept when integriq is missing

- **GIVEN** a field `adres` with `x-openregister-property-source` `{"provider": "bag", "mode": "default", "config": {"x": 1}}`
- **AND** integriq is not installed
- **WHEN** a maker changes the description of another field and saves
- **THEN** `adres` still carries the declaration with `config` `{"x": 1}`

### Requirement: The fields table shows the registry source and a refusal lands on its field (REQ-BQRF-003)

The "Format" column of the fields table SHALL show "{provider label}, live" or
"{provider label}, starting value" for a field with a declaration. When
OpenRegister refuses the schema save over a property-source declaration, the
designer SHALL show the refusal on the field it names and open that field.

@e2e tests/e2e/schema-designer-registry-field.spec.ts

#### Scenario: The table shows the source

- **GIVEN** the field `kvkNummer` reads `kvk` live
- **WHEN** a maker opens the schema
- **THEN** its "Format" cell reads "KvK organisation, live"

#### Scenario: An unknown provider is refused on its field

- **GIVEN** a field whose declaration names a provider OpenRegister refuses
- **WHEN** the maker saves the schema
- **THEN** the refusal message shows on that field and the field is opened
- **AND** the schema is not reported as saved
