# Design: data-calculated-field-authoring

## Context at HEAD (development cdeb58617)

- `src/views/SchemaDesigner.vue` stages a schema body, keeps
  `staged.calculations` from `body['x-openregister-calculations']` (line 1003)
  and writes it back (line 1054); `save()` (line 1449) PUTs on the numeric id.
- `src/components/schema-editor/CalculationEditor.vue` renders that block
  read-only.
- OpenRegister: `PropertyCalculations::PROPERTY_KEY = 'calculation'`, the same
  `{type, expression}` object the annotation holds; forwarded declarations are
  BLOCKING on save, the annotation is advisory.

## D1. Where a calculation is stored

On the property: `properties.<name>.calculation = {type, expression}`. A name
declared both on a property and in the annotation is refused by OpenRegister
(`calculation-duplicate-declaration`), so the editor does not offer "add" for a
name the annotation already holds; it lists that entry read-only with the note
that it comes from the register file.

## D2. How an expression is built

The editor loads `GET /apps/openregister/api/schemas/calculation-operators` once
and offers a tree builder: a node is an operator from the catalogue, a field
reference (one of the schema's own properties), or a literal of the operator's
argument type. No free-text formula. The JSON of the expression is shown in a
collapsed, read-only block for an auditor.

## D3. Trying before saving

"Try" posts `{calculation, object, register, schema}` to
`/apps/openregister/api/schemas/calculation-evaluate` with a sample the maker
fills in (defaulting to the first demo object of the schema when there is one)
and shows the result or the refusal.

## D4. Refusals

A 400 from the schema PUT with a calculation error code is mapped onto the
field named in its node path; the section scrolls to it and the staged body is
kept, so nothing typed is lost.

## Risks

- The operator catalogue may be absent on an older OpenRegister: the editor
  then shows the read-only view it shows today and says why.
