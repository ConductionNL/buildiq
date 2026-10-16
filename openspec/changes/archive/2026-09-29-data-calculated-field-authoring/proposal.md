# data-calculated-field-authoring

## Why

Matrix row `data-computed-field` in the buildiq matrix ("Add a field whose value
is calculated from other fields") is `building`: buildiq shows calculations but
cannot author one. `src/components/schema-editor/CalculationEditor.vue:1-30` is a
v1 stub that prints an existing `x-openregister-calculations` block read-only and
says "You cannot edit calculations here yet". The schema designer passes it
`staged.calculations` (`src/views/SchemaDesigner.vue:162`, `:1003`, `:1054`) and
writes that block back unchanged.

Four competitors rate the row yes (NocoBase, Budibase, Mendix, Power Apps) and
the row is in buildiq's core area (data). The build-all pass of 2026-09-28
decided `build`.

The engine already exists. OpenRegister accepts a `calculation` key on a single
schema property (`lib/Service/Calculation/PropertyCalculations.php`,
`PROPERTY_KEY = 'calculation'`), validates it on save and refuses the save with a
code when it is wrong (`SchemaMapper::validateCalculationsAnnotation`), publishes
its operators (`GET /api/schemas/calculation-operators`) and evaluates an unsaved
declaration against a sample (`POST /api/schemas/calculation-evaluate`). What is
missing is the buildiq half: a way for a maker to write one.

## What changes

- The Calculations section of the schema designer becomes an editor: add a
  calculated field, pick its type, build its expression from the published
  operators and the schema's own fields, and try it against a sample record
  before saving.
- A calculated field is written as the property-level `calculation` key on its
  property, the key OpenRegister names for an administration surface. The
  hand-written `x-openregister-calculations` block stays read-only and is
  kept as it is.
- A save OpenRegister refuses shows the refusal code and the field it names,
  next to that field, and the staged edit is kept.

## What stays

- No formula language of buildiq's own: the expression is OpenRegister's JSON
  AST, built from its operator catalogue.
- Calculations inside a form while it is filled in are `form-calculation`, in
  the change `forms-live-values-and-checks`, and read rule sets, not this.

## Rows

| matrix | row | now | after |
|---|---|---|---|
| buildiq | data-computed-field | building | built |
