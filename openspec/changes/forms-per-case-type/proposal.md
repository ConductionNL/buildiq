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

## Discovery wave 3: the form owns the channel and the order

Round 4 discovery reaches the same change from the other side. The depth
study `procest/_round4/discovery/casetype-configurability.md` in
ConductionNL/market-intelligence, written 2026-09-14, puts row C13, "Forms
per intake channel", in cluster CT-6, "The form is not a form", and gives
CT-6 to buildiq. The row carries matrix row 1.1 and cites D-xxllnc-72.

dossiq reads `no` on C13, and the reason is exact: "`case.intakeChannel`
enum; `friendly-case-create-form` REQ-FCF-001", and "the channel is recorded
after the fact and one create dialog serves every channel". A channel the
product writes down after the case exists is not a channel that shaped the
form.

Nobody in the corpus does better. The second read's summary on C13 is
"nobody ships different forms per channel", against three partials: ZAC
"`Z-adm` `COMMUNICATIEKANAAL` reference table (8 rows, required field) plus
`productaanvraagtype` mapping", Valtimo "`V-ed` an external start form (URL)
plus the internal form flow, two intake channels", and OpenCase "`C-cc`
`ImportLocation` (folder or IMAP), separation sheets and QR for paper". A
`must` row where the whole market is partial is worth building rather than
matching.

Row B16, "Grouping, order and layout on the form", is the other half. dossiq
reads `no`: "`propertyDefinition` has no order, group or section;
`PropertiesTab.vue:20` renders in fetch order". The field's own keys are
dossiq's and openregister's, sized S in the study. The form's order and its
sections are this change's.

One consolidated candidate sits beside the rows, and its cluster is not this
one:

| candidate | relevance | driven | cluster and owner | this change's half |
|---|---|---|---|---|
| C-intake-16, "Public web form turned on per case type, with its own settings" | must | xxllnc-zaken | 51 "The intake form as its own object", owner portaliq | the form object declares that it is public and carries its confirmation text. portaliq renders it, and owns the address check, the captcha and the reuse of earlier case data |

Cluster 51's own mechanism line names this change: "extend portaliq
`embedded-intake-form` (portaliq#539) and `forms-per-case-type`". The two
halves meet at the leaf.

## The decision this rests on

D16, "Is the citizen's form the same definition as the internal one"
(`_round4/discovery/decisions.md`). Ruben took option 1 for the flag and
option 2 for the form, and said what each carries: "The field carries
whether the citizen may see and change it, because that is a property of the
field. The form carries which fields appear in which order for which
channel, because that is a property of the form."

That sentence is this section's whole scope. The form gains a channel and an
order. The portal flag stays off the form: it is a `propertyDefinition` key,
and it belongs to dossiq and openregister. The layout of the case page is
`case-page-layout-per-case-type`.

## What wave 3 adds

- `channel` on a form, with one default per type value, channel and
  audience, so the portal, the desk and the post room each get their own way
  in.
- `sections[]` and an order on `fields[]`, so the form decides what appears
  and in what order rather than inheriting the fetch order of the schema.
- `isPublic` and `confirmationText` on the form, served to whoever renders
  it.
- A `channel` filter on the leaf, and the channel served back with the form
  so the consumer writes it rather than inferring it afterwards.

Size M.

## How dossiq consumes it, in wave 3

dossiq's `friendly-case-create-form` asks the leaf for the `internal` default
on the `desk` channel. Its portal journey asks for the `client` default on
the `portal` channel. Both write `case.intakeChannel` from the served form's
`channel` instead of setting it after the fact, which is what makes row C13
move. The channel vocabulary stays dossiq's: buildiq validates a form's
channel against the consumer's own channel property and ships no list of its
own.

## Out of scope

- Rendering. `CnFormDialog` and `CnJourney` render; the consumer applies
  presets on submit because only it writes the object.
- Field-level rights per audience. That is OpenRegister's
  `row-field-level-security`; a hidden preset is a form decision, not a
  permission.
- The channel vocabulary. It is `case.intakeChannel` in dossiq, and buildiq
  validates against it rather than owning it.
- Rendering a public form, the captcha, the address check and the reuse of
  earlier case data. Those are portaliq's, cluster 51.
- Field order, grouping and placeholder on `propertyDefinition`. Row B16
  splits there and the field half is dossiq's.
