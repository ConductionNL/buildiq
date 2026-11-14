# Design: forms-multi-product-request

Read at buildiq development `d21e42f`, openregister development `ae898b0`,
nextcloud-vue development `c8aa858`.

## Where it sits

- The journey designer is not built yet. Its change `journey-designer` plans
  `src/views/JourneyDesigner.vue`, `src/components/journey-editor/JourneyWritesEditor.vue`
  and `src/services/journeyValidation.js` (its tasks 1 to 4), with
  `lib/Controller/JourneyDesignerController.php` for the save path. This change
  extends the writes editor and the validator it plans.
- The journey shape and the run are OpenRegister's `or-form-and-journey-registry`:
  a `journey` with steps, `next` rules, `writes[]` and `access`, and a run API
  that stages answers in `journeyRun` and commits writes at the declared steps.
- Registration forms, today's single-target intake:
  `lib/Settings/register.d/50-registration-forms.json` (`registrationForm`
  `0.4.0` with `register`, `schema`, `typeProperty`, `typeValue`), authored in
  `src/components/page-editor/fields/RegistrationFormEditor.vue` and listed from
  `DetailPageEditor.vue` through `RegistrationFormList` (lines 151-156).
- Form fields: `src/components/page-editor/fields/FormFieldBuilder.vue`
  (`FIELD_TYPES` at line 122).
- Processes on creation: OpenRegister flows with an `openregister.trigger-object`
  node on `object.created`, listed by `GET /apps/openregister/api/flows`.

## D1. A write that repeats

A `writes[]` entry may carry `forEach`, the key of a list answer collected
earlier in the journey. The run commits the entry once per item, in list order.
Inside the mapping, `item.<key>` reads the item's own fields and every other
answer stays readable as before. An entry without `forEach` behaves as today.

## D2. A target per item

A repeating entry may carry `targetBy` (an item field, usually `product`) and
`targets`, a map from each allowed value of that field to `{register, schema,
typeValue}`. Every value the list can hold must have a target; an unmapped value
is refused at save. The mapping is validated against every target schema it can
reach, with the same validator the run uses, so a field missing on one product's
schema is named with that product.

## D3. One bundle, many parts

An optional entry marked `bundle: true` runs before the repeating entry and
writes one parent record (for example `aanvraagbundel`). Item mappings may set a
field to the bundle's id through the dependent-write reference the journey
designer already supports. The run records each item's outcome on the
`journeyRun`, so the confirmation can say which parts were filed.

## D4. The product list field

The form field builder gains "Product list": a field with `widget: sub-objects`
whose first column is a choice of products and whose other columns are the
per-product details the maker adds. It stores an array of objects under one
answer key, which is what `forEach` names. A `maxItems` (default 10, at most 25)
bounds how many writes one submission can cause.

## D5. Does each part start a process

For each target, the writes editor lists the flows whose object trigger names
that target's register and schema with `object.created`, read from
OpenRegister's flow list, and warns "This product starts no process." when there
is none. It is a warning, not a refusal: a target may start its process another
way, for example a dossiq case type.

## D6. Authorisation

Authoring a repeating write is authoring a write, so it needs the journey
designer's own authorisation (its requirement "Authoring a journey MUST be
separately authorised"). The `targets` map may name only registers the author
may write.

## Risks

- One submission now causes several writes. `maxItems` bounds it, and the run
  records each part, so a failed part is retried alone.
- The feature needs three siblings: the designer, OpenRegister's run, and
  nextcloud-vue's list widget. Until all three land it is inert, and the designer
  says which piece is missing.

## What it does not do

- It does not change registration forms, which keep one target.
- It does not keep a product catalogue.
- It does not take payment.
