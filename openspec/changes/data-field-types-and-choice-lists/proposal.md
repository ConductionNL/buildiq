---
kind: code
---

# Proposal: data-field-types-and-choice-lists

## Why

A maker models data in the schema designer, and the designer offers seven JSON
types. The buildiq matrix records what that leaves out:

- buildiq matrix, row `data-field-types` ("Choose from typed fields such as
  text, number, date, choice, file and yes or no", `partial`):
  "src/components/schema-editor/FieldEditor.vue:319-327 SUPPORTED_TYPES =
  ['string','number','integer','boolean','array','object','relation']; no
  dedicated date/choice/file type. 'date' is only reachable as a free-text
  format string on 'string' ... No enum/select/choice picker anywhere".
- buildiq matrix, row `data-choice-lists` ("Maintain the choice lists a field
  offers in one place", `no`): "no field type, no schema-editor component, and
  no shared-choice-list concept anywhere in buildiq".
- buildiq matrix, row `data-user-field` ("Assign records to platform users with
  a user field", `no`): "a relation points at another schema, not at Nextcloud
  users, and there is no user picker type".

The competitors offer all three:

- NocoBase (data-field-types): "packages/core/client-v2/src/collection-manager/interfaces/
  holds 43 field interfaces (input, number, dateOnly, datetime, select at
  select.ts:14, checkbox, attachment via file-manager, and more)"; (data-user-field)
  "any collection can hold a relation to the built-in users collection".
- Budibase (data-field-types): "column types Text, Long form text, Single and
  Multi select, Number, Boolean, Date/time, Single and Multi attachment,
  Signature, Relationship, Formula, AI, JSON, User"; (data-user-field)
  "Single user and Multi user column types (BBReferenceFieldSubType.USER)".
- Mendix (data-field-types): "attribute types include AutoNumber, Boolean, Date
  and time, Decimal, Enumeration, Hashed string, Integer, String and more"
  (https://docs.mendix.com/refguide/attributes/); (data-choice-lists)
  "Enumeration attribute type, backed by a reusable enumeration document"
  (same URL); (data-user-field) "an association to System.User (or Account)
  assigns records to platform users"
  (https://docs.mendix.com/refguide/generalization-and-association/).
- Power Apps (data-field-types): "Dataverse data types: Choice, Choices,
  Currency, Date and Time, Decimal, Email, File, Image, Lookup, Multiline Text
  and more" (https://learn.microsoft.com/en-us/power-apps/maker/data-platform/types-of-fields);
  (data-user-field) "Dataverse lookup columns can point to the User table"
  (same URL).

`data-field-types` has four competitors yes, `data-user-field` four, and
`data-choice-lists` one (Mendix). All three sit in buildiq's core area (data).
No tender, featureRequest or roadmap row carries them.

The platform underneath already has what the designer does not offer.
OpenRegister validates `format: date`, `type: file`, `enum` and a property bound
to a shared concept scheme (`conceptScheme`, openregister change
`property-code-list-from-concept-scheme`), and nextcloud-vue 2.57.1 already
draws a date picker, a select for `enum` and a user picker for
`referenceType: nextcloud-user` (`src/utils/schema.js:290-311`). The gap is the
designer.

## What changes

- The field type picker offers named types a maker understands: text, long
  text, number, whole number, yes or no, date, date and time, choice, several
  choices, file, user, several users, relation, list and group. Each maps to
  one JSON Schema shape OpenRegister and nextcloud-vue already read.
- A choice field takes its options either inline (an ordered list with labels)
  or from a shared list: an OpenRegister concept scheme picked by name. The
  field then carries only the scheme reference, so the list is maintained in
  one place.
- A user field stores a Nextcloud user id with `referenceType: nextcloud-user`.
- The designer keeps every property key it does not edit. Today a save rebuilds
  each property from the editor's fields only, so an `enum`, a `title` or any
  `x-` key set outside the designer is dropped (see design D4).

## Rows this closes

| matrix | row id | row name | own rating | what is missing |
|---|---|---|---|---|
| buildiq | data-field-types | Choose from typed fields such as text, number, date, choice, file and yes or no. | partial | date, choice, file and user types in the designer |
| buildiq | data-choice-lists | Maintain the choice lists a field offers in one place. | no | a choice field that points at a shared list |
| buildiq | data-user-field | Assign records to platform users with a user field. | no | a user field type |

## Existing work it builds on

- `openspec/specs/openbuild-schema-designer/spec.md` and
  `openspec/specs/schema-designer-ui/spec.md`: the designer and its field
  editor.
- Archived `2026-07-11-data-scopes-authoring`: REQ-OBDSA-002 fixed the same
  strip-on-save problem for the `authorization` block; D4 applies that fix to
  properties.

## Sibling halves

- openregister: none owed for date, file, enum and user; they validate today.
  The concept-scheme options on schema read are its task 2.1 of
  `property-code-list-from-concept-scheme` (done).
- nextcloud-vue: 2.57.1 has no reference to `conceptScheme` or
  `x-openregister-concepts` in `src/`, and no `file` widget in `utils/schema.js`.
  A choice bound to a scheme renders as a select only once the form reads the
  options OpenRegister serves, and a file field renders only with the form file
  upload the buildiq matrix row `form-file-upload` already assigns to
  nextcloud-vue. Both are nextcloud-vue's.

## Out of scope

- Authoring the concept schemes themselves; they are OpenRegister vocabulary
  registers with their own editor.
- Computed and AI fields (`data-computed-field`, `ai-computed-column`).
- Signature, audio and location fields (`forms-signature-audio-and-location`).
