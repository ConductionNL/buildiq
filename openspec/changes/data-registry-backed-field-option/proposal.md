---
kind: code
---

# Proposal: data-registry-backed-field-option

## Why

**buildiq matrix, row `int-dutch-registries`**, "Look up Dutch government
registries such as the chamber of commerce from an app.", rated `no`,
`built.state` `specified`.

The lookup itself is built, outside buildiq. integriq's archived change
`2026-09-29-registry-backed-field-source` ships three providers (BAG, BRP, KvK)
behind `PropertySourceController` (`GET /api/property-sources`,
`/api/property-sources/{provider}/suggest`, `/resolve`), and OpenRegister
accepts and checks the declaration `x-openregister-property-source`
(`lib/Service/Schemas/PropertySourceDeclaration.php`: `provider`, `mode`
`live` or `default`, `config`).

Nothing in buildiq writes that declaration. A maker who wants a "KvK-nummer"
field that looks up the company cannot say so in the schema designer: the field
editor (`src/components/schema-editor/FieldEditor.vue`) offers type, format,
validation and relation, and carries any other `x-` key through untouched
(REQ-BQFT-004), but never sets one. So the only way to get a registry-backed
field is to hand-edit JSON in OpenRegister.

## What changes

- The field editor gets a section "Value source" for `string` and `object`
  fields with two choices: "Typed in" (the default, nothing is written) and
  "From a registry".
- "From a registry" shows a provider picker filled from integriq's
  `GET /api/property-sources` (label, and the identifier it keys on, for
  example "KvK organisation, keyed on kvkNummer") and a choice between "Look it
  up every time" (`mode: live`) and "Use it as a starting value" (`mode:
  default`).
- Saving writes `x-openregister-property-source: {provider, mode, config}` on
  the property. `x-openregister-property-source` becomes an editor-owned key.
- The fields table shows the source in the "Format" column, for example
  "KvK organisation, live".
- A refusal from OpenRegister on save (an unknown provider, an unknown mode) is
  shown on the field it names.
- Without integriq the section shows a note and keeps an existing declaration
  as it is.

## Rows covered

- `int-dutch-registries` (buildiq's half)

Delivered before this change: `integriq/registry-backed-field-source`
(resolvers and endpoints) and OpenRegister's `property-source-vocabulary`
(the declaration).

## Not in this change, cross-repo

A built app renders its forms with nextcloud-vue (`CnFormDialog`,
`fieldsFromSchema`). A form field that reads `x-openregister-property-source`,
calls integriq's suggest and resolve, and stores the identifier with its
provenance belongs in nextcloud-vue. Until it lands, a declared field renders
as a plain text field in a built app. That is listed as cross-repo work in the
spec round hand-back, not specified here.
