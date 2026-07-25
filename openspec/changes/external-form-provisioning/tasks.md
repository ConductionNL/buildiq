## 1. Manifest declaration + validation

- [ ] 1.1 Define `runtime.externalForms[]` shape and validate it app-side in a new
      `src/services/manifestValidation/externalForms.js`, wired into
      `useManifestValidator.js` alongside the existing `validateTheme` /
      `validateWorkflowAttachments` calls (REQ-EFP-001). Vitest: every rejection case +
      lossless round-trip + byte-identical baseline when absent.

## 2. Builder UI

- [ ] 2.1 Add the "External access" section to
      `src/components/page-editor/FormPageEditor.vue` — visible/enabled only when
      `submitShape === 'endpoint'` and `config.submitEndpoint` matches
      `/api/objects/{register}/{schema}`; disabled with a hint otherwise (REQ-EFP-002).
- [ ] 2.2 Implement `src/dialogs/ExternalFormAccessDialog.vue` (standalone, modal-isolation
      rule): enable/disable public create, optional public read, optional
      `organisationScope`, optional track-link action, and a URL-preview panel
      (REQ-EFP-002). All `NcSelect`s carry `inputLabel`; i18n keys under
      `openbuild.externalForm.*` + nl translations.
- [ ] 2.3 Vitest for `ExternalFormAccessDialog.vue`: enable flow, disable flow, save
      payload shape, Portaliq-unavailable hint rendering.

## 3. Provisioning service

- [ ] 3.1 Implement `src/services/externalFormProvisioningService.js::enable()` — GET
      schema, merge-append `"public"` to `authorization.create` (+ `read` when
      `publicRead`), PATCH the full merged `authorization` object (REQ-EFP-003). Vitest:
      existing groups preserved; read-add case; never sends a partial `authorization`
      fragment.
- [ ] 3.2 Implement `::provisionPortalPage()` — POST (create) or PUT (update, matched by
      stored `objectId`) the `portaliq`/`portalPage` object with an `anonymous: true`
      `type:create` action targeting the toggle's `(register, schema)`; catch
      schema-not-found and degrade per REQ-EFP-004 (skip write, `portalPage: null`, hint
      surfaced). Vitest: create path, update path, degrade path.
- [ ] 3.3 Implement `::disable()` — GET schema, remove `"public"` from `create`/`read`
      (only entries this toggle added) leaving all other groups untouched, PATCH; PUT the
      linked `portalPage.status` to `"draft"` when one exists (REQ-EFP-005). Vitest:
      selective removal; no-linked-portalPage no-op case.

## 4. Track-link action

- [ ] 4.1 Implement `src/composables/useTrackLinkAction.js::mintTrackLink(register,
      schema, objectId, opts)` calling
      `POST /api/objects/{register}/{schema}/{id}/integrations/shares`
      `{type:"public-token", ...opts}`; returns the resolved public URL (REQ-EFP-006).
      Vitest: request shape, success response mapping, error surfacing.
- [ ] 4.2 Wire the "Mint track-link" action into whatever existing data-register object
      list/detail view OpenBuild renders (locate the current generic object-row action
      slot during apply), gated on `trackLinkAction.enabled` for that schema's
      `externalForms` entry (REQ-EFP-006). Vitest: action present/absent per flag.

## 5. Verification

- [ ] 5.1 Playwright e2e: UI-driven against localhost:8080 — configure external access on
      a seeded Form page, save, assert the schema's `authorization.create` now contains
      `public` (via a direct OR API read) and — when Portaliq's schema is present in the
      test env — a `portalPage` object exists; disable and assert both are reverted/set to
      draft (REQ-EFP-002 through REQ-EFP-005). If Portaliq's `portal-page-provisioning`
      has not yet shipped in the test environment, the Portaliq assertions are
      `@e2e exclude` with that reason, not skipped silently.
- [ ] 5.2 Newman: pin the three consumed endpoints' shapes —
      `PATCH /api/schemas/{id}` merge behaviour, `POST /api/objects/portaliq/portalPage`
      (skipped/marked pending if the schema is absent in the collection's target env), and
      `POST /api/objects/{register}/{schema}/{id}/integrations/shares` returning a
      `{token, url}` shape (REQ-EFP-003, REQ-EFP-004, REQ-EFP-006).
- [ ] 5.3 Quality gates + regression: `npm run lint` + vitest green; hydra gates
      (modal-isolation, nc-input-labels, e2e-coverage, no-phantom-cross-app-rpc — confirm
      the three consumed OR/Portaliq routes are real, not phantom); grep confirms no
      `#[PublicPage]`, no `OCA\OpenRegister`/`OCA\Portaliq` import, and no `ShareToken`
      file anywhere in the repo (REQ-EFP-007). Regression: an app with no
      `externalForms` entries serializes identically to baseline.
