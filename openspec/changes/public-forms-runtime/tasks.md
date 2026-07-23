## 1. Schema

- [ ] 1.1 Add the `ShareToken` schema to `lib/Settings/openbuild_register.json` (applicationId, pageId, token, mode, boundObjectId, expiresAt, passwordHash, revoked, allowedPrefillFields, honeypotField, requireEmailVerification); bump schema version.
- [ ] 1.2 Add the optional `public` block to the page-config shape consumed by the page designer (enabled, mode, allowedPrefillFields, requireEmailVerification) — validated app-side, not in the external manifest schema.

## 2. Token service

- [ ] 2.1 `ShareTokenService::issue()` — generates opaque random token + honeypot field name, rejects issue against a page without `config.public.enabled: true`, enforces mandatory `expiresAt` for `mode: edit`.
- [ ] 2.2 `ShareTokenService::revoke()` / `resolve()` — resolve validates not-revoked, not-expired, password match when set.
  - acceptance: revoke takes effect immediately for subsequent resolves
  - acceptance: resolve never returns a token payload for another Application's page

## 3. Public render controller

- [ ] 3.1 `PublicFormController::render(token)` (`#[PublicPage]`) returns a manifest fragment containing only the bound page/schema/widgets.
- [ ] 3.2 Prefill-from-URL: map query params present in `allowedPrefillFields` onto the returned form's initial values; ignore all others.
- [ ] 3.3 Edit-mode render: when `boundObjectId` is set, include that object's current values as the form's initial state.

## 4. Anonymous submission

- [ ] 4.1 `PublicSubmissionService::submit()` (`#[PublicPage]` `#[NoCSRFRequired]` `#[AnonRateLimit]`) — honeypot check (silent 200 no-op on trip), schema validation, owner-context OR write via ObjectService (not the client objects API).
- [ ] 4.2 `mode: edit` submission updates `boundObjectId` instead of creating; rejects if the object no longer resolves.
- [ ] 4.3 `requireEmailVerification` flag: accept-then-flag the created/updated object with `emailVerified: false`.

## 5. Routes

- [ ] 5.1 Register the public render + submission routes in `appinfo/routes.php` as a distinct group, before the SPA catch-all; confirm auth attributes match route-auth/semantic-auth gates.

## 6. Frontend

- [ ] 6.1 `src/dialogs/ShareTokenDialog.vue` — create/revoke/copy-link/expiry editing, wired from the page designer toolbar and `AppSettingsModal`.
- [ ] 6.2 Public bootstrap entry point (no Pinia auth-store assumption) mounting `CnAppRoot` for the single returned page, with the honeypot field rendered `aria-hidden` + off-screen (not `display:none`).
- [ ] 6.3 Password prompt UI for password-protected tokens.

## 7. Tests

- [ ] 7.1 Newman: token resolve (valid/expired/revoked/password), render scoped to bound page only, submit create/edit, honeypot no-op, rate-limit 429.
- [ ] 7.2 PHPUnit: `ShareTokenService`, `PublicSubmissionService` (owner-context write, honeypot, edit-vs-create branch).
- [ ] 7.3 Playwright: fill and submit a public form anonymously (no session) end to end; edit-link opens prefilled and updates the record.

## 8. Verify

- [ ] 8.1 `composer check:strict` and hydra mechanical gates (route-auth, semantic-auth, csrf-cochange, no-admin-idor) green on the diff.
- [ ] 8.2 `openspec validate "public-forms-runtime"` passes and `openspec status` shows all artifacts complete before archiving.
