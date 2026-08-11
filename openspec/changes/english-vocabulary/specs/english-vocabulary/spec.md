## ADDED Requirements

### Requirement: Schema properties SHALL be named in English

Every property declared in openbuild's register schemas SHALL use an English
identifier, at every nesting level, including properties declared under
`items.properties` of an array-typed property.

Where a property already carries an English `title`, the English identifier SHALL be
derived from that `title` rather than translated afresh — the `title` records the
author's intent and is the authoritative evidence.

#### Scenario: A top-level Dutch property is renamed to its own title

- **WHEN** `RuleSet.naam` carries `"title": "Name"`
- **THEN** the property SHALL be named `name`
- **AND** the Dutch word `Naam` SHALL appear only in `l10n/nl.json`

#### Scenario: A nested property inside array items is renamed

- **WHEN** `DecisionTable.regels` is an array whose `items.properties` contain `waardes`
- **THEN** `regels` SHALL be renamed to `rules` and `waardes` to `values`
- **AND** the rename SHALL NOT stop at the top level of `DecisionTable.properties`

#### Scenario: An ambiguous Dutch word is resolved from the schema, not from the language

- **WHEN** a property named `regels` could mean either "rules" or "lines"
- **AND** its `title` reads `Rules` and its sibling `geraaktRegels` reads `Triggered Rules`
- **THEN** the English name SHALL be `rules` / `triggeredRules`
- **AND** the name SHALL NOT be chosen by translating the Dutch word in isolation

#### Scenario: The scan reports the app clean

- **WHEN** the token-aware vocabulary scan is run against openbuild after the rename
- **THEN** it SHALL report 0 Dutch schemas and 0 Dutch properties
- **AND** the scan SHALL have walked nested `properties` and `items.properties`

### Requirement: Every consumer of a renamed property SHALL be updated in the same change

The change SHALL enumerate and update every read site of a renamed property, and SHALL
NOT rely on the test suite to detect a missed one. A property rename in an OpenRegister
schema is silent at runtime: consumers read with a null-coalescing default, so a key
that no longer exists yields null rather than an error.

#### Scenario: A read site is diffed against the new schema

- **WHEN** the rename lands
- **THEN** every read of an old Dutch key in `lib/` and `src/` SHALL have been enumerated
- **AND** each SHALL be updated in the same commit as the schema change

#### Scenario: A property name inside a declarative expression string is updated

- **WHEN** an `x-openregister-calculations` or `x-openregister-aggregations` expression
  references a renamed property by name inside a string
- **THEN** that expression SHALL be updated
- **AND** the change SHALL NOT treat static analysis as sufficient, because a property
  name inside a string is invisible to PHPStan and to `php -l`

#### Scenario: A surviving Dutch key is a defect even when gates pass

- **WHEN** the full test suite and all hydra gates report green
- **AND** a grep for an old Dutch key still returns a hit in the register or in `lib/`
- **THEN** the change SHALL be treated as incomplete

### Requirement: Renaming a stored property SHALL be treated as a data migration

The change SHALL measure the stored-object count before renaming, and SHALL migrate
those objects when the count is non-zero. Objects already persisted in OpenRegister
carry the old property names, so renaming the schema without addressing stored data
orphans that data.

#### Scenario: The stored object count is measured before the rename lands

- **WHEN** the rename is prepared
- **THEN** the number of existing `RuleSet`, `DecisionTable`, `ConditionActionRule`,
  `RuleExecutionLog` and `TestCase` objects SHALL be counted on the target instance
- **AND** the count SHALL be recorded in the change

#### Scenario: Stored objects exist and are migrated

- **WHEN** the measured count is greater than zero
- **THEN** a migration SHALL rewrite the Dutch keys to their English names
- **AND** the migration SHALL be reversible for as long as rollback is required

#### Scenario: No stored objects exist

- **WHEN** the measured count is zero
- **THEN** the migration step MAY be skipped
- **AND** the measurement SHALL still be recorded, so the skip is evidenced rather than assumed

### Requirement: The Dutch user interface SHALL be unchanged by the rename

Renaming identifiers SHALL NOT remove Dutch from what a Dutch-speaking user sees. The
Dutch words move from the schema to the app's translation catalogue.

#### Scenario: A Dutch label survives the rename

- **WHEN** a user with a Dutch locale views a `RuleSet`
- **THEN** the field previously labelled `Naam` SHALL still read `Naam`
- **AND** the label SHALL be served from `l10n/nl.json`

#### Scenario: Translation keys are re-pointed rather than re-extracted

- **WHEN** `l10n/nl.json` is updated
- **THEN** existing translation keys SHALL be re-pointed to the new identifiers
- **AND** `check-l10n` SHALL pass
