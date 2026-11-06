---
kind: code
depends_on: []
---

# Proposal: forms-fee-on-submit

## Why

Row `form-payment`: take an online payment as part of submitting a form. portaliq built the payment step in `intake-pay-on-submit`. A case type with `portalFee` shows "Pay {amount} now" after submit, forwards to the payment provider through integriq, and reads the payment state back. Only a live check and a validate run are open there. REQ-IPS-002 adds that a form with a fee needs the `substantial` sign-in level.

A form built in buildiq cannot ask for that fee. The registration form editor (`src/components/page-editor/fields/RegistrationFormEditor.vue`) sets the sign-in level that portaliq enforces (`minTrust`, REQ-OBRF-009) but has no fee. The fee lives only on portaliq's case type, outside the form the maker builds.

## What changes

- The registration form gets an optional fee: amount, currency (EUR by default) and the line the filer sees.
- Saving refuses a form with a fee whose sign-in level is below `substantial`, the same rule portaliq applies.
- `RegistrationFormPresenter::serve()` serves the fee beside `minTrust`.
- portaliq takes the served fee as the case type's `portalFee` when the case type declares none. This is the provider half, handed to the portaliq lane.

## Out of scope

- The payment flow, provider and return page, which portaliq and integriq own.
- Fees that vary with the answers: a calculated fee is a later step on `forms-live-values-and-checks`.
