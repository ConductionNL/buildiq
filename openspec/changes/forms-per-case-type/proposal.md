---
kind: code
depends_on: [registration-form-builder]
---

## Why

Competitor gap register, row Q1.15 "is the citizen-facing form a separate
object from the record type, so one type carries several forms and a form
can hide fields with preset values" (`procest/_gaps/gap-register.md` in
ConductionNL/market-intelligence, 2026-09-13, read from
`_round4/compare/proposed-rows-batch8.md`). Rated no, owner buildiq, size
M. Opened by the small-owner lane of the OpenSpec phase.

`registration-form-builder` makes the form its own object, bound to one
type value, served as a leaf. That closes the first half of the row. Its
design says "one published form per `(targetApp, register, schema,
typeProperty, typeValue)`", so a type carries exactly one form, and
nothing in it presets a value the citizen must not see. The row asks for
both: several forms per type, and hidden preset fields.

The best competitor in the register: JSM Cloud (documented), request types
own the portal form and preset hidden fields; no driven yes yet
(`_round4/compare/proposed-rows-batch8.md`). The use is concrete. One
`bouwvergunning` type, three forms: the citizen's (`audience: client`,
short, presets `intakeChannel = portal`), the desk clerk's (`audience:
internal`, every field), the architect's (`audience: supplier`, presets
`applicantRole = gemachtigde`, hides it). Same type, same schema, three
ways in.

## What changes

- `registrationForm` gains `name`, `audience` (`client`, `supplier`,
  `partner`, `internal`, open string like the portal's audience
  vocabulary) and `isDefault`. Uniqueness moves from the type tuple to
  `(type tuple, name)`; at most one `isDefault` per `(type tuple,
  audience)`.
- `presets[]` on a form: `{field, value, hidden}`. A hidden preset field
  is not rendered and its value is written with the submission by the
  consumer; a visible preset is rendered pre-filled and editable.
- The leaf `buildiq-registration-form` lists every published form for the
  type, and accepts an optional `audience` filter and a `name`, so a
  consumer asks for "the client default" or "the form called
  architect-intake". The response carries `presets[]` so the consumer
  applies them on submit.
- The builder: a form list per type in the applies-to panel, a name and
  audience per form, a Presets section per field with a hidden toggle.

## How dossiq consumes it

The register's dossiq half: "caseType.intakeFormRef becomes a list; a
case type carries several forms and each presets what the citizen must
not see". dossiq's `friendly-case-create-form` asks the leaf for the
`internal` default; its portal journey asks for the `client` default;
both merge `presets[]` into the values before the write. One task in
dossiq's umbrella `competitor-parity-2026-09`, against `leaf-integrations`.

## ADRs

- ADR-085: a form is an OpenRegister object with the manifest form
  grammar; presets do not invent a second grammar.
- ADR-066: the leaf lists and serves; buildiq never writes the target
  object, so it never applies a preset itself.
- ADR-031 and ADR-070: the uniqueness rules are schema, not PHP.
- ADR-046: `audience` reuses the portal's open vocabulary.

## Existing specs it extends

The delta `registration-form-builder` (REQ-OBRF-001 to 003) and
`form-editor-logic`.

## Out of scope

- Rendering. `CnFormDialog` and `CnJourney` render; the consumer applies
  presets on submit because only it writes the object.
- Field-level rights per audience. That is OpenRegister's
  `row-field-level-security`; a hidden preset is a form decision, not a
  permission.
