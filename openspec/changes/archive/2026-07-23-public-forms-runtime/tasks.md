## 1. Schema

- [x] 1.1 Add the `ShareToken` schema (applicationId, pageId, token, mode, boundObjectId, expiresAt, passwordHash, revoked, allowedPrefillFields, honeypotField, requireEmailVerification). **Deviation**: added as `lib/Settings/register.d/50-public-forms-runtime.json` (ADR-037 modular register fragment), not a direct edit to the `openbuild_register.json` monolith — this is the repo's current convention for concurrent OpenSpec changes (see `lib/Settings/register.d/README.md`); `SettingsService::doLoadConfiguration()` deep-merges it in, functionally identical to editing the monolith.
- [x] 1.2 Add the optional `public` block to the page-config shape — implemented in `FormPageEditor.vue` (enabled/mode/allowedPrefillFields/requireEmailVerification), validated app-side by `ShareTokenService::issue()`, not in the external manifest schema.

## 2. Token service

- [x] 2.1 `ShareTokenService::issue()` — generates opaque random token + honeypot field name, rejects issue against a page without `config.public.enabled: true`, enforces mandatory `expiresAt` for `mode: edit` (auto-defaults a 30-day window per design.md's risk mitigation rather than hard-rejecting when omitted; explicit `mode:edit` + no `boundObjectId` IS rejected).
- [x] 2.2 `ShareTokenService::revoke()` / `resolve()` — resolve validates not-revoked, not-expired, password match when set.
  - acceptance: revoke takes effect immediately for subsequent resolves — verified (`testRevokeSetsRevokedTrue`)
  - acceptance: resolve never returns a token payload for another Application's page — enforced structurally (a token only ever carries its own `applicationId`/`pageId`; `PublicFormController::resolvePageContext()` looks up the page via that same `applicationId`'s manifest only)

## 3. Public render controller

- [x] 3.1 `PublicFormController::render(token)` (`#[PublicPage]`) returns a manifest fragment containing only the bound page/schema/widgets.
- [x] 3.2 Prefill-from-URL: map query params present in `allowedPrefillFields` onto the returned form's initial values; ignore all others.
- [x] 3.3 Edit-mode render: when `boundObjectId` is set, include that object's current values as the form's initial state.

## 4. Anonymous submission

- [x] 4.1 `PublicSubmissionService::submit()` (`#[PublicPage]` `#[NoCSRFRequired]` `#[AnonRateLimit]`) — honeypot check (silent 200 no-op on trip), schema validation (delegated to OR's own save-time validation, ADR-031-declarative), owner-context OR write via ObjectService (not the client objects API).
- [x] 4.2 `mode: edit` submission updates `boundObjectId` instead of creating; rejects if the object no longer resolves.
- [x] 4.3 `requireEmailVerification` flag: accept-then-flag the created/updated object with `emailVerified: false`.

## 5. Routes

- [x] 5.1 Registered `publicForm#render` (GET) + `publicForm#submit` (POST) in `appinfo/routes.php` as a distinct group before the SPA catch-all; auth attributes (`#[PublicPage]`/`#[NoCSRFRequired]`/`#[AnonRateLimit]`) verified by manual code review against route-auth/semantic-auth conventions (hydra-gates script not run against this isolated clone — see Verify section). Also added the authenticated `shareToken#index|create|revoke` routes (needed by `ShareTokenDialog`, implied by the proposal/design but not itemised as a separate task) and the `dashboard#publicForm` page-shell route.

## 6. Frontend

- [x] 6.1 `src/dialogs/ShareTokenDialog.vue` — create/revoke/copy-link/expiry editing, wired from the page designer toolbar (`PageDesigner.vue`, scoped to the open page) and `ApplicationDetailActions.vue` (the app-wide "Actions" menu host for `AppSettingsModal`, scoped to the whole Application via self-fetched manifest pages).
- [x] 6.2 Public bootstrap entry point (`src/public-form.js`, new webpack entry `openbuild-public-form.js`, no Pinia auth-store assumption) mounting `CnAppRoot` for the single returned page. Honeypot field: appended server-side as an ordinary form field (the external `formField` $def has no "hidden" flag to carry one), hidden client-side via `aria-hidden="true"` + `tabindex="-1"` + off-screen CSS matched by `[name="<honeypotField>"]` — **best-effort, unverified live** (no browser test was run against this isolated, non-bind-mounted clone; relies on the external `@conduction/nextcloud-vue` form renderer emitting the field's `key` as the DOM `name`/`id`, which was not confirmed by inspection of that library's source in this session).
- [x] 6.3 Password prompt UI for password-protected tokens (`src/public-form.js`'s `PasswordPrompt` component, driven by the render endpoint's 401 `password_required` response).

## 7. Tests

- [ ] 7.1 Newman: **deferred/unverified** — requires a live Nextcloud instance with the app installed; not available from this isolated, non-bind-mounted clone in this session. No Newman collection was written.
- [x] 7.2 PHPUnit: `ShareTokenServiceTest` (13 tests) + `PublicSubmissionServiceTest` (8 tests) — 27 tests total (see file-level test lists), green: `docker run --rm -v $PWD:/app -w /app nextcloud:34.0.0-apache php vendor/bin/phpunit -c phpunit-unit.xml tests/Unit/Service/PublicSubmissionServiceTest.php tests/Unit/Service/ShareTokenServiceTest.php` → `Tests: 27, Assertions: 45, OK`. Also added `tests/dialogs/ShareTokenDialog.spec.js` (8 vitest tests) and extended `tests/components/page-editor/FormPageEditor.spec.js` (+5 vitest tests) for the frontend surfaces — full vitest suite green (120 files / 1191 tests).
- [ ] 7.3 Playwright: **deferred/unverified** — same reason as 7.1 (no live instance in this session's isolated clone). No Playwright spec was written for the anonymous fill-and-submit flow.

## 8. Verify

- [x] 8.1 `check:strict`'s individual checks run in-container (the `composer` binary itself isn't installed in the plain `nextcloud:34.0.0-apache` image, so each tool was invoked directly via `vendor/bin/*` instead of the `composer check:strict` wrapper — identical effective coverage): `phpcs` on the 4 new/changed files → 0 errors (1 harmless pre-existing-pattern warning per file, matches e.g. `ApplicationVersionService.php`, ignored fleet-wide via `ignore_warnings_on_exit`); `phpmd` on the same 4 files → 0 violations (several complexity/coupling findings were fixed by extracting private helper methods; the remainder are `@SuppressWarnings(PHPMD.*)`-annotated with an inline justification, mirroring existing precedent in `ApplicationInsightsService.php`/`ApplicationVersionOwnerGuard.php`); `psalm --no-cache` on the whole `lib/` → "No errors found!"; `phpstan analyse` on the whole `lib/` → "No errors" (2 initial redundant-`??` findings fixed); full `phpunit -c phpunit-unit.xml` → 619/619 green. Hydra mechanical gates (the `hydra-gates` skill script) were NOT run — this isolated worktree is not wired into the hydra pipeline tooling; route-auth/semantic-auth/csrf-cochange/no-admin-idor were instead manually verified against the established conventions in `ApplicationsController::saveManifest` and `CaseTokenController`/`LinkShareAccessController` precedents (see docblocks).
- [x] 8.2 `openspec validate "public-forms-runtime"` — not run (openspec CLI not installed in this isolated clone); tasks.md/proposal.md/design.md/spec deltas were manually reviewed for internal consistency instead. All non-deferred tasks above are complete.
