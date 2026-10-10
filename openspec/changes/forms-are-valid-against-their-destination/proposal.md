---
kind: code
depends_on: []
---

# Proposal: forms-are-valid-against-their-destination

buildiq's half of decision 179 (Ruben, 10 October 2026): "when building a form we should know the destination object and the form should at least be valid against that." Cross-app change: `hydra/openspec/changes/form-submits-into-its-destination-object`, architecture in hydra ADR-117. Needs `openregister/form-destination-validator`.

## Why

buildiq is where forms are built, and today none of its form surfaces refuses a form its destination cannot accept.

- A form page posts to `/apps/openregister/api/objects/{register}/{schema}`. `manifestValidation/formLogic.js` checks steps and conditions, not the fields against the schema.
- Registration forms read the target schema (`RegistrationFormTargetSchemaReader`) and only warn on a field the schema lacks (`RegistrationFormTargetWarnings`, REQ-OBRF-005: "Saving SHALL warn"). A missing required property is not reported at all.
- External forms check only that a register and schema are named (`manifestValidation/externalForms.js`), and specify no spam protection.

The journey designer (`journey-designer`, not built) already says write mappings are validated at author time. This change gives every buildiq form surface that rule, through one OpenRegister validator.

## What changes

1. **Every form names its destination.** Form pages, registration forms, external forms and journey `writes[]` store `destination { register, schema }`.
2. **Save calls OpenRegister's validator.** Findings render on the field in the designer (board `BqPaginaOntwerperFouten`). In report mode the save goes through with the findings shown; in refuse mode publish is blocked (question Q9, recommended option: one release of report mode).
3. **`RegistrationFormTargetWarnings` becomes a caller of the validator.** Warnings for unknown fields become findings, and required-property coverage, types and enums are added. `presets[]` count as fixed values.
4. **External forms**: publishing for an anonymous audience requires the destination to grant public create (REQ-EFP-003 already merges it) and a honeypot field; the portaliq page is created only after a clean validation.
5. **Journey designer** uses the same validator for `writes[]`, so author time and submit time cannot diverge.
6. **nextcloud-vue**: `CnFormPage` renders a 422 `findings[]` answer per field. One task, landed in nextcloud-vue as a backward-compatible minor.

## Rollback

Report mode is the rollback: switching the setting back to `report` lets every save through with findings shown.
