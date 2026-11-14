---
kind: code
---

# Proposal: data-model-diagram

## Why

buildiq matrix, row `data-model-diagram`, "See the data model as a diagram of
object types and their relations.", rated `no`, in buildiq's core area (data).
The row is competitor-derived (origin
https://github.com/nocobase/nocobase/blob/v2.2.18/packages/plugins/@nocobase/plugin-graph-collection-manager/src/client/index.tsx#L18);
no tender, featureRequest or roadmap row carries it. Three competitors rated
`yes`:

- NocoBase: "packages/plugins/@nocobase/plugin-graph-collection-manager/src/client/index.tsx:18
  graph view tab under Data sources, Main; optional preset plugin
  packages/presets/nocobase/package.json:62".
- Mendix: "the domain model describes the app's data visually as entities,
  attributes and associations in a diagram editor"
  (https://docs.mendix.com/refguide/domain-model/).
- Microsoft Power Apps: "the data workspace lets you create tables, configure
  relationships and view a diagram of your data"
  (https://learn.microsoft.com/en-us/power-apps/maker/data-platform/create-edit-entities-portal).

What buildiq does today, per the matrix `built.evidence`: "grep -iE
'diagram|erd|graph' over src/components/schema-editor and
src/views/SchemaDesigner.vue: no diagram view; schemas are edited as a list".
The schema designer lists a version's schemas
(`src/components/schema-editor/SchemaListPanel.vue`), and relations are rows in
a form (`src/components/schema-editor/RelationEditor.vue`). A maker who wants
to see what links to what opens each schema in turn.

## What changes

- The schema list of an app version gets a "Diagram" view next to the list: one
  box per schema with its fields, one line per relation with its name and a one
  or many marker.
- Relations made in buildiq's relation editor are drawn, as are fields that
  point at another schema.
- Clicking a box, or pressing Enter on it, opens that schema in the designer. A
  schema in another register shows at the edge, without a name when the maker
  may not read it.
- The same boxes and lines are listed in a table under the diagram, for
  keyboard and screen-reader users. Above 50 schemas the boxes start folded to
  their titles.
- The maker can switch the diagram to one of the app's data registers.

## Rows this closes

| matrix | row id | row name | own rating | what is missing |
|-|-|-|-|-|
| buildiq | data-model-diagram | See the data model as a diagram of object types and their relations. | no | a diagram of an app version's schemas and relations inside the builder |

## Existing work it builds on

- `openspec/specs/openbuild-schema-designer`: the schema list scoped to the
  app's register ("Schema list panel scoped to the virtual app's register
  namespace") and the relations sub-editor ("Sub-editors for aggregations,
  calculations, notifications, relations, widgets"). The diagram is a second
  view of that same list.
- `openspec/specs/schema-designer-ui`: the list and detail modes of
  `SchemaDesigner.vue`.
- `data-registers-runtime` (open, every task ticked): `Application.dataRegisters`,
  which the register switch reads.
- The candidate the dedupe index offered,
  `archive/2026-06-20-unify-apps-with-app-type`, was read and does not mention a
  diagram.

## Sibling halves

- openregister owes the model. Its open change `modelling-schema-diagram`
  (openregister development `ae898b0`) adds `GET /api/registers/{id}/model`,
  which returns a register's schemas as nodes and the links "declared by `$ref`,
  `items.$ref` and `inversedBy`" as edges, with the read checks of
  `registers#schemas`. Buildiq draws from that endpoint and does not resolve
  references itself.
- openregister also owes one addition to that change: buildiq's relation editor
  writes relations as `x-openregister-relations` entries with `name`, `target`,
  `cardinality` and `inverseOf` (`RelationEditor.vue:235-251`), and the model
  change reads only `$ref`, `items.$ref` and `inversedBy`. OpenRegister keeps
  the key (`openregister/lib/Db/Schema.php:3097`) but reads it only in
  `NotificationRecipientResolver.php:205`. Without the addition every relation a
  maker drew in buildiq is missing from the diagram.
- nextcloud-vue: none. `CnGraphCanvas` (Vue Flow, per-type node slots,
  `readOnly`, zoom controls) is exported at 2.57.1.

## Out of scope

- Making or changing a relation by drawing a line. Relations stay in the
  relation editor.
- A diagram of records and their links.
- Saving where a maker moved a box. The layout is computed on open.
- Downloading the diagram.
