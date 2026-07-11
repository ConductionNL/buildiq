## Context

Manifest v2 form pages (`type: "form"`) render through `CnFormPage` in
`@conduction/nextcloud-vue`. Today the component and the schema support a
flat `config.fields[]` plus a submit shape — no steps, no conditions, no
structured validation. A sibling leaf change, **`manifest-form-logic`**
on the nextcloud-vue repo, adds the rendering + schema side; this change
adds the authoring side in OpenBuild's page designer. Both are specced
against one shared manifest contract (below) and MUST stay consistent.

### Ground-truth architecture (verified against HEAD)

- **Form sub-editor** — `src/components/page-editor/FormPageEditor.vue`
  implements REQ-OBPD-006: submit one-of (`submitHandler` XOR
  `submitEndpoint`, enforced at write time by `setSubmitShape` /
  `setSubmitHandler` / `setSubmitEndpoint`), `submitMethod` and `mode`
  enum pickers, optional `submitLabel` / `successMessage`, and a Fields
  fieldset delegating to `FormFieldBuilder`. Its `update(key, value)`
  spreads `{ ...this.config }` and deletes empty keys — so **unknown
  top-level config keys already round-trip losslessly**. It uses
  `pageEditorValidationMixin` (`src/mixins/pageEditorValidation.js`):
  `validatedConfigKeys` registers JSON-Pointer prefixes
  `/pages/<selected>/config/<key>` with the provided
  `pageEditorValidator`, and `markFor(key)` / `isInvalid(key)` drive
  `InlineFieldMark.vue` (the ADR-024 / REQ-OBPD-011 inline validation
  mark: a `{ hasError, message }` badge with `role="alert"`).
- **Field rows** — `src/components/page-editor/fields/FormFieldBuilder.vue`
  is a `modelValue`-in / `update:modelValue`-out array editor. Each row:
  `key`, `label`, `type` (native select over
  `string|number|boolean|select|textarea|date`), a `required` checkbox
  and a `pattern` text input written **flat on the field object**.
  `updateField(index, key, value)` spreads `{ ...current }` — unknown
  per-field keys (e.g. an externally authored `visibleWhen`) already
  survive edits. Empty-string / false values are dropped except for
  `key` / `label` / `type`.
- **visibleWhen today** — the shared predicate lives in nextcloud-vue's
  `src/schemas/app-manifest-v2.schema.json` as `$defs/visibleWhen`:
  three modes — `endpoint` (GET a JSON endpoint), `source` (an OR
  `{ register, schema, filter? }` query) and **LOCAL** (neither key —
  `field` dot-paths into the caller's object context), with
  `op ∈ eq|neq|gt|gte|lt|lte` (default `eq`) and a literal `value`.
  Evaluation is fail-safe (any error → hidden). It is referenced by
  `$defs/action.visibleWhen` and the banner widget. **No OpenBuild
  editor authors it**: `grep -rn visibleWhen src/` returns nothing —
  the index/detail editors' `ActionBuilder.vue` surfaces id / label /
  icon / target only, so conditions on actions are raw-JSON-only today.
  This change gives form fields the first visual `visibleWhen` builder;
  actions stay out of scope.
- **Validation plumbing** — `src/services/manifestValidation/*.js`
  (`theme`, `workflowAttachments`, `documentAttachments`,
  `connectorDataSource`, `schedules`) each export `validateX(manifest)`
  returning entries `useManifestValidator`
  (`src/composables/useManifestValidator.js:24-70`) concatenates with
  the canonical `validateManifest` result. Add `formLogic.js` there.
- **Persistence** — `PageDesignerHost.save()` PUTs the whole
  ApplicationVersion manifest; any top-level or nested key the editor
  does not touch survives. No new save path is needed (same free-ride
  the schedules editor uses).
- **Tests** — Vitest component specs in `tests/components/`, service
  specs in `tests/services/`; Playwright specs in `tests/e2e/` titled by
  REQ id (see `tests/e2e/page-designer.spec.ts`; note that suite is
  currently quarantined behind Conduction/openbuild#41 — new e2e specs
  follow the same title convention and run once #41 is fixed).

## Shared manifest contract (the `manifest-form-logic` leaf)

This is the contract the nextcloud-vue leaf defines and this editor
authors against. Task 1.1 re-verifies it against the leaf's actual
schema delta before implementation; any drift is resolved in the leaf's
favour (the schema is canonical, per the schedules-editor precedent).

**Re-verified against installed `@conduction/nextcloud-vue@1.0.0-beta.173`
(tasks 1.1/1.2):** the contract below is confirmed byte-for-byte.
Two corrections to this document's original assumptions, resolved in the
leaf's favour:

1. The canonical `validateManifest` (installed) already implements the
   complete-partition, duplicate-id, dangling-reference, `min`≤`max` and
   pattern-compile checks itself (`utils/validateManifest.js` §7,
   "Form logic"), confirming there is **no renderer fail-safe** — an
   incomplete partition is a hard validation error, never silently
   patched at render time.
2. `CnFormPage` does **NOT** honour the legacy flat `field.required` /
   `field.pattern` keys — `utils/formValidation.js`'s
   `validateFieldValue()` reads only `field.validation`. The "Validation
   plumbing" and Decision 4 text below describing them as
   "renderer-supported for back-compat" / "still honoured" is
   superseded by this finding: flat-only fields are silently
   unenforced at runtime today, which makes this editor's migrate-on-edit
   behaviour a correctness fix, not a cosmetic one.

```jsonc
{
  "type": "form",
  "config": {
    "fields": [
      {
        "key": "email",
        "label": "i18n.email",
        "type": "string",
        "visibleWhen": { "field": "wantsContact", "op": "eq", "value": true },
        "validation": {
          "required": true,
          "min": 5,            // number fields: value bound; string fields: length bound
          "max": 254,
          "pattern": "^[^@]+@[^@]+$",
          "message": "i18n.email-invalid"   // custom message for ANY failed rule
        }
      }
    ],
    "steps": [
      {
        "id": "step-contact",             // stable slug, unique within steps[]
        "title": "i18n.step-contact",     // required
        "description": "i18n.step-contact-help",  // optional
        "fields": ["wantsContact", "email"]        // ordered field-key refs into config.fields[].key
      }
    ]
  }
}
```

Contract invariants (shared with the leaf):

- `fields[].visibleWhen` reuses the existing `$defs/visibleWhen` shape.
  The editor's builder authors the **LOCAL** form only
  (`{ field, op?, value }` — `field` names a sibling field key, i.e. a
  dot-path into the in-flight form value); `endpoint` / `source` modes
  remain schema-legal, are renderable by the leaf, and round-trip
  through this editor untouched (read-only summary, "edit in Raw JSON").
- `steps[]` is an **ordered** array; step order = wizard order; a step's
  `fields[]` order = field order within the step. `title` is required
  per step, `description` optional.
- Absent or empty `steps[]` = **single-step form** (today's flat
  rendering) — backward compatible by construction.
- When `steps[]` is non-empty, the leaf's post-schema validator requires
  a **complete partition**: every declared `config.fields[].key` appears
  in exactly one step (zero steps = validation error, two steps =
  validation error). There is NO renderer fail-safe. The editor
  maintains the invariant itself: the unassigned-fields pool is a
  transient editor state, and on save any still-unassigned keys are
  auto-assigned to the **final** step with an explicit warning mark, so
  the written manifest always validates.
- `validation` = `{ required?, min?, max?, pattern?, message? }`. The
  legacy flat `required` / `pattern` field keys stay renderer-supported
  for back-compat but are **deprecated**: the editor prefills from them
  and normalises to `validation` on the first edit of that field's
  validation section (removing the flat duplicates it migrated, never
  other keys).

## Goals / Non-Goals

**Goals**

- A citizen developer can author multi-step wizards, per-field
  visibility conditions, and validation rules visually — no raw JSON.
- Everything written validates against the leaf's schema contract; the
  two repos never diverge on shape.
- Stale references (condition or step pointing at a deleted field) are
  warned about live, never silently dropped.
- Raw-JSON-authored content — including advanced `visibleWhen` modes and
  unknown keys — survives every editor interaction.

**Non-Goals**

- No rendering work: `CnFormPage` steps/conditions/validation execution
  is the nextcloud-vue leaf, not this change.
- No schema authoring: `app-manifest-v2.schema.json` deltas live in the
  leaf.
- No `visibleWhen` builder for actions, columns, or widgets — form
  fields only (the shared builder component is written to be reusable,
  but wiring it elsewhere is future work).
- No branching/skip logic beyond per-field `visibleWhen` (no per-step
  conditions, no goto-step rules) in this iteration.
- No new backend service, controller, route, OR schema, or seed data.

## Decisions

### Decision 1: Steps reference fields by key; fields stay canonical in `fields[]`

`steps[]` entries carry `fields: ["key", ...]` references instead of
inlining field objects. Rationale: `config.fields[]` remains the single
source of truth (no duplication, no merge conflicts between step blocks),
absent `steps[]` degrades to the flat form for free, and
`FormFieldBuilder` keeps owning field definitions unchanged. The steps
manager only moves references around. This matches the leaf contract
above.

### Decision 2: Steps manager UX — explicit pool, no drag-and-drop

`FormStepsManager.vue` renders each step as a bordered group (title
input, optional description input, up / down / delete buttons) with its
assigned field keys listed in order (each removable) and a native
`<select>` of **unassigned** field keys + an "Assign" button. An
"Unassigned fields" strip above the steps lists keys in no step, with an
inline note that the renderer appends them to the final step. Reordering
is up/down buttons (house style: native inputs, no DnD dependency —
same as `ColumnBuilder` / `ActionBuilder`). Deleting a step returns its
keys to the unassigned pool. Deleting the **last** step removes the
`steps` key entirely (single-step form again) rather than writing
`steps: []`.

### Decision 3: Conditions builder authors LOCAL mode only; advanced shapes pass through

`VisibleWhenBuilder.vue` offers three pickers: **Field** (native select
over the sibling `config.fields[].key` values, excluding the field being
edited), **Op** (`eq|neq|gt|gte|lt|lte`, default `eq`), **Value** (text
input; typed `true`/`false`/numeric strings are coerced to
boolean/number on write so `op: gt` comparisons behave, matching the
schema note that ordering ops coerce to Number). "No condition" is the
default state; clearing the builder deletes the `visibleWhen` key.
When an existing `visibleWhen` carries `endpoint` or `source`, the
builder renders a read-only summary ("Advanced condition — edit in Raw
JSON") and never rewrites that object — preserving raw-JSON authoring.

### Decision 4: Validation section normalises legacy flat keys

`FieldValidationBuilder.vue` writes the `validation` object. On open it
prefills from `field.validation`, falling back to the legacy flat
`field.required` / `field.pattern`. On the first write it migrates: the
values land in `validation` and the flat `required` / `pattern` keys are
removed **from that field only** (they were merged into `validation`, so
no information is lost and the renderer's deprecation path is exercised).
Fields the user never touches keep their flat keys byte-for-byte —
migration is opt-in per field, never a bulk rewrite. `min` / `max`
accept numbers (value bounds for `number` fields, length bounds for
string-ish fields, per the leaf contract); `pattern` is compiled with
`new RegExp()` on input and marked invalid inline when it throws;
`message` is a free i18n key.

### Decision 5: Dangling references warn live, never auto-fix

Removing a field whose key is referenced by another field's
`visibleWhen.field` or by a step's `fields[]` list does NOT cascade.
Instead: `FormFieldBuilder` / `FormStepsManager` compute dangling
references against the current `config.fields[]` on every render and
paint an `InlineFieldMark`-style warning next to the stale reference
("Condition references removed field 'x'"), and
`services/manifestValidation/formLogic.js` reports the same as a
validation error so the right-pane list and the `steps` / `fields`
inline marks (via `validatedConfigKeys`) agree. Rationale: auto-deleting
conditions on field removal would destroy work on a mis-click and break
the raw-JSON-preservation invariant; the schedules editor set the same
precedent for stale `synchronizationId`s.

### Decision 6: Controlled components + free persistence (no new save path)

All new components follow the house contract: props in
(`modelValue` / `:config`), events out (`update:modelValue` /
`update:config`), no API calls of their own. Every mutation shallow-clones
before writing (spread, `slice()`), preserving unknown keys.
`PageDesignerHost.save()`'s ApplicationVersion PUT persists everything —
no new endpoint, store, or save method.

### ADR-004 compliance

Plain Vue 2.7; native inputs / selects matching the existing page-editor
field components (`FormFieldBuilder`, `ActionBuilder`, `ColumnBuilder` —
this sub-tree deliberately uses native controls, not `NcSelect`). No
modals (no `NcModal` — the details area is inline expansion, so the
modal-isolation gate is not triggered). All new inputs carry visible
`<label>`s or `aria-label`s; warnings use `role="alert"` via the
`InlineFieldMark` pattern. No DOM data-attribute reads, no admin router
exposure.

### Declarative-vs-imperative (hydra ADR-031)

No declarative-backend behaviour is added: the editor writes declarative
manifest data the nextcloud-vue leaf renders client-side. No
notification dialect, no reconciler, no imperative dispatch.

## Validation rules (`services/manifestValidation/formLogic.js`)

For each `type: "form"` page's `config` (all errors carry JSON-Pointer
paths under `/pages/<n>/config/...` so the inline marks resolve):

- `steps`, when present: array of objects; each step has a non-empty
  `title` and a `fields` array of strings; step `id`s (when present)
  unique; a field key assigned to more than one step is an error; a step
  `fields[]` entry not found in `config.fields[].key` is an error
  (dangling step reference).
- `fields[].visibleWhen`, when present and LOCAL-shaped: `field` is a
  non-empty string; when it names no existing sibling field key, error
  (dangling condition reference); `op`, when present, is on the
  `eq|neq|gt|gte|lt|lte` allow-list. `endpoint` / `source` shapes are
  NOT validated here (canonical schema territory) beyond being objects.
- `fields[].validation`, when present: `required` boolean; `min` / `max`
  numbers with `min` ≤ `max` when both present; `pattern` a string that
  compiles via `new RegExp()`; `message` a string. A field carrying both
  a `validation` object and legacy flat `required` / `pattern` keys
  yields a warning-level entry (unmigrated duplicate).

## Dependencies / additive-tolerance guard (cross-repo)

This change is `kind: code` (OpenBuild Vue). The schema + renderer it
authors against is the **`manifest-form-logic` leaf on nextcloud-vue**,
declared in `depends_on` and consumed via the nc-vue `beta` dist-tag.

Sequencing guard, mirroring `schedules-editor`: the canonical
client-side validator (`validateManifest` from
`@conduction/nextcloud-vue`, consumed by `useManifestValidator` and the
`check:manifest` gate) resolves against the *installed* library build.
Today's schema already tolerates the new shapes additively —
`config.fields[].items` is `additionalProperties: true` and the form
page's `config` accepts unknown keys — so an authored manifest is not
rejected pre-leaf. The app-side `formLogic.js` checks are the
authoritative gate until the leaf lands; once the `beta` build with the
leaf is installed, the canonical validator takes over shape validation
and `formLogic.js` keeps the semantic checks (dangling references,
`min` ≤ `max`, regex compile). Ship this UI against a nextcloud-vue
build that includes the leaf; if it must ship earlier, the only
degradation is that `CnFormPage` renders the form flat and always-visible
(conditions/steps are additive keys the current renderer ignores) — the
authored data is preserved either way.

## No OR schema / no seed data

`steps` / `visibleWhen` / `validation` are manifest JSON persisted inside
the existing ApplicationVersion object via the existing PUT; no new OR
schema, `register.d/` fragment, or seed data.

## Risks / Trade-offs

- **Leaf-contract drift** — if the leaf ships a different `steps[]`
  encoding (e.g. inline field objects), the editor writes invalid data.
  Mitigation: task 1.1 verifies the leaf's schema delta before any
  component work; the contract section above is the agreed shape.
- **Stale-renderer window** — a consumer instance with a pre-leaf
  nextcloud-vue renders authored wizards flat. Accepted: additive keys,
  no data loss, documented in the guard above.
- **Value-coercion ambiguity** — the condition value input coerces
  `"true"` / `"42"` to boolean / number; a user needing the literal
  string can use Raw JSON. Accepted for v1 (matches op semantics).
- **Per-field migration surprise** — editing one field's validation
  migrates only that field's flat keys; a manifest can temporarily mix
  flat and structured validation. The renderer supports both; the
  warning-level validator entry keeps it visible.

## Migration Plan

None. Additive frontend only. Existing form pages (flat fields, flat
`required` / `pattern`) load unchanged: no steps → single-step state,
no conditions, validation section prefilled from flat keys until edited.

## Open Questions

- Whether `VisibleWhenBuilder` should later be mounted in
  `ActionBuilder` (actions already carry `visibleWhen` in the schema) —
  deliberately out of scope here; the component is written prop-driven
  so it can be reused.
- Whether step `id` should be user-editable or always auto-derived from
  the title (current design: auto-derived kebab-case, editable —
  matching the schedules-editor id precedent).
