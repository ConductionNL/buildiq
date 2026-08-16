# journey-designer Delta: journey-designer

**Status**: in-progress
**Scope**: openbuild
**OpenSpec changes**:

- [journey-designer](../../)

## Purpose

Authors a `journey` in OpenBuild: step sequence, branch rules, write mappings
and access mode, over `form` objects authored by the existing form-page editor.
Implements ADR-085 §6. Related: `openbuild-page-designer`, `form-editor-logic`
(which already authors `config.steps[]` inside one form), and the cross-app
delta `hydra/openspec/changes/portaliq-phase-two/specs/forms-and-journeys/spec.md`.

## ADDED Requirements

### Requirement: The designer MUST author steps over existing form objects

A `JourneyDesigner` SHALL author an ordered `steps[]` of `form`, `review` and
`confirmation`, where a `form` step references an existing `form` object. Field
authoring SHALL remain in the form editor.

#### Scenario: A form step references rather than duplicates

- **GIVEN** a journey step referencing a form object
- **WHEN** the journey is saved
- **THEN** it stores the reference, and no field definition is copied into the
  journey

#### Scenario: Reordering steps reorders the journey

- **GIVEN** three steps
- **WHEN** the second is moved first
- **THEN** the stored order reflects it and step contents are unchanged

#### Scenario: A referenced form that no longer exists is reported

- **GIVEN** a journey referencing a deleted form
- **WHEN** the designer opens it
- **THEN** the broken reference is shown, and the journey is not silently
  rendered without that step

### Requirement: Branch rules MUST be authored only from the shared operator set

The branch editor SHALL author `next` rules as `visibleWhen` predicates,
offering only the operators the canonical schema accepts.

#### Scenario: Only schema operators are offered

- **GIVEN** the branch editor
- **WHEN** the operator list is inspected
- **THEN** it contains exactly the canonical set and nothing else

#### Scenario: A rule referencing an unanswered-by-then field is refused

- **GIVEN** a rule on step two referencing a field first asked on step three
- **WHEN** the journey is saved
- **THEN** it is refused, naming the field and the step ordering

### Requirement: Write mappings MUST be validated against the target schema at author time

The designer SHALL validate every `writes[]` entry: the target register and
schema must exist, and every mapped property must exist on that schema. It
SHALL use the same validator the run path uses.

#### Scenario: An unknown target property is caught before publish

- **GIVEN** a mapping naming a property absent from the target schema
- **WHEN** the journey is saved
- **THEN** it is refused, naming the property and the schema

#### Scenario: Author-time and submit-time validation cannot diverge

- **GIVEN** one invalid mapping
- **WHEN** it is validated by the designer and by the run path
- **THEN** both produce the same rejection, because both call the same
  validator
- **AND** this is asserted, since a designer that validates differently is a
  source of false confidence rather than a safety net

#### Scenario: A dependent write is authorable

- **GIVEN** a step writing an organisation and then a contact referencing it
- **WHEN** the mapping references the preceding write's id
- **THEN** it validates

### Requirement: Authoring a journey MUST be separately authorised

Creating or editing a journey SHALL require its own authorisation, distinct
from general page-designer access.

#### Scenario: A page-designer user cannot author writes

- **GIVEN** a user permitted to use the Page Designer but not to author
  journeys
- **WHEN** they attempt to save a journey
- **THEN** it is refused
- **AND** the reason names journey authoring specifically — a journey can cause
  writes into any register its mapping targets

### Requirement: The preview MUST mount the real renderer

The live preview SHALL mount `CnJourney` with the in-flight journey, without
saving.

#### Scenario: The preview is the renderer, not an approximation

- **GIVEN** a journey previewed in the designer and rendered in the portal
- **WHEN** the two are compared
- **THEN** the step sequence, fields, validation and branch behaviour match

#### Scenario: Previewing writes nothing

- **GIVEN** a preview of a journey whose step declares `writes[]`
- **WHEN** the preview is advanced through that step
- **THEN** no object is created in any target register
