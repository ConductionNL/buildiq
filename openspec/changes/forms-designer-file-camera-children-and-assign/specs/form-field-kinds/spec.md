# form-field-kinds Specification (delta)

## Purpose

The form field kinds and behaviours buildiq's form designer offers beyond plain inputs, each written in the manifest keys the nextcloud-vue form renderer reads.

## ADDED Requirements

### Requirement: A maker adds a file field with its limits (REQ-BQFK-001)

The form designer SHALL offer field type `file`. A file field SHALL have settings for the allowed file types (`accept`), the maximum size (`maxSize`, entered in MB and stored in bytes as nextcloud-vue `CnFileField` reads it) and whether several files are allowed (`multiple`), written on the field in the manifest.

#### Scenario: Receipts as PDF or image
- **GIVEN** a maker adds a field "Bonnetjes" of type file
- **WHEN** she allows PDF and images, sets 10 MB and allows several files
- **THEN** the field in the manifest carries `type: "file"`, `accept`, `maxSize: 10485760` (bytes) and `multiple: true`

### Requirement: A file field can open the camera (REQ-BQFK-002)

A file field SHALL have a camera setting with the values off, back camera (`capture: "environment"`) and front camera (`capture: "user"`). Off SHALL remove `capture` from the field.

#### Scenario: A photo of the damage
- **GIVEN** a file field "Foto van de schade"
- **WHEN** the maker sets the camera to back camera
- **THEN** the field carries `capture: "environment"`, and on a phone the built form opens the camera

### Requirement: A maker adds a table of child records (REQ-BQFK-003)

The form designer SHALL offer field type `child-records` with the child schema, the child field that references the parent (`parentField`) and the columns shown. A child schema without a property referencing the form's schema SHALL be marked as unusable and SHALL NOT be saved as the field's schema.

#### Scenario: Order lines in the order form
- **GIVEN** an Order form and an OrderLine schema with a reference `order`
- **WHEN** the maker adds a child records field on OrderLine with parent field `order` and columns product and quantity
- **THEN** the field carries `type: "child-records"`, `schema`, `parentField: "order"` and the two columns

#### Scenario: A schema that does not point back
- **WHEN** the maker picks a child schema with no reference to Order
- **THEN** the designer says the schema has no field pointing at the order, and the field is not saved with it

### Requirement: A field is filled in from another answer (REQ-BQFK-004)

Every field SHALL have a "Fill in from another answer" editor that writes `assign` rules: each a condition on another field, using the shared `visibleWhen` operators, and a value that is a literal, `@answer.<field>` or a sentinel token. A rule SHALL NOT read the field it sets.

#### Scenario: The currency follows the country
- **GIVEN** fields `country` and `currency`
- **WHEN** the maker adds to `currency` the rule "when country equals NL, set EUR"
- **THEN** `currency` carries `assign: [{ when: { field: "country", op: "eq", value: "NL" }, value: "EUR" }]`

#### Scenario: A rule on itself
- **WHEN** the maker writes a rule on `currency` whose condition reads `currency`
- **THEN** the designer refuses the rule and says a field cannot fill itself
