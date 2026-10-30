# Tasks: forms-fee-on-submit

- [ ] **T01**: Add `fee` (`amount` number > 0, `currency` ISO 4217 string default `EUR`, `description` string) to the `registrationForm` schema in `lib/Settings/register.d/50-registration-forms.json`, with a register version bump (REQ-OBRF-010). Verify: PHPUnit on the fragment accepting a fee and refusing a negative amount.
- [ ] **T02**: A "Fee on submit" section in `RegistrationFormEditor.vue` beside the sign-in level: switch, amount, currency, description; switching it on with a sign-in level below `substantial` shows why it cannot be saved (REQ-OBRF-010). Verify: vitest `tests/components/RegistrationFormEditorFee.spec.js`.
- [ ] **T03**: `lib/Service/RegistrationFormValidator.php`: refuse a form with a fee and `minTrust` missing or below `substantial` (order from `RegistrationFormSignInLevel::LEVELS`) (REQ-OBRF-010). Verify: PHPUnit cases.
- [ ] **T04**: `lib/Service/RegistrationFormPresenter.php` `serve()`: add `fee` (or null) beside `minTrust` (REQ-OBRF-010). Verify: PHPUnit that the served payload carries the fee.
- [ ] **T05**: Provider half for the portaliq lane: portaliq's form binding resolver takes the served `fee` as `portalFee` when the bound case type declares none. Drafted as `for-ruben/portaliq-registration-form-fee.md` in the build-all workspace; stays open until portaliq ships it.
- [ ] **T06**: English and Dutch strings in `l10n/en.json` and `l10n/nl.json`.
- [ ] **T07**: Live check after T05: a form with a fee of 25 EUR on the dev portal, signed in at `substantial`, submits, shows "Pay €25.00 now", and returns as paid with integriq's `log` provider.
- [ ] **T08**: When done, set `form-payment` to `built` in `openspec/parity/capabilities.json` and archive this change.
