# journey-designer Specification

## Purpose
Authors a `journey` in Buildiq: step sequence, branch rules, write mappings
and access mode, over `form` objects authored by the existing form-page editor.
Implements ADR-085 §6. Related: `buildiq-page-designer`, `form-editor-logic`
(which already authors `config.steps[]` inside one form), and the cross-app
delta `hydra/openspec/changes/portaliq-phase-two/specs/forms-and-journeys/spec.md`.

## Requirements

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

### Requirement: A write can repeat per list item (REQ-BQMP-001)

The writes editor SHALL let a maker mark a write as repeating over a list answer
collected earlier in the journey (`forEach`). The mapping SHALL be able to read
the item's own fields and the other answers. The designer SHALL refuse a
`forEach` that names an answer that is not a list or that is first asked after
the write's step.

#### Scenario: A maker repeats the request write per product

- **GIVEN** a maker in the journey designer with a step that asks the product list `producten`
- **WHEN** they mark the write on the last step as repeating over `producten` and map `item.toelichting` to `omschrijving`
- **THEN** the journey saves with `forEach` set to `producten` on that write

### Requirement: Each item goes to its product's target (REQ-BQMP-002)

A repeating write SHALL accept `targetBy` and a `targets` map from each allowed
product value to a register, schema and type value. Saving SHALL be refused when
a product value has no target, or when the mapping names a field that one of the
target schemas lacks, and the refusal SHALL name the product and the field. The
designer SHALL use the run's own validator.

#### Scenario: Two products, two case types

- **GIVEN** a product list whose values are `parkeervergunning` and `afvalcontainer`
- **WHEN** the maker maps them to case type values `PV` and `AC` on schema `zaak`, and saves
- **THEN** the journey saves, and the preview's review shows one request per product with its case type

#### Scenario: A field missing on one target is named

- **GIVEN** a mapping that writes `kenteken` and a target schema for `afvalcontainer` without that field
- **WHEN** the maker saves
- **THEN** the save is refused with "afvalcontainer: kenteken does not exist on the target schema."

### Requirement: The parts can hang under one bundle (REQ-BQMP-003)

The writes editor SHALL allow one bundle write before the repeating write, and
item mappings SHALL be able to reference the bundle's id. The form builder SHALL
offer a "Product list" field with a product column, detail columns and a maximum
number of rows of at most 25.

#### Scenario: A resident requests two products at once

- **GIVEN** a published journey with a bundle write and a repeating write, and a resident who picks a parking permit and a waste container
- **WHEN** the resident submits
- **THEN** one bundle record and two request records exist, each request points at the bundle, and each request is on its own case type

### Requirement: The designer says whether a part starts a process (REQ-BQMP-004)

For each target of a repeating write, the designer SHALL list the flows that start
on creation of a record in that target's register and schema, and SHALL warn
"This product starts no process." when there is none.

#### Scenario: A product without a process is flagged

- **GIVEN** targets where only `parkeervergunning` has a flow on record creation
- **WHEN** the maker opens the writes editor
- **THEN** `afvalcontainer` shows "This product starts no process."
