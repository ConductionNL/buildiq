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

## D5. Wave 3: the channel beside the audience

`audience` says who fills the form in. `channel` says how it arrived. They
are not the same axis, and collapsing them loses a case a gemeente has: the
desk clerk types in what came by post, so the audience is `internal` and the
channel is `post`. Both stay on the form, and the default rule spans both:
at most one published default per `(type tuple, audience, channel)`.

`channel` is validated, not owned. The consuming schema declares the
vocabulary, and dossiq's is `case.intakeChannel`. Buildiq reads that enum
through the target schema and refuses a value outside it. Shipping a list of
our own would put two vocabularies in step by hand, which is exactly what
the register's own rule against a second grammar avoids.

A form with no `channel` serves every channel. That keeps the existing
seeded forms valid and gives an admin a single form until they need more.

## D6. Order and sections live on the form, not on the field

Row B16 splits in two and the study says so: the field's own keys, order,
group and placeholder, are dossiq's on `propertyDefinition`; the form's
order and sections are here. They can disagree, and the form wins, because
the form is the thing the filer sees.

The consequence: a field added to the schema does not appear on a form until
an admin puts it there. That is the point of an ordered form and it is worth
writing in the release note, because the old behaviour, every property in
fetch order, is silently different.

## D7. `isPublic` and `confirmationText` stop at the edge

The form declares that it may be served publicly and what the filer reads
after submitting. It does not carry the captcha, the address check or the
reuse of earlier case data: those run where the form is rendered, and
cluster 51 gives them to portaliq. Buildiq serves the declaration, portaliq
enforces the rest, and the boundary is the leaf.

## Risks

- An admin publishes a public form for a type whose fields include internal
  values. The presets and the field list already decide what is served, and
  `isPublic` changes nothing about the field-level flag, which is the
  field's own under D16.
- A consumer that asks for a channel it never writes. The served `channel`
  is what it should write; the leaf documents it in the response shape, the
  same way it already documents `presets[]`.
