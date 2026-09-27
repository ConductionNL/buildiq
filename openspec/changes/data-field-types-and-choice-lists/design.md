# Design: data-field-types-and-choice-lists

Read at buildiq development `d21e42f`, nextcloud-vue 2.57.1 as installed,
openregister development `ae898b0`.

## What exists

- `src/components/schema-editor/FieldEditor.vue:319-327` `SUPPORTED_TYPES`
  holds `string`, `number`, `integer`, `boolean`, `array`, `object`,
  `relation`. The string branch shows a free-text `format` input
  (lines 98-106).
- `fieldFromProperty()` (FieldEditor.vue, after `schemaToFields()` at line 684)
  reads type, format, pattern, lengths, bounds, items type and the relation
  block. `propertyFromField()` (line 777 onward) builds each property from
  scratch: description, default, then the type's slots.
- `src/views/SchemaDesigner.vue:1015` `composeSchemaBody()` calls
  `fieldsToSchema()` and writes `properties` whole; the `authorization` block
  is merged back over its preserved raw copy (REQ-OBDSA-002), properties are
  not.
- nextcloud-vue 2.57.1 `src/utils/schema.js:290-311`: `enum` renders a select,
  `referenceType: 'nextcloud-user'` or `format: 'user'` a user picker (and an
  array of them a multi-user picker), `format: date` a date picker,
  `date-time` a date-time picker.
- openregister: `lib/Service/Schemas/PropertyValidatorHandler.php:903` handles
  `type: file`; `lib/Service/Vocabulary/CodedPropertyDeclarationFactory.php:106-135`
  reads a property's `x-openregister-concepts` or its simple `conceptScheme`
  spelling; `lib/Service/Schemas/CodedChoiceDeclaration.php` refuses a scheme
  beside a literal `enum`.

## D1. Named types over JSON types

The picker lists maker-facing types and maps each to one shape:

| type | stored as |
|---|---|
| text, long text | `string`, long text adds `format: textarea` |
| number, whole number | `number`, `integer` |
| yes or no | `boolean` |
| date, date and time | `string` with `format: date` or `date-time` |
| choice | `string` with `enum` and `x-enum-labels`, or with `conceptScheme` |
| several choices | `array` of the same |
| file, several files | `file`, or `array` of `file` |
| user, several users | `string` with `referenceType: nextcloud-user`, or an array of it |
| relation, list, group | as today |

`fieldFromProperty()` reads a stored property back to the same named type, so
an existing schema opens with the right type selected. Anything it cannot name
opens as its JSON type, as today.

## D2. A choice is inline or shared, never both

The choice editor offers "Enter the options here" (an ordered list of value and
label) or "Use a shared list" (a picker over OpenRegister's concept schemes).
Choosing a shared list writes `conceptScheme: <slug>` and removes `enum`, which
is exactly the pairing `CodedChoiceDeclaration` refuses. The options of a
shared list are shown read-only in the editor so the maker sees what the field
will offer.

## D3. A user field stores the user id

`referenceType: nextcloud-user` is the key nextcloud-vue already reads and the
one its own docblock prefers over `format: user`. The value is the Nextcloud
user id; the display name is resolved at render time.

## D4. Keep what the designer does not edit

`propertyFromField()` starts from the property as it was loaded and overwrites
only the keys the editor owns, instead of starting from an empty object. The
loaded property travels on the field row as `_raw`. Without this, a maker who
opens a schema whose `enum` was set by an import and saves it loses the enum:
`fieldFromProperty()` never reads it and `propertyFromField()` never writes it.
Changing a field's type still clears the previous type's slots, as it does
today (FieldEditor.vue:564-566).

## Risks

- A choice bound to a concept scheme renders as a select only once
  nextcloud-vue reads the options OpenRegister serves; until then the app shows
  a text input and OpenRegister still refuses a value outside the scheme. The
  editor says so when the installed nextcloud-vue lacks it.
- A file field depends on nextcloud-vue's form file upload (matrix row
  `form-file-upload`, owner nextcloud-vue).

## What it does not do

- It adds no new JSON Schema keyword and no OpenRegister change.
- It does not migrate existing schemas; they open with the nearest named type.
