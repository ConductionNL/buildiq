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

### Requirement: A field carries required, length, range and pattern rules (REQ-BQCV-021)

The field editor in the schema designer SHALL let a maker mark a field as required and set validation rules on it: a minimum and maximum length for text, a minimum and maximum value for numbers, and a pattern for text. The rules SHALL be saved into the schema property, so every form and API write on that schema is checked against them.

#### Scenario: A maker sets a length and a pattern on a text field

- **GIVEN** a maker editing the field `postcode` in the schema designer
- **WHEN** they mark it required, set the maximum length to 7 and the pattern to `^[1-9][0-9]{3} ?[A-Z]{2}$`, and save
- **THEN** the schema property `postcode` is required and carries `maxLength: 7` and that `pattern`
- **AND** a record saved with the value `12345` is refused with a message that names the field

#### Scenario: A maker sets a range on a number field

- **GIVEN** a number field `age`
- **WHEN** the maker sets the minimum to 0 and the maximum to 120 and saves
- **THEN** a record with `age` 130 is refused and a record with `age` 40 is saved
