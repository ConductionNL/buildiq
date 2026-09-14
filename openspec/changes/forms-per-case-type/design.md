## Context

Kind: code. Three properties, one uniqueness change, one leaf filter, one
builder section. Everything else is `registration-form-builder`.

## D1. `registrationForm`, extended

| property | notes |
|---|---|
| `name` | slug-like, unique within the type tuple |
| `audience` | open string; `client`, `supplier`, `partner`, `internal` are the seeded values |
| `isDefault` | boolean; at most one per `(type tuple, audience)` |
| `presets[]` | `{field, value, hidden}`; `field` must exist on the target schema |

Uniqueness: `(targetApp, register, schema, typeProperty, typeValue,
name)`. A second `isDefault` for the same tuple and audience is refused
by validation naming the existing form.

## D2. Presets and the rendered form

A hidden preset removes the field from `fields[]` as served. A visible
preset leaves the field and sets its `default`. The leaf answers
`presets[]` verbatim beside the served form. The consumer merges hidden
presets into the submission values before its own write; buildiq never
writes the target object (REQ-OBRF-003).

## D3. The leaf

`buildiq-registration-form` `list(register, schema, objectId, {audience?,
name?})`:

- no filter: every published form for the type, defaults first, plus the
  caller's drafts;
- `audience` only: the forms of that audience, the default first;
- `name`: that form.

Response per form: `{form, presets[], name, audience, isDefault}`. Drafts
are keyed by form id as today.

## D4. The builder

The applies-to panel lists the forms for the chosen type with name,
audience and a default star; "Add form" creates one bound to the same
type. Each field in `FormPageEditor.vue` gets a Presets row: value and a
hidden toggle. Saving validates preset field names against the target
schema and warns on an unknown one, as REQ-OBRF-002 does for fields.

## Risks

- A hidden preset on a required field the consumer forgets to merge. The
  consumer's own validation refuses the write, which is the right place;
  the leaf documents the merge in its response shape.
- Two apps reading the same type with different audience words. The
  vocabulary is open, and the seeded four match the portal's.
