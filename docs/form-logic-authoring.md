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

## Prefilled, calculated and checked while it is filled in

A form can fill in what is already known, work out what follows from the
answers, and tell the person filling it in straight away why they do not
qualify. Calculations and checks come from a rule set you already built and
tested under **Rules**. There is no formula language to learn.

**Filled in when the form opens.** In a field's **Details**, pick what it
starts with: a fixed value, the user name of the person filling it in, their
name, their e-mail, today, or a field of the record the form opens from (for
example `title`). The field stores a token such as `@me.email`; the person
can still change the value.

**Calculated.** Pick the rule set that works the value out, the outcome to
show, and the answers it reads. The field shows the outcome read-only and
works it out again when one of those answers changes. A rule set that is not
active is marked, because the field stays empty until it is.

**Eligibility check.** On the form page, pick a rule set, the outcome and
value that mean the person qualifies (for `loan-eligibility`: `decision` is
`approved`), and the outcome that explains why not (`reason`). With **Wait
for a pass before the form can be sent** the submit button stays disabled
until the check passes.

While the form is filled in, buildiq evaluates these rule sets in preview
mode, which writes nothing to the execution log. When the record is saved,
buildiq evaluates every calculated field again with the saved values and
stores its own answer, so a value changed in the browser never lands. A
blocking check that fails refuses the save with its explanation.

> Showing these values while the form is filled in needs the matching hooks
> in `@conduction/nextcloud-vue`'s `CnFormPage`. Until the app's renderer has
> them, the form shows the fields as plain inputs; the server still
> recomputes and checks on save.

## The dangling-reference warning

Deleting a field that's still referenced by another field's condition,
or by a step, does **not** silently break anything or cascade-delete the
reference. Instead, you'll see an immediate warning ("Condition
references removed field '…'" / a similar note on the step) right where
the stale reference lives, so you can decide what to do — re-add the
field, or edit the condition/step yourself. The reference stays in the
manifest until you resolve it.

## Filling a form from pasted text

Under **Fill from pasted text** you can let the users of a form fill it
from text they paste, such as an email or a letter. Switch it on, tick the
fields the AI may fill, and optionally write a hint for the paste box. The
page config then carries:

```json
"smartPaste": { "enabled": true, "fields": ["naam", "adres", "telefoon"], "hint": "Paste an email or letter" }
```

What the user gets:

- A **Paste to fill** control. The AI proposes values for the ticked fields
  only, and only for fields that are empty and visible; the other fields
  stay as they are.
- Every proposed value is marked as a suggestion, still passes the field's
  own validation, and nothing is submitted until the user clicks Submit.

The checks the designer runs:

- Smart paste is only for signed-in forms. On a form in `public` mode (the
  default when no mode is set) the switch stays off and says "Only
  signed-in forms can use AI.", so no anonymous visitor ever reaches a
  language model.
- A ticked field must exist on the form. Delete a ticked field and the
  fieldset is marked with the unknown key until you untick it.
- Files, signatures and relations cannot be filled from text and are not
  offered.
- Switched on with no fields ticked is an error.

The preview shows the control with a fixed sample answer, so you can see
the behaviour without calling a model. In built apps the control appears
once the form renderer and the AI fill service support it; until then the
setting is saved but has no effect.

## See also

- [Buildiq Runtime](./openbuild-runtime.md) — how a virtual app renders
  end to end.
- [Buildiq RBAC](./openbuild-rbac.md) — who can edit a page's form
  logic.
