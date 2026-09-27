# Design: data-model-diagram

Read at buildiq development `d21e42f`, openregister development `ae898b0` and
`@conduction/nextcloud-vue` 2.57.1.

## Where it sits

- View: `src/views/SchemaDesigner.vue`, registered as `SchemaDesignerView`
  (`src/registry.js:230`) and mounted by the `Schemas` page
  (`src/manifest.json:518-534`). List mode is lines 28-39: an "Import data"
  toolbar and `SchemaListPanel`. The list is loaded from
  `/apps/openregister/api/registers/{register}/schemas` (line 884).
- Relations: `src/components/schema-editor/RelationEditor.vue` serialises rows
  into `x-openregister-relations` entries `{name, target, cardinality,
  inverseOf}` (`editorToRelations()`, lines 235-251, cardinality `one` or `many`
  at line 81). `SchemaDesigner.vue:1031-1034` writes them onto the schema body.
- Data registers: `Application.dataRegisters`
  (`lib/Settings/register.d/20-data-registers.json:7-36`).
- Canvas: `CnGraphCanvas` at nextcloud-vue 2.57.1
  (`src/components/CnGraphCanvas/CnGraphCanvas.vue`), exported from
  `src/components/index.js:64`. It takes Vue Flow `nodes` and `edges` (lines
  178-190), a `readOnly` flag that refuses drag, connect and select together
  (line 196), zoom controls (line 244), and per-type node slots (`#node-<type>`,
  comment at lines 24-27). Unregistered types fall back to `CnFlowNode`, which is
  focusable and keyboard-operable (`CnFlowNode.vue:29-33`).
- Model, openregister (open change, not built): `GET /api/registers/{id}/model`
  from `modelling-schema-diagram`.

## D1. A second view of the list, not a new page

The diagram answers the same question as the list (what data does this app
version have), for the same register, so it is a "List | Diagram" switch on the
list mode of `SchemaDesigner.vue`, kept in the route query (`?view=diagram`) so
a link opens it. A new menu entry was rejected: ADR-097 sets a navigation budget,
and a second schema page would drift from the first.

## D2. OpenRegister computes the model, buildiq draws it

Buildiq reads nodes and edges from OpenRegister's model endpoint and maps them
onto the canvas in a pure module, `src/services/dataModelGraph.js`: node id,
title, fields (name and type), edge label and a `1` or `n` marker. It does not
resolve `$ref` values itself. Resolving references and deciding which external
schema a maker may see are OpenRegister's (its design D-1 and D-2), and doing
them twice would let the two pictures disagree.

## D3. Read only, laid out on open

The canvas runs with `readOnly: true`, so a maker cannot make a relation by
dragging a line that the relation editor would not know about. Layout is a
left-to-right layering computed in `dataModelGraph.js` from the edges (a schema
with no incoming relation starts a column). Positions are not stored.

## D4. A box is a schema node, and opening it is the designer's route

The diagram registers one node type, `schema`, whose slot renders the title and
up to twelve fields ("and 8 more" after that). Click or Enter on a box calls
`openSchema()` (`SchemaDesigner.vue:1372-1381`), the handler `SchemaListPanel`'s
`open` event reaches today, so the `?_version=` query travels along. An external node the maker may
not read carries no title (OpenRegister's rule) and does not open.

## D5. The table is the accessible view

Under the canvas a table lists each relation as "from, relation, to, one or
many", and each schema without relations on its own row, each row with an
"Open" button. This follows ADR-059: everything the canvas shows is reachable
without a pointer.

## Risks

- Until openregister reads `x-openregister-relations` in its model, relations a
  maker drew in buildiq do not appear. The change is only done when they do
  (task T02 checks it).
- The model endpoint is not built yet. This change cannot ship ahead of it.
- A large register draws a crowded canvas. Boxes fold to titles above 50 schemas
  and the table stays complete.

## What it does not do

- It adds no buildiq route and no stored state.
- It does not edit schemas or relations.
