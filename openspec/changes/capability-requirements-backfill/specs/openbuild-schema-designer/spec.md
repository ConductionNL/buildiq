## ADDED Requirements

### Requirement: A maker links one object type to another (REQ-BQCV-003)

The schema designer SHALL let a maker give a field the relation type, pick the target schema and choose one or many. The saved schema SHALL carry the relation on that property, and the Relations section SHALL list it with its target and cardinality. OpenRegister SHALL resolve the relation when the record is read, so a built app shows the linked record.

#### Scenario: A request points at the person who made it

- **GIVEN** an app with the schemas request and person
- **WHEN** a maker adds the field applicant to request with type relation, target person and cardinality one, and saves
- **THEN** the saved request schema marks applicant as a relation to person with cardinality one
- **AND** the Relations section lists applicant with target person

#### Scenario: A many relation keeps a list

- **GIVEN** the schema project
- **WHEN** a maker adds the field members as a relation to person with cardinality many and saves
- **THEN** the saved schema stores members as a list of person references
