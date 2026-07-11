---
kind: code
depends_on:
  - nextcloud-vue#manifest-form-logic   # steps[] / visibleWhen / validation in app-manifest-v2.schema.json + CnFormPage rendering (published via the nc-vue `beta` dist-tag)
---

## Why

The manifest v2 form page type (`type: "form"`, rendered by `CnFormPage`
in `@conduction/nextcloud-vue`) today supports only a **flat** field list
plus a submit shape: `config.fields[]` items carry `key` / `label` /
`type` / `required` / `pattern` and nothing else, and OpenBuild's
authoring surface matches — `src/components/page-editor/FormPageEditor.vue`
(REQ-OBPD-006) edits the submit one-of and delegates the field list to
`src/components/page-editor/fields/FormFieldBuilder.vue`, which renders
exactly one row per field with key / label / type / required / pattern
inputs (verified against HEAD). No editor in the page designer authors a
`visibleWhen` condition today: the shared `$defs/visibleWhen` predicate
(`{ field, op, value }`, ops `eq|neq|gt|gte|lt|lte`, LOCAL mode dot-paths
into the caller's object context) exists in
`app-manifest-v2.schema.json` and is consumed by `$defs/action` and the
banner widget, but on the index/detail editors it is reachable only by
hand-editing raw manifest JSON — `grep -rn visibleWhen src/` in this repo
returns nothing.

The market baseline for form builders (Typeform, Tally, Microsoft Forms,
Gravity Forms) includes **conditional logic**, **multi-step wizards**, and
**per-field validation rules**. Nextcloud Forms is surveys-only, so a
citizen developer building a Nextcloud app has no ecosystem option for a
conditional multi-step form. A sibling leaf change,
**`manifest-form-logic` on the nextcloud-vue repo**, adds the RENDERING +
SCHEMA side: the form page config gains `steps[]` (ordered groups of
fields with per-step title/description), per-field `visibleWhen`
conditions (reusing the existing `$defs/visibleWhen` LOCAL shape:
`field` / `op` / `value`), and per-field `validation` rules
(`{ required, min, max, pattern, message }`) in
`app-manifest-v2.schema.json`, implemented in `CnFormPage`. That leaf
closes the runtime half; without THIS change the authoring half stays
raw-JSON-only and the loop is open exactly like schedules was before
`schedules-editor`.

This change adds the AUTHORING side in OpenBuild's page designer and
declares an explicit dependency on the leaf (consumed via the nc-vue
`beta` dist-tag). It writes only shapes the leaf's manifest contract
defines, and keeps the two consistent: `fields[].visibleWhen` reuses the
existing `$defs/visibleWhen` shape (`field`/`op`/`value`), `steps[]` is
an ordered array of field-key groups with per-step title/description,
and `validation` is `{ required, min, max, pattern, message }`.

## What Changes

- **MODIFIED** `src/components/page-editor/FormPageEditor.vue` — gains a
  **Steps** fieldset mounting a new steps manager; `validatedConfigKeys`
  gains `'steps'` so `useManifestValidator` errors under
  `/pages/<n>/config/steps` mark inline (ADR-024 / REQ-OBPD-011 pattern
  via `InlineFieldMark.vue`).
- **NEW** `src/components/page-editor/fields/FormStepsManager.vue` — the
  Steps manager: add / reorder (up-down) / delete steps, per-step
  `title` + optional `description`, and assignment of existing field
  keys to steps. Deleting a step returns its fields to the unassigned
  pool; it never deletes field definitions. Absent `steps[]` renders the
  single-step state (backward compatible).
- **MODIFIED** `src/components/page-editor/fields/FormFieldBuilder.vue`
  — each field row gains an expandable details area hosting two new
  sections, and the flat inline `required` / `pattern` inputs move into
  the Validation section (prefilled from the legacy flat keys):
  - **NEW** `src/components/page-editor/fields/VisibleWhenBuilder.vue` —
    the **Conditions** section: a `visibleWhen` builder with
    field / op / value pickers. The field picker is sourced from the
    form's own schema fields (the sibling `config.fields[].key` values);
    op is the schema enum `eq|neq|gt|gte|lt|lte` (default `eq`); value
    is a literal. Advanced `visibleWhen` shapes (`endpoint` / `source`
    modes) authored in raw JSON are summarised read-only and preserved
    untouched.
  - **NEW** `src/components/page-editor/fields/FieldValidationBuilder.vue`
    — the **Validation** section: `required` switch, `min` / `max`
    numbers, `pattern` regex (validated on input), custom `message`,
    written as the field's `validation` object per the leaf contract.
- **Live dangling-reference warning** — when a `visibleWhen.field` (or a
  step's field-key list) references a field key that no longer exists in
  `config.fields[]`, the editor shows an inline warning immediately
  (same `InlineFieldMark` affordance), without auto-deleting the stale
  reference.
- **NEW** `src/services/manifestValidation/formLogic.js` — app-side
  strict checks (steps shape, unknown field references, duplicate step
  assignment, `visibleWhen` op allow-list, `validation` coherence such
  as `min` ≤ `max` and compilable `pattern`), wired into
  `src/composables/useManifestValidator.js` beside the existing
  theme / workflow / document / connector / schedules validators.
- **Raw JSON round-trip preserved** — `FormPageEditor.update()` and
  `FormFieldBuilder.updateField()` already spread the previous object,
  so externally authored keys survive; the new sections keep that
  invariant for `steps` / `visibleWhen` / `validation` and for unknown
  sibling keys.
- **NO new backend, route, OR schema, or seed data.** Persistence rides
  the existing ApplicationVersion PUT via `PageDesignerHost.save()`,
  exactly like the schedules editor.

### Capabilities

- **ADDED** `form-editor-logic` — the visual authoring surface for
  conditional logic, multi-step wizards, and validation rules on
  manifest v2 form pages in OpenBuild's page designer.

No existing capability is modified: `openbuild-page-designer`'s
REQ-OBPD-006 (form sub-editor: submit one-of, method/mode pickers, field
list) keeps its requirements; this change composes new sections into the
same sub-editor.

## Impact

- Files touched: `src/components/page-editor/FormPageEditor.vue`,
  `src/components/page-editor/fields/FormFieldBuilder.vue`,
  `src/composables/useManifestValidator.js`; new files under
  `src/components/page-editor/fields/` and
  `src/services/manifestValidation/`; Vitest specs under
  `tests/components/` and `tests/services/`; one Playwright spec under
  `tests/e2e/`.
- Cross-repo dependency: the `manifest-form-logic` leaf on nextcloud-vue
  (schema + `CnFormPage` rendering), consumed via the `beta` dist-tag.
  Until the leaf is installed, authored logic saves fine (additive
  manifest keys) but renders as a flat form — see design.md
  "Dependencies / additive-tolerance guard".
- No BREAKING changes: absent `steps[]` = single-step form; fields
  without `visibleWhen` are always visible; fields without `validation`
  keep today's behaviour.
