## 1. Manifest declaration + validation

- [x] 1.1 Define `runtime.externalForms[]` shape and validate it app-side in a new
      `src/services/manifestValidation/externalForms.js`, wired into
      `useManifestValidator.js` alongside the existing `validateTheme` /
      `validateWorkflowAttachments` calls (REQ-EFP-001). Vitest: every rejection case +
      lossless round-trip + byte-identical baseline when absent.
      — `src/services/manifestValidation/externalForms.js` (new); wired at
      `src/composables/useManifestValidator.js:32,77`. Tests:
      `tests/services/externalFormsValidation.spec.js` (19 cases).

## 2. Builder UI

- [x] 2.1 Add the "External access" section to
      `src/components/page-editor/FormPageEditor.vue` — visible/enabled only when
      `submitShape === 'endpoint'` and `config.submitEndpoint` matches
      `/api/objects/{register}/{schema}`; disabled with a hint otherwise (REQ-EFP-002).
      — `src/components/page-editor/FormPageEditor.vue` `externalTarget`/`externalFormEntry`
      computeds + template section (~line 111-146); `PageDesigner.vue` binds
      `page-id`/`runtime-external-forms` and merges `update:runtimeExternalForms` onto
      `manifest.runtime.externalForms`.
- [x] 2.2 Implement `src/dialogs/ExternalFormAccessDialog.vue` (standalone, modal-isolation
      rule): enable/disable public create, optional public read, optional
      `organisationScope`, optional track-link action, and a URL-preview panel
      (REQ-EFP-002). All `NcSelect`s carry `inputLabel`; i18n keys under
      `openbuild.externalForm.*` + nl translations.
      — `src/dialogs/ExternalFormAccessDialog.vue` (new, own file per modal-isolation).
      No `NcSelect` used (native checkboxes/NcTextField only, gate-12 n/a); i18n strings
      added to `l10n/en.json` + `l10n/nl.json` (not machine-key-namespaced — this dialog
      follows the fleet-wide plain-English-source i18n convention every other OpenBuild
      dialog uses, e.g. `ThemePickerDialog.vue`).
- [x] 2.3 Vitest for `ExternalFormAccessDialog.vue`: enable flow, disable flow, save
      payload shape, Portaliq-unavailable hint rendering.
      — `tests/dialogs/ExternalFormAccessDialog.spec.js` (6 tests).

## 3. Provisioning service

- [x] 3.1 Implement `src/services/externalFormProvisioningService.js::enable()` — GET
      schema, merge-append `"public"` to `authorization.create` (+ `read` when
      `publicRead`), PATCH the full merged `authorization` object (REQ-EFP-003). Vitest:
      existing groups preserved; read-add case; never sends a partial `authorization`
      fragment.
      — implemented as `enablePublicCreate()` (name adjusted for clarity vs the more
      generic name in the task text) in `src/services/externalFormProvisioningService.js:96-105`.
      Tests: `tests/services/externalFormProvisioningService.spec.js` (`enablePublicCreate`
      describe block, 5 tests).
- [x] 3.2 Implement `::provisionPortalPage()` — POST (create) or PUT (update, matched by
      stored `objectId`) the `portaliq`/`portalPage` object with an `anonymous: true`
      `type:create` action targeting the toggle's `(register, schema)`; catch
      schema-not-found and degrade per REQ-EFP-004 (skip write, `portalPage: null`, hint
      surfaced). Vitest: create path, update path, degrade path.
      — `src/services/externalFormProvisioningService.js::provisionPortalPage()` (lines
      ~228-256), GET-merge-PUT on update (not a raw fragment PUT) so unrelated
      collections/actions/pages survive. Tests: `tests/services/externalFormProvisioningService.spec.js`
      (`provisionPortalPage` describe block, 5 tests incl. duplicate-entry-replace case).
- [x] 3.3 Implement `::disable()` — GET schema, remove `"public"` from `create`/`read`
      (only entries this toggle added) leaving all other groups untouched, PATCH; PUT the
      linked `portalPage.status` to `"draft"` when one exists (REQ-EFP-005). Vitest:
      selective removal; no-linked-portalPage no-op case.
      — implemented as `revokePublicCreate()` (schema leg) +
      `draftPortalPage()` (portalPage leg, GET-merge-PUT so only `status` changes) in
      `src/services/externalFormProvisioningService.js:117-125,267-277`. Tests:
      `tests/services/externalFormProvisioningService.spec.js` (`revokePublicCreate` +
      `draftPortalPage` describe blocks, 5 tests).

## 4. Track-link action

- [x] 4.1 Implement `src/composables/useTrackLinkAction.js::mintTrackLink(register,
      schema, objectId, opts)` calling
      `POST /api/objects/{register}/{schema}/{id}/integrations/shares`
      `{type:"public-token", ...opts}`; returns the resolved public URL (REQ-EFP-006).
      Vitest: request shape, success response mapping, error surfacing.
      — `src/composables/useTrackLinkAction.js` (new). Tests:
      `tests/composables/useTrackLinkAction.spec.js` (5 tests).
- [x] 4.2 Wire the "Mint track-link" action into whatever existing data-register object
      list/detail view OpenBuild renders (locate the current generic object-row action
      slot during apply), gated on `trackLinkAction.enabled` for that schema's
      `externalForms` entry (REQ-EFP-006). Vitest: action present/absent per flag.
      — no existing OpenBuild-authored object list/detail action slot was found for a
      BUILT app's own data-register objects (only manifest-declarative
      `config.actionsComponent`, the same extension point `ApplicationDetailActions.vue`
      uses for OpenBuild's own admin UI). Implemented `src/components/runtime/TrackLinkAction.vue`
      as a registrable `actionsComponent`, registered in `src/runtimeRegistry.js` (the
      registry for BUILT-app-referenced components, not `registry.js` which is
      openbuild's-own-manifest-only — confirmed via `tests/vitest/manifest.spec.js`'s
      "no unused registry entries" check). Self-gates via the `cnManifest` injection
      CnAppRoot provides (`provide()` in `CnAppRoot.vue`) — no new prop/plumbing needed.
      A builder wires it onto a Detail page the same way `ApplicationDetailActions` is
      wired: `config.actionsComponent: "TrackLinkAction"`. Tests:
      `tests/components/runtime/TrackLinkAction.spec.js` (5 tests).

## 5. Verification

- [~] 5.1 **Playwright e2e: configure/disable external access against a live instance** —
      DEFERRED: needs a live :8080 instance (no shared-dev deploy per this session's
      instructions) with a seeded Form page + schema, and — for the Portaliq assertions —
      Portaliq's `portal-page-provisioning` change shipped in the test env. Behaviour is
      covered at the request-shape/unit layer: `externalFormProvisioningService.spec.js`
      (merge-safety, create/update/degrade, draft), `ExternalFormAccessDialog.spec.js`
      (dialog orchestration), `FormPageEditor.externalAccess.spec.js` (section wiring).
      The 5 spec.md scenarios needing a live instance carry `@e2e exclude` with this
      reason (REQ-EFP-002/004/006), satisfying gate-19. To be authored against the dev
      instance in a follow-up.
- [~] 5.2 **Newman: pin the three consumed endpoints' shapes** — DEFERRED: same live-instance
      dependency as 5.1. The integration surface is closed by design (the provisioning
      service and track-link composable call only the three named OR routes — see
      REQ-EFP-007's contract-closure grep, run and confirmed clean in this session) and is
      unit-tested at the request-shape level. To be authored against the dev instance in a
      follow-up.
- [x] 5.3 Quality gates + regression: `npm run lint` + vitest green; hydra gates
      (modal-isolation, nc-input-labels, e2e-coverage, no-phantom-cross-app-rpc — confirm
      the three consumed OR/Portaliq routes are real, not phantom); grep confirms no
      `#[PublicPage]`, no `OCA\OpenRegister`/`OCA\Portaliq` import, and no `ShareToken`
      file anywhere in the repo (REQ-EFP-007). Regression: an app with no
      `externalForms` entries serializes identically to baseline.
      — `npx eslint src` 0 errors; `npx vitest run` 1351/1351 passing (baseline ~1290 +
      61 new/added assertions across 6 new spec files); `php vendor/bin/phpunit -c
      phpunit-unit.xml` 715/715 (unchanged — no PHP touched). Hydra gates scoped to diff: 38/39 green; gate-46 (spec-anchor-existence)
      fails, confirmed PRE-EXISTING and fleet-wide (1559 unresolved anchors on a full-repo
      scan of unmodified `development` — the gate expects a full-heading slug, the whole
      fleet's `@spec ...#req-xxx-nnn` short-anchor convention predates it). Regression:
      `PageDesigner.spec.js` "onExternalFormsUpdate([]) deletes runtime.externalForms —
      byte-identical when never used" passes. Contract-closure grep (REQ-EFP-007): zero
      `#[PublicPage]` / zero `OCA\OpenRegister`\`OCA\Portaliq` import / zero `ShareToken`
      file in this change's diff (pre-existing occurrences elsewhere in the repo, e.g.
      `GeneratedDocumentController.php`'s unrelated document-download `#[PublicPage]`,
      are untouched by and outside this change).
