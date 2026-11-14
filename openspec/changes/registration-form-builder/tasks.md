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

- [ ] 2.1 Mount `FormPageEditor.vue` for a `registrationForm` with the applies-to panel and a phase label per step (REQ-OBRF-002)
- [x] 2.2 Validate field names against the target schema on save; warn, do not block
      `RegistrationFormAuthoringService::save()` returns the findings; a draft saves with them. Publishing needs zero findings (decision 189, later than this task).

## 3. Leaf

- [x] 3.1 Add `lib/Integration/RegistrationFormLeafProvider.php` with `list` and draft `create`, registered on `RegisterLeafProvidersEvent` (REQ-OBRF-003)
      Already on development, registered by `BuildiqLeafRegistrationListener`.

## 4. Quality

- [x] 4.1 PHPUnit for the provider: published form per type, drafts scoped to the caller, `create` refuses a draft for another user
      `RegistrationFormLeafProviderTest` / `...ContractTest` (already on development); `RegistrationFormFragmentTest` now also pins the draft's owner-only rule, its retention and the seeded form.
- [ ] 4.2 Playwright `tests/e2e/registration-form-builder.spec.ts` for building a two-phase form and publishing it
- [ ] 4.3 Dutch and English strings; docs with screenshots
- [ ] 4.4 Hand the leaf id and the `{form, drafts}` shape to dossiq for `friendly-case-create-form`
