---
kind: code
---

# Proposal: forms-live-values-and-checks

## Why

A form built in buildiq asks and validates, but it does not think along while it
is filled in. Three rows of the buildiq matrix record it:

- buildiq matrix, row `form-calculation` ("Calculate a value in a form while it
  is filled in, such as the fee to pay", `no`):
  "src/components/schema-editor/CalculationEditor.vue:1-30 is a v1 stub
  (read-only view, authoring deferred); FormPageEditor.vue and
  fields/FormFieldBuilder.vue offer no calculated field; nextcloud-vue v2.55.1
  src/components/CnFormPage: grep -iE 'calculat|formula|computed' finds no
  in-form calculation".
- buildiq matrix, row `form-prefill` ("Prefill a form with known data, such as
  the logged-in user's details", `no`): "CnFormPage v2.55.1 accepts a static
  initialValue ... FormPageEditor.vue:374 lists 'initialValue' only as a
  validated config key with no input for it. No binding of a field to the
  current user or known data".
- buildiq matrix, row `form-eligibility-feedback` ("Tell the person filling in a
  form straight away which conditions they do not meet, with an explanation",
  `partial`): "Business rules can decide an outcome from data
  (lib/Service/RuleEngineService.php, lib/Service/DecisionTableEvaluator.php;
  example 'loan-eligibility' in lib/Settings/register.d/10-business-rules.json:599-602),
  but no form page evaluates a rule while it is filled in and shows the unmet
  condition".

Demand. Two tender rows: TenderNed 382064 on `form-calculation`
(https://www.tenderned.nl/aankondigingen/overzicht/382064) and TenderNed 234319
on `form-eligibility-feedback`
(https://www.tenderned.nl/aankondigingen/overzicht/234319).

Competitors, quoted from the matrix:

- `form-calculation`, four yes. NocoBase: ""Field assignment" linkage action
  sets a field from an expression over other form values while the form is
  filled in". Budibase: "a Text component (textv2) or a field Default value can
  bind a Handlebars or JavaScript expression over other fields". Mendix: "page
  expressions and on-change actions recompute values such as a fee while the form
  is filled in" (https://docs.mendix.com/refguide/attributes/). Power Apps:
  "Power Fx formulas recalculate automatically as inputs change, like a
  spreadsheet, so a fee label updates while the form is filled in"
  (https://learn.microsoft.com/en-us/power-apps/maker/canvas-apps/working-with-formulas).
- `form-prefill`, four yes. NocoBase: ""Default value" per form field accepting
  variables; the current user is exposed as ctx.user". Budibase: "every form
  field has a bindable 'Default value'; ... offers 'Current User.<field>'
  bindings". Mendix: "the [%CurrentUser%] token gives the logged-in user"
  (https://docs.mendix.com/refguide/xpath-keywords-and-system-variables/). Power
  Apps: "the User() function returns the current user's name, email and picture
  to set a control's Default value"
  (https://learn.microsoft.com/en-us/power-apps/maker/canvas-apps/show-current-user).
- `form-eligibility-feedback`, three yes. Budibase: "per-field validation rules
  ... each carry their own error message shown on the field". Mendix:
  "validation rules and the Validation Feedback activity show a message next to
  a field straight away, explaining which condition is not met"
  (https://docs.mendix.com/refguide/setting-up-data-validation/). Power Apps:
  "business rules validate data and show error messages on a form when
  conditions are not met"
  (https://learn.microsoft.com/en-us/power-apps/maker/data-platform/data-platform-create-business-rule).

The three share one form, one field editor and one rule engine, so they are one
change.

## What changes

- A form field can take a default: a fixed value, a token for the signed-in user
  (`@me`, their display name, their e-mail) or today's date, or a field of a
  record the form was opened from.
- A form field can be calculated: bound to an output of a rule set, re-evaluated
  as the answers it reads change, and shown read-only.
- A form can carry an eligibility check: a rule set evaluated as the person
  types, whose unmet conditions show beside the form with the explanation the
  rule set carries, and which can block submit while unmet.
- The rule evaluate endpoint gets a preview mode for live use: no execution log
  row per keystroke, same RBAC, same rate limit.
- The calculated value is evaluated again on the server when the record is
  saved, so a changed value in the browser never lands.

## Rows this closes

| matrix | row id | row name | own rating | what is missing |
|---|---|---|---|---|
| buildiq | form-calculation | Calculate a value in a form while it is filled in, such as the fee to pay. | no | a calculated form field |
| buildiq | form-prefill | Prefill a form with known data, such as the logged-in user's details. | no | a field default bound to the user or to known data |
| buildiq | form-eligibility-feedback | Tell the person filling in a form straight away which conditions they do not meet, with an explanation. | partial | evaluating a rule while the form is filled in and showing the unmet conditions |

## Existing work it builds on

- `openspec/specs/business-rules-engine/spec.md` (archived
  `2026-06-14-business-rules-engine`): rule sets, decision tables, the evaluate
  route; `buildiq-consumes-shared-dmn` (open) moves matching to OpenRegister's
  DMN evaluator.
- `openspec/specs/form-editor-logic/spec.md` (archived
  `2026-07-11-form-editor-logic`): steps, `visibleWhen`, validation on a form
  page.
- `data-computed-field` in the buildiq matrix is `building`
  (`CalculationEditor.vue`, `x-openregister-calculations`): the stored-field
  calculation this change's form calculation does not replace.

## Sibling halves

- nextcloud-vue: `CnFormPage` in 2.57.1 takes a static `initialValue`
  (`CnFormPage.vue:370`, `:581-596`) and nothing more. It owes three generic
  hooks: resolve a field `default` through its existing sentinel resolver, call a
  host-provided resolver for fields marked `calculate` when the answers they read
  change, and render a host-provided list of unmet conditions. The sentinel
  vocabulary (`$defs.sentinelFilterToken`: `@me`, `@now`, `@today`, and the
  relative dates) also needs `@me.displayName` and `@me.email`. Buildiq supplies
  the resolver and writes the manifest; it does not fork the form renderer.
- openregister: none owed. The server-side re-evaluation uses its
  `ObjectCreatingEvent` and `ObjectUpdatingEvent` (`lib/Event/`).

## Out of scope

- A formula language. Calculations come from rule sets, which buildiq already
  authors and tests.
- Prefill from a registry lookup (the buildiq matrix row `form-address-lookup`,
  deferred; the lookup is integriq's).
- Payment of a calculated fee (`intake-pay-on-submit` in portaliq).
