---
kind: code
depends_on: [journey-designer]
---

# Proposal: forms-multi-product-request

## Why

**buildiq matrix, row `form-multi-product-request`**, "Request several products
or services in one submission, each starting its own process.", rated `no`,
`built.state` `none`. A tender demand row asks for it: TenderNed announcement
234319 (https://www.tenderned.nl/aankondigingen/overzicht/234319). No competitor
rates it `yes`; five rate it `partial`, each by composition:

- NocoBase: "SubTableFieldModel/index.tsx:387 sub-table in the form creates
  several child records in one submission, and a Collection event workflow on
  the child collection ... starts a process per child" (source read at v2.2.18).
- Budibase: "one submission can start several rows or flows only by composition:
  a multi-select field ... saved on one row, then an automation Loop ... creating
  a row per product" (source read at v3.46.0).
- Mendix: "one submission can create several request objects each starting its
  own workflow in a microflow; this is modelled"
  (https://docs.mendix.com/refguide/workflows/).
- Microsoft Power Apps: "each created row can start its own flow through the
  Dataverse trigger, so a multi-item request is modelled as child rows"
  (https://learn.microsoft.com/en-us/power-automate/dataverse/create-update-delete-trigger).
- Appsmith is `no`: "one onSubmit per form; no process concept to start per
  product" (source read at v2.4.2).

Today, from `built.evidence`: "A registration form targets one register, schema
and type value (src/components/page-editor/DetailPageEditor.vue:151-156,
fields/RegistrationFormEditor.vue:42-82); nothing splits one submission into
several records or processes". The lane's decision adds: "journey-designer
authors writes[] for one submission, but not one write per requested product."

ADR-085 puts the shape in place: a `journey` declares `writes[]`, "each a
`register`/`schema`/`mapping` triple", committed by the run only at the steps
that declare them. What it lacks is one write per item of a list the user filled
in, with a target chosen per item.

## What changes

- A journey write can repeat: `forEach` names a list answer, and the write runs
  once per item.
- Each item picks its own target: the item's product value maps to a register,
  schema and type value, so a parking permit and a waste container each land in
  their own case type.
- An optional bundle write runs first, and every item write can point at it, so
  the user and the handler see one request with its parts.
- The form builder offers a "Product list" field: the user picks products and
  fills per-product details in rows.
- The designer shows, per target, whether a process starts when the record is
  created, and warns when none does.

## Rows this closes

| matrix | row id | row name | own rating | what is missing |
|-|-|-|-|-|
| buildiq | form-multi-product-request | Request several products or services in one submission, each starting its own process. | no | one write per requested product, each to its own target, authored in the journey designer |

## Existing work it builds on

- Change `journey-designer` (open, not started): the designer, `writes[]`
  validation with the run path's validator, and dependent writes ("A dependent
  write is authorable"). This change extends it and needs it.
- Change `registration-form-builder` (open) and spec
  `external-form-provisioning`: a registration form keeps one target; a request
  for several products is a journey.
- Change `forms-per-case-type` (open): the type value a case type is created
  with, which each item's target names.

## Sibling halves

- openregister: the run. The `journey` schema and the run API that commits
  `writes[]` are OpenRegister's open change `or-form-and-journey-registry` (none
  of its tasks checked at `ae898b0`). It owes accepting `forEach`, `targetBy` and
  `targets` in the journey schema, committing one write per item in order,
  recording each item's outcome on the `journeyRun`, and retrying a failed item
  without writing the others again.
- nextcloud-vue: the list field and the review. It owes the `sub-objects` form
  widget (open change `form-widgets-duration-and-subobject-table`, none of its
  tasks checked at `c8aa858`) and a review step in `CnJourney` that lists each
  product with its details.
- portaliq: nothing new. It hosts the journey (ADR-085 host table), and the
  confirmation lists what the run wrote.
- dossiq: nothing new. Each product's case type starts its own process when its
  case is created.

## Out of scope

- A product catalogue. The designer maps the list's product values to targets;
  where the list of products comes from is the form's own choice list.
- Payment for several products. That is portaliq's `intake-pay-on-submit`.
- Splitting an existing record into several afterwards.
