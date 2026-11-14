# Design: pages-detail-related-items

Read at buildiq development `974af862` and nextcloud-vue development `e487bc8`; buildiq pins `@conduction/nextcloud-vue` `^2.57.1`, and `relatedCollections` and `relationLinks` are props of `CnDetailPage` at tag `v2.57.1`.

## What exists

- `src/components/page-editor/DetailPageEditor.vue` (504 lines): register and schema selects (`:14-49`), the Sidebar fieldset with three shapes, object, boolean or unset (`:65-126`, `sidebarShape()` at `:279`, `updateSidebarKey()` at `:366`), a `sidebarProps.tabs` fieldset (`:128-137`), then `AppliesToPanel`, `ScreenOverrideList` and `RegistrationFormList` (`:138-156`). Register and schema lists come from `src/composables/useRegisterPicker.js`.
- nextcloud-vue:
  - `src/components/CnDetailPage/CnDetailPage.vue`: prop `relatedCollections` (`:1601`, entries `{ title?, register, schema, filter?, columns?, sort?, limit?, rowRoute? }`) and prop `relationLinks` (`:1631`, entries `{ label?, register, schema, fkField, labelField?, allowCreate?, title?, selectLabel? }`), rendered together below the body (`:623-645`); the `sidebar` object form carries `hiddenTabs` (`:1114-1121`).
  - `src/components/CnRelatedCollections/CnRelatedCollections.vue`: one titled `CnObjectListWidget` per entry, with filter tokens `@objectId` and `@object.<field>` resolved against the open record; `limit` defaults to 10.
  - `src/components/CnPageRenderer/CnPageRenderer.vue:128` binds the page config into the page component, so both keys reach `CnDetailPage` from a published manifest.

## D1. A related list is authored by its link field

A maker picks the related schema and the field on it that holds this record's id. The editor writes `filter: { <field>: '@objectId' }`. Makers think "requests of this client", not in filter maps, and the token is the one the renderer resolves. The field list comes from the picked schema's properties.

## D2. Columns and limit are optional

Columns default to the schema's list columns, as `CnObjectListWidget` does when `columns` is empty. The limit input accepts 1 to 50 and is left out of the config when it is the renderer's default.

## D3. Link buttons live in the same section

`relationLinks` render beside the related lists, so the editor keeps them together: label, register and schema, the field on this record that stores the link (`fkField`), the field shown in the picker (`labelField`) and whether a new record may be created from the picker (`allowCreate`).

## D4. Files is a sidebar choice

The files of a record show in the sidebar's files tab. A Files checkbox in the Sidebar section removes or adds `files` in `config.sidebar.hiddenTabs`. When the sidebar is in the boolean form and a maker switches Files off, the editor converts it to `{ enabled: true, hiddenTabs: ['files'] }`, so the choice survives.

## D5. Validation

Both lists go through the existing `useManifestValidator` path; an entry without register and schema, or a link button without `fkField`, gets an inline mark on its row.
