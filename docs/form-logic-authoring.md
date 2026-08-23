<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->

# Form logic — steps, conditions, and validation

The page designer's form-page sub-editor lets you build multi-step
wizards, conditional field visibility, and per-field validation rules
visually — no raw JSON required. This page covers the three sections
added to the **Form page** editor, plus the one editor-only behaviour
that keeps every save valid.

> Rendering these shapes at runtime (`CnFormPage`) requires a
> `@conduction/nextcloud-vue` build that contains the
> `manifest-form-logic` leaf (`1.0.0-beta.173`+, off the `beta` dist-tag).
> Authoring works regardless — the additive manifest keys survive either
> way — but a pre-leaf renderer shows the form flat and always-visible
> until the app's nextcloud-vue dependency is upgraded.

## Steps

Open a **form** page in the designer and expand the **Steps** fieldset
(below **Fields**). Each step has:

- a **title** (required, shown in the step indicator at runtime),
- an optional **description**,
- a stable **id** — auto-derived as a kebab-case slug from the title, but
  editable if you type your own,
- an ordered list of **field keys** assigned to that step.

Use **+ Add step** to create a step, then assign existing fields to it
from the **Unassigned fields** pool above the step list (pick a key in
the step's select, click **Assign**). Removing a field from a step
returns its key to the pool — field *definitions* are never touched by
the Steps section; only which step a key belongs to changes.

If you leave a page with no steps at all, it renders as a single flat
form — exactly like before this feature existed. Deleting the last
remaining step removes the `steps` key entirely rather than leaving an
empty array, so the page falls back to the same single-step state.

### The "every field needs a step" rule

Once a page has *any* steps, the runtime requires every declared field
to belong to **exactly one** step — an unassigned field would silently
never render, and a field assigned to two steps would render twice. The
Steps section shows the current unassigned pool live, with a note that
those keys are automatically appended to the **last** step when you
save. There is no silent renderer fallback for this — the manifest
either satisfies the rule or fails validation — so the auto-assign-on-save
behaviour is what keeps your save from ever producing an invalid
manifest, even if you forget to place a newly-added field into a step
before clicking Save.

## Conditions

Expand a field's row (click **Details**) to open its **Conditions**
section. Pick:

- **Field** — another field on the same form (the field you're editing
  is excluded from the list),
- **Op** — `eq`, `neq`, `gt`, `gte`, `lt`, or `lte` (defaults to `eq`,
  which is left out of the saved manifest since it's the default),
- **Value** — typed `true` / `false` and plain numbers are saved as
  boolean/number so ordering comparisons (`gt`, `lt`, …) work correctly;
  anything else is saved as the literal text you typed.

Clearing the field picker (or clicking **Clear**) removes the condition.

If a condition was authored directly in the Raw JSON tab using the
advanced `endpoint` or `source` shapes (a condition resolved against a
same-origin URL or an OpenRegister query, rather than another field on
the form), the Conditions section shows a read-only note — **"Advanced
condition — edit in Raw JSON"** — and never rewrites it. Editing
anything else on that field leaves the advanced condition untouched.

## Validation

The same field details area has a **Validation** section:

- **Required** — the field must be filled in,
- **Min** / **Max** — a length bound for text fields, a value bound for
  number fields,
- **Pattern** — a regular expression the value must match. An
  expression that doesn't compile is flagged inline immediately and is
  never saved, so you can't accidentally write a broken pattern.
- **Message** — a custom message shown for whichever rule fails.

Older form pages sometimes carry validation as flat `required` /
`pattern` keys directly on the field (rather than the structured
`validation` object above). The Validation section reads those as a
starting point, but **only writes the structured object once you make an
edit in that field's own Validation section** — at that point the flat
keys on that field are replaced. Fields you never touch keep their flat
keys exactly as they were; this is opt-in, per field, never a bulk
rewrite of the whole form.

## The dangling-reference warning

Deleting a field that's still referenced by another field's condition,
or by a step, does **not** silently break anything or cascade-delete the
reference. Instead, you'll see an immediate warning ("Condition
references removed field '…'" / a similar note on the step) right where
the stale reference lives, so you can decide what to do — re-add the
field, or edit the condition/step yourself. The reference stays in the
manifest until you resolve it.

## See also

- [Buildiq Runtime](./buildiq-runtime.md) — how a virtual app renders
  end to end.
- [Buildiq RBAC](./buildiq-rbac.md) — who can edit a page's form
  logic.
