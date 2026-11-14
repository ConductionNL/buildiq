## ADDED Requirements

### Requirement: Application schema carries an optional dataRegisters binding array

The system SHALL extend the `Application` schema in
`lib/Settings/openbuild_register.json` (via a `register.d/` fragment per
ADR-037 — see design.md Decision 2) with an optional `dataRegisters` array
property, sibling to `baseRef`, of shape:

```json
{
  "dataRegisters": {
    "type": "array",
    "default": [],
    "items": {
      "type": "object",
      "required": ["register"],
      "additionalProperties": false,
      "properties": {
        "register": { "type": "string", "pattern": "^[a-z0-9][a-z0-9-]*[a-z0-9]$" },
        "label": { "type": "string" }
      }
    }
  }
}
```

Each entry names a shared, non-versioned OpenRegister register (by slug)
that the `Application` binds to alongside its own per-version register
(`ApplicationVersion.register`, ADR-002). `dataRegisters` is declarative
schema metadata only (ADR-031) — this requirement introduces no service
class, no route, and no validation that a referenced register slug exists in
OpenRegister at save time (see design.md Non-Goals). This is the schema
surface SPECTR-NEXTCLOUD-PLAN.md §4.2 / hydra ADR-050 decision #2 locks for
Buildiq; the consumers (builder pickers, promotion-skip regression
coverage, export inclusion, designer UI) are out of scope for this
requirement and land in the follower spec `data-registers-runtime`.

**ID:** REQ-OBA-010

#### Scenario: Schema declares dataRegisters after install

- **WHEN** the Buildiq app is installed (or upgraded) and its repair step
  runs
- **THEN** the `Application` schema in the `buildiq` register exposes the
  `dataRegisters` property with the shape above
- **AND** the property is omittable — existing Application objects created
  before this change remain schema-valid

#### Scenario: Saving an Application with a dataRegisters binding round-trips

- **WHEN** a client PUTs an Application via OR REST with
  `dataRegisters = [{ "register": "spectr", "label": "Spectr market intelligence data" }]`
- **THEN** OR persists the object and a subsequent GET returns the same
  `dataRegisters` array byte-for-byte

#### Scenario: Saving an Application with multiple bindings round-trips

- **WHEN** a client PUTs an Application via OR REST with
  `dataRegisters = [{ "register": "brp-personen" }, { "register": "bag-adressen", "label": "BAG adressen" }]`
- **THEN** OR persists the object and a subsequent GET returns both entries,
  in order, byte-for-byte
- **AND** the entry without a `label` round-trips with no `label` key present

#### Scenario: Application without dataRegisters is still accepted

- **WHEN** a client saves an Application that omits `dataRegisters` entirely
- **THEN** OR persists the object and returns 2xx — the property is optional
  and defaults to an empty array on read

#### Scenario: Binding missing the required register slug is rejected

- **WHEN** a client PUTs an Application with
  `dataRegisters = [{ "label": "No slug given" }]` (missing the required
  `register` key)
- **THEN** OR rejects the save with a 4xx citing the missing `register`
  property under the failing array index

#### Scenario: Binding with an unrecognised sub-property is rejected

- **WHEN** a client PUTs an Application with
  `dataRegisters = [{ "register": "spectr", "readOnly": true }]` (note the
  unknown `readOnly` key)
- **THEN** OR rejects the save with a 4xx citing the unknown property under
  the failing `dataRegisters` array entry

#### Scenario: Register slug pattern is enforced

- **WHEN** a client PUTs an Application with
  `dataRegisters = [{ "register": "Not_A-Valid-Slug!" }]`
- **THEN** OR rejects the save with a 4xx citing the `register` value as not
  matching the kebab-case pattern
