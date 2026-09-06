# business-rules-engine Specification

## Purpose

buildiq stops shipping its own DMN rule matcher and consumes OpenRegister's.
Every decision a shipped table reaches is unchanged, proven by a 72-case parity
run against the real shared evaluator. What buildiq keeps is what is actually
buildiq's: resolving payload paths into named values, and the structural overlap
analysis behind the editor's warnings.

## ADDED Requirements

### Requirement: REQ-BRE-020 Rule matching is delegated, translation is not

The system SHALL delegate rule matching and hit-policy selection to
OpenRegister's shared decision-table evaluator, and SHALL NOT keep its own
implementation of either.

The system SHALL translate between buildiq's decision-table dialect and the
shared evaluator's, because the two are not the same dialect. A decision that a
table reaches today MUST be the decision it reaches after delegation.

#### Scenario: The table is translated into the shared shape

- **GIVEN** a table with `inputColumns`, `outputColumns` and rules carrying `conditions` and `values`
- **WHEN** it is evaluated
- **THEN** the shared evaluator receives positional `inputs`, `outputs` and
  `inputEntries`, and each rule's id is its own index

#### Scenario: buildiq's cell spellings are translated

- **GIVEN** cells reading `==7`, `18..65`, `*` and `any`
- **THEN** the shared evaluator receives `=7`, `[18..65]`, `-` and `-`
- **AND** an untranslated `*` would be read as a literal and stop matching, which
  is a changed decision with no error raised

#### Scenario: A decision is unchanged by delegation

- **GIVEN** the shipped loan-eligibility table under each hit policy
- **WHEN** the same payload is evaluated before and after delegation
- **THEN** the output columns and the triggered rule MUST be identical

### Requirement: REQ-BRE-021 An unresolved input column falls through, it does not refuse

When an input column's `expressionPath` does not resolve in the payload, the
system SHALL fail only the conditions that test that column, and SHALL let the
table fall through to its remaining rules and then to the output columns'
declared defaults.

The system MUST NOT refuse the whole table. The shared evaluator coerces every
declared input before matching and raises `type_mismatch` on a null, so the
unresolved columns and the rules testing them SHALL be withheld from it.

#### Scenario: A payload missing a column still decides

- **GIVEN** a table whose rules test `creditScore`, and a payload carrying no `creditScore`
- **WHEN** it is evaluated
- **THEN** those rules MUST NOT match, and the table MUST return its catch-all
  rule or its declared defaults rather than raising

#### Scenario: A wildcard rule survives an unresolved column

- **GIVEN** the same payload and a catch-all rule with no conditions
- **THEN** the catch-all MUST still match

### Requirement: REQ-BRE-022 An unrecognised hit policy still decides

The system SHALL treat a hit policy it does not recognise as `first`, as it did
before delegation, and SHALL translate `rule-order` to the shared evaluator's
`FIRST`.

The shared evaluator refuses a policy it does not implement, so without this a
table with a typo'd policy would begin erroring instead of deciding.

#### Scenario: A typo'd policy decides as first

- **GIVEN** a table declaring `hitPolicy: output-order`
- **THEN** it MUST be evaluated as `FIRST`
