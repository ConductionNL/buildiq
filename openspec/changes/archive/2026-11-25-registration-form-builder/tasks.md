## 1. Schemas

- [x] 1.1 Add `registrationForm` and `formDraft` to `lib/Settings/openbuild_register.json` with uniqueness, owner-only RBAC on drafts and 30-day retention (REQ-OBRF-001)
      Both schemas live in `lib/Settings/register.d/50-registration-forms.json` (merged into the buildiq
      register). Uniqueness is the later per-(type, audience, channel) default rule of `forms-per-case-type`,
      enforced by `RegistrationFormAuthoringService`. `formDraft` now carries owner-only authorization
      (create by any signed-in user, read only where `userId` is the caller, update/delete left to the owner)
      and `x-openregister-archival.retention.default: P30D`; register 0.15.0.
- [x] 1.2 Seed one published registration form for `dossiq/case` `caseType = bouwvergunning` with two phases and one `visibleWhen` rule
      In the demo data (`lib/Settings/buildiq_mock_register.json`): phases Aanvrager and Bouwwerk, the company name shown when the applicant is a company.

## 2. Builder

- [x] 2.1 Mount `FormPageEditor.vue` for a `registrationForm` with the applies-to panel and a phase label per step (REQ-OBRF-002)
      The registration form already has its own editor, `RegistrationFormEditor.vue` (from `forms-per-case-type`),
      so it reuses the form page sub-editor's parts rather than `FormPageEditor.vue` itself: an "Applies to" block
      naming the bound app/register/schema and type value, `FormStepsManager.vue` with a phase label per step
      (`withPhase`), a `VisibleWhenBuilder` rule per question and the save-for-later switch.
- [x] 2.2 Validate field names against the target schema on save; warn, do not block
      `RegistrationFormAuthoringService::save()` returns the findings; a draft saves with them. Publishing needs zero findings (decision 189, later than this task).

## 3. Leaf

- [x] 3.1 Add `lib/Integration/RegistrationFormLeafProvider.php` with `list` and draft `create`, registered on `RegisterLeafProvidersEvent` (REQ-OBRF-003)
      Already on development, registered by `BuildiqLeafRegistrationListener`.

## 4. Quality

- [x] 4.1 PHPUnit for the provider: published form per type, drafts scoped to the caller, `create` refuses a draft for another user
      `RegistrationFormLeafProviderTest` / `...ContractTest` (already on development); `RegistrationFormFragmentTest` now also pins the draft's owner-only rule, its retention and the seeded form.
- [x] 4.2 Playwright `tests/e2e/registration-form-builder.spec.ts` for building a two-phase form and publishing it
      Saves the scenario's form through the builder's route as a draft, publishes it, reads it back (one form, both
      phase labels, the `visibleWhen` rule intact) and checks that a caller with no account cannot publish. The
      publish skips with the destination's reason on a stack without dossiq. Written, not run here (no live stack).
- [x] 4.3 Dutch and English strings; docs with screenshots
  > 25 Nov 2026: `npm run test:l10n` (every used key in `l10n/en.json`) and `npm run test:l10n:parity` (nl at full parity) both pass. `docs/registration-form-builder.md` now shows the Applies to panel, the "Forms for this case type" list and an open form (`docs/static/screenshots/registration-form-builder/`). Taken in Chromium at 2x from a live Nextcloud 34 with OpenRegister main, on a demo app `case-intake` whose detail page targets a `case` schema; no real data.
- [x] 4.4 Hand the leaf id and the `{form, drafts}` shape to dossiq for `friendly-case-create-form`
  > Done 25 Nov: dossiq ConductionNL/dossiq#6918 (competitor-parity-2026-09 task 15, shapes in buildiq-consumer-halves.md), task 15.4: leaf id `buildiq-registration-form` and the `{items, total, nextCursor, drafts}` envelope with an example form, for `friendly-case-create-form`.
