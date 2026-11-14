## Context

Kind: code. One schema, one editor section, one data-provider leaf.

The page designer (`openbuild-page-designer`) authors `manifest.pages[]` for
a virtual app. A layout per case type is the same shape, the detail-page
`config`, but stored per target schema and type value instead of per virtual
app page, and read at render time by another app.

## D1. `pageLayout` schema

In `lib/Settings/openbuild_register.json`:

| property | type | notes |
|---|---|---|
| `targetApp` | string | app id, e.g. `dossiq` |
| `register`, `schema` | string | the object schema the layout applies to |
| `typeProperty` | string, optional | e.g. `caseType` |
| `typeValue` | string, optional | one value; empty means schema-wide |
| `header` | object | `titleField`, `subtitleField`, `chips[]` (field names) |
| `tabs[]` | array | ordered `{id, kind: leaf|widget, ref, label}` |
| `widgets[]` | array | `{slot, id, config}` in the manifest widget shape |
| `status` | lifecycle `draft`, `published` | only `published` resolves |

`(targetApp, register, schema, typeProperty, typeValue)` is unique. The
`tabs[]` and `widgets[]` sub-shapes validate against the canonical manifest
schema (`app-manifest.schema.json`, detail page config).

## D2. Editor

`DetailPageEditor.vue` (the detail-page sub-editor) gains an "applies to"
panel: target app, register, schema and an optional type property and value
picker read from the target schema's enum or from the type register's rows.
Saving writes a `pageLayout` object through the OpenRegister objects API, the
same path the page designer already uses (REQ-OBPD save flow).

## D3. `buildiq-page-layout`, kind data-provider

- `lib/Integration/PageLayoutLeafProvider.php`, `IntegrationProvider` with
  storage strategy `app-local`, no `create`.
- `list(register, schema, objectId)` reads the object's type value when a
  type-specific layout exists, and returns at most one published layout:
  type value first, then schema-wide. Empty list when none.
- Registered on `RegisterLeafProvidersEvent` behind `class_exists()`.
- The consumer places the leaf in its manifest. `CnDetailPage` asks the
  provider before render and merges the answer over its manifest config
  (nextcloud-vue dependency, tracked as `runtime-detail-layout` there).

## D4. Resolution order

1. published layout with matching `typeValue`
2. published layout with empty `typeValue`
3. nothing: the consumer's manifest wins

Draft layouts never resolve. An admin previews a draft in the page designer's
live preview pane.

## Risks

- A layout referencing a leaf id the consumer does not register renders an
  empty tab. The editor warns on save when the leaf id is unknown to the
  integration registry.

## D5. Wave 3: the shapes CT-6 adds

`pageLayout` grows four blocks. All four resolve by the D4 order, and all
four are served by the one leaf, so a consumer registers it once.

| block | shape | citation |
|---|---|---|
| `tabs[]` | `{id, kind: leaf\|widgets\|fieldGroup\|relatedList, ref, label, order}` | Valtimo Tabbladen, four tab kinds, `V-ed` |
| `widgets[]` inside a `widgets` tab | `{id, config, order, width: small\|medium\|large\|extraLarge, conditions[], highContrast}` | `V-ed` widget wizard, "width Klein, Medium, Groot, Xtra Groot", drag order; `V-cc` `Condition`; D-valtimo-38 |
| `header` | `{titleField, subtitleField, chips[], fields[], widgetId}` | `V-ed` Header widget |
| `taskList` | `{columns[]: {field, label, order, width}, searchFields[]: {field, operator}}` | D-casetype-21, `CaseDefinition-Taken.md`, D-valtimo-30 |
| `uploadFields[]` | `{field, label, order, visibility: editable\|readOnly\|hidden, default}` | D-casetype-22, `V-zgwp`, D-valtimo-42 |

## D6. Why the conditions are evaluated in the leaf and not in the consumer

`conditions[]` reads the host object, and the leaf already has it: `list`
takes `register`, `schema` and `objectId` and reads the type value from it
(D3). Evaluating there means one implementation, and it means a consumer
cannot forget. The cost is that the served layout is per object rather than
per case type, so the provider result is not cacheable across objects of one
type. Cache on `(layout id, condition field values)` rather than on the
layout id.

The condition vocabulary stays deliberately small: `equals`, `notEquals`,
`isEmpty`, `isNotEmpty`, `in`. A condition that needs more than that is a
rule, and rules are openregister's under decision D3. This is the same line
the study draws: "a rule whose condition reads a case-type property rather
than a built-in field" is named as still missing after openregister's three
rule changes land, and it is not answered here.

## D7. Task list and upload fields ride the same object

A separate `taskListLayout` and `uploadFormLayout` schema would mean three
uniqueness rules, three editors and three leaves for one question: what does
this case type look like. They ride `pageLayout` instead, each optional, each
resolving by the same order.

The consequence worth stating: dossiq's task list and its upload dialog now
depend on a buildiq leaf. Both keep their manifest shape when nothing
answers, which is the same fallback the detail page already has, and it is
what makes the dependency safe to add.

## D8. What buildiq does not touch

Buildiq stores and serves. It never reads a case, never writes a task and
never applies an upload default. The consumer applies what it is served, the
same rule `forms-per-case-type` states for presets: buildiq never writes the
target object.
