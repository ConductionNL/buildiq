## MODIFIED Requirements

### Requirement: Schema-set walk over the version's manifest.pages[].config

The system SHALL derive the data sources for a version's insights aggregation as
`(register, schema)` pairs, server-side:

1. Resolve the ApplicationVersion record, its `manifest` payload and its
   `register`.
2. When the version's register exists, the pairs SHALL be every schema that
   register lists, plus every `manifest.pages[].config.schema` whose
   `config.register` equals the version's register. Pages naming other
   registers SHALL be ignored.
3. When the version's register does not exist, the pairs SHALL be every page's
   `(config.register, config.schema)`, skipping entries with a missing value.
4. Pairs SHALL be unique by register and schema ID. Schemas that do not resolve
   SHALL be skipped.

The object count SHALL be the sum over the pairs, each counted in its own
register. The schema IDs of the pairs drive the other KPIs and the activity
chart. The payload SHALL carry `schemaCounts`, the object count per schema, keyed
by schema ID and by schema slug. No pairs is a valid input: all four KPIs return
`0` and `activity` is `[]`.

@e2e exclude pure-backend aggregation, covered by ApplicationInsightsServiceTest; the KPI tiles have no scenario of their own

**ID:** REQ-OBAI-003

#### Scenario: Walk derives unique schema IDs from manifest.pages

- **GIVEN** a manifest whose pages name the same schema twice and a second one,
  all in the version's register
- **WHEN** the service walks the manifest
- **THEN** the resulting schema-set contains two unique schema IDs

#### Scenario: A schema without a page still counts

- **GIVEN** the version's register lists a schema that no page names
- **AND** that schema holds 2 objects in the version's register
- **WHEN** the caller GETs the insights endpoint
- **THEN** `objectCount` is `2`

#### Scenario: A version without a register counts where its pages point

- **GIVEN** the version's register does not exist
- **AND** its pages read schema `hello-message` in register `buildiq`, which
  holds 3 objects
- **WHEN** the caller GETs the insights endpoint
- **THEN** `objectCount` is `3`
- **AND** `schemaCounts["hello-message"]` is `3`

#### Scenario: Empty manifest pages yields zero KPIs and empty activity

- **GIVEN** a manifest with `pages: []` and a version register without schemas
- **WHEN** the caller GETs the insights endpoint
- **THEN** all four KPIs are `0` and `activity` is `[]`
