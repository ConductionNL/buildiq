## 1. VersionPromotionService — strategy switch, OR-lock, semver copy, on-failure flip

- [x] 1.1 Create `lib/Service/VersionPromotionService.php` with constructor injecting
      OR's `ObjectService`, OR's `RegisterService` (or equivalent surface for
      schema-import / register-merge), OR's lock primitive helper, `IUserSession`,
      and `LoggerInterface`. ADR-022 — no app-local DB access; all data ops go via OR.
- [x] 1.2 Implement `defaultStrategyFor(Application $app, ApplicationVersion $target):
      string` as a static pure function returning `"migrate-existing-data"` when
      `$target->getUuid() === $app->getProductionVersion()?->getUuid()`, else
      `"start-with-source-data"` (spec REQ-OBVP-011).
- [x] 1.3 Implement `promote(ApplicationVersion $source, string $strategy): array`
      as the main entry point:
      - Resolve the target via `$source->getPromotesTo()`. If null, throw a
        `\OCP\AppFramework\Http\NoPromoteTargetException` (or equivalent 422-mapped
        exception) carrying `code: "no_promote_target"` (spec REQ-OBVP-001).
      - Validate `$strategy` against the closed set; throw a 400-mapped exception
        with `code: "invalid_strategy"` for anything else (spec REQ-OBVP-001).
      - Acquire OR object lock on the target row; on contention, throw a 409-mapped
        exception carrying `{code, lockedBy, expiresAt}` (spec REQ-OBVP-006).
      - Run the strategy switch inside a `try { … } catch { … } finally { unlock }`.
      - On success, return the updated target ApplicationVersion as an array.
- [x] 1.4 Implement `runStartWithSourceData(ApplicationVersion $source,
      ApplicationVersion $target): void` per spec REQ-OBVP-002: schema-import →
      delete-all-target-rows → copy-source-rows → write manifest+semver → save.
- [x] 1.5 Implement `runMigrateExistingData(ApplicationVersion $source,
      ApplicationVersion $target): void` per spec REQ-OBVP-003: schema-import (OR
      handles column-level migration) → leave target rows untouched → write
      manifest+semver → save.
- [x] 1.6 Implement `runEmptyStart(ApplicationVersion $source, ApplicationVersion
      $target): void` per spec REQ-OBVP-004: lock-then-delete-target-rows →
      schema-import → write manifest+semver → save. The destructive-confirmation
      gate is UI-only; the backend trusts that the client has obtained admin intent.
- [x] 1.7 Implement `forwardSchemaSetToOR(ApplicationVersion $source,
      ApplicationVersion $target): void` calling OR's schema-import / register-merge
      API on `$target->getRegister()` with `$source`'s schema set (spec REQ-OBVP-005).
      Map OR's failure response into a captured exception that the surrounding `try`
      block can route to the on-failure flow (spec REQ-OBVP-009).
- [x] 1.8 Implement `applyManifestAndSemver(ApplicationVersion $source,
      ApplicationVersion $target): void` writing `$source->getManifest()` and
      `$source->getSemver()` onto `$target`, then saving via OR's object service.
      No additional bump (spec REQ-OBVP-008).
- [x] 1.9 Implement `handlePromotionFailure(ApplicationVersion $target, string
      $strategy, \Throwable $e): never` (PHP 8.1 `never`-returning helper) per spec
      REQ-OBVP-009: set `$target->setStatus('archived')`, set
      `_self.promotionFailedAt = <ISO-8601>`, save, then re-throw a 500-mapped
      exception with `{code: "promotion_failed", strategy, message}`. Ensure the
      `finally` block in `promote()` still releases the OR lock even though this
      method re-throws.
- [x] 1.10 PHPDoc + SPDX header on the file (license/copyright per Hydra
      `hydra-gate-spdx`). No forbidden patterns (`var_dump`, `die`, `error_log`,
      `print_r`, `dd`, `dump`).

## 2. VersionPromotionController — thin pass-through

- [x] 2.1 Create `lib/Controller/VersionPromotionController.php` extending
      `ApiController`. Constructor injects `VersionPromotionService`, OR's
      `ObjectService` (to load source ApplicationVersion + parent Application),
      `IUserSession`, and the existing per-app RBAC helper.
- [x] 2.2 Implement `promote(string $appUuid, string $versionUuid): JSONResponse`:
      - Load the parent Application by `$appUuid`; 404 on missing.
      - Load the source ApplicationVersion by `$versionUuid` (and verify its
        `application` relation points at `$appUuid`; 404 on mismatch).
      - Run the per-application RBAC check (owner OR editor — NC admin NOT
        auto-granted per spec REQ-OBVP-007); return 403 with
        `{code: "insufficient_permission"}` on failure.
      - Read `$request->getParam('strategy')` from the JSON body.
      - Delegate to `VersionPromotionService::promote($source, $strategy)`.
      - Return `200 application/json` with the updated target.
- [x] 2.3 Map the service's exception classes to HTTP responses:
      - `NoPromoteTargetException` → 422 `{code: "no_promote_target"}`
      - `InvalidStrategyException` → 400 `{code: "invalid_strategy"}`
      - `VersionLockedException` → 409 `{code: "version_locked", lockedBy,
        expiresAt}`
      - `InsufficientPermissionException` → 403 `{code: "insufficient_permission"}`
      - `PromotionFailedException` → 500 `{code: "promotion_failed", strategy,
        message}`
- [x] 2.4 Annotate `promote()` with `#[NoAdminRequired]` (the auth is per-app RBAC,
      not Nextcloud admin) per spec REQ-OBVP-001 and the `hydra-gate-route-auth`
      rule.
- [x] 2.5 PHPDoc + SPDX header. No forbidden patterns.

## 3. Route registration

- [x] 3.1 Open `appinfo/routes.php` and add the entry:
      `['name' => 'VersionPromotion#promote', 'url' => '/api/applications/{appUuid}/versions/{versionUuid}/promote', 'verb' => 'POST']`.
- [ ] 3.2 Verify the route resolves at runtime via
      `php occ route:list 2>&1 | grep promote` after an `apache2ctl graceful` in
      the dev container.

## 4. PromoteVersionDialog.vue — modal component

- [x] 4.1 Create `src/dialogs/PromoteVersionDialog.vue` as a standalone `.vue` file
      (ADR-004 modal-isolation rule — NOT inline in any parent). Use `<NcDialog>`
      from `@nextcloud/dialogs` as the modal primitive.
- [x] 4.2 Declare props: `sourceVersion: { type: Object, required: true }` and
      `targetVersion: { type: Object, default: null }`. If `targetVersion` is null,
      render a no-target body with a Cancel-only footer per spec REQ-OBVP-010.
- [x] 4.3 Render the summary header: `Promote {{ sourceVersion.name }} →
      {{ targetVersion.name }}` plus a sub-line showing both `register` names.
- [x] 4.4 Render the three-strategy radio group with the values
      `start-with-source-data | migrate-existing-data | empty-start`. Each radio
      is paired with a proper input label (per `hydra-gate-nc-input-labels` —
      ADR-004 input-label rule) and a one-paragraph inline description.
- [x] 4.5 Compute the default strategy in `data()` or `mounted()` via a JS helper
      `defaultStrategyFor(application, targetVersion)` mirroring the PHP pure
      function (spec REQ-OBVP-011). The helper lives in
      `src/dialogs/PromoteVersionDialog.vue` or a sibling helper imported by it.
- [x] 4.6 For `empty-start` only: render a "Type the application slug to confirm"
      text input. Bind a computed `isDestructiveGateMet` that returns
      `selectedStrategy !== 'empty-start' || typedSlug === application.slug` (exact
      case-sensitive byte-equal match). Disable the Confirm button when
      `isDestructiveGateMet === false` (spec REQ-OBVP-010).
- [x] 4.7 On Confirm click, emit `confirm` with payload `{strategy:
      selectedStrategy}`. On Cancel click or dialog close, emit `cancel` with no
      payload. The dialog SHALL NOT call the backend endpoint itself.
- [x] 4.8 i18n strings (per nl/en minimum — memory rule `i18n-requirement`): all
      visible strings (radio labels, descriptions, button labels, destructive
      confirmation hint) routed via `t('openbuilt', '…')`. Add the new keys to
      `l10n/en.json` and `l10n/nl.json`.
- [x] 4.9 Component unit test
      `src/dialogs/__tests__/PromoteVersionDialog.spec.js` covering:
      mounts with production target → default is `migrate-existing-data`; mounts
      with mid-chain target → default is `start-with-source-data`; selecting
      `empty-start` disables Confirm until slug typed; typing slug enables
      Confirm; `confirm` event emitted with chosen strategy; `cancel` event
      emitted on cancel.

## 5. Destructive-confirmation gate — UI test (REQUIRED)

- [x] 5.1 Playwright test in the docs-site / journeydoc capture spec covering: the
      admin opens the dialog (mocked or via a temporary call site), selects
      `empty-start`, and the Confirm button is **disabled**. The admin types a
      WRONG slug (e.g. `wrong-slug`) and the Confirm button is **still disabled**.
      The admin clears the input and types the EXACT app slug (`hello-world`) and
      the Confirm button **enables**. Clicking Confirm fires the `confirm` event
      with `{strategy: "empty-start"}`. (Spec REQ-OBVP-010, locked prompt
      constraint.)
- [x] 5.2 The test SHALL assert that for `start-with-source-data` and
      `migrate-existing-data` the Confirm button is enabled by default (no
      destructive-confirmation gate applies).

## 6. Unit tests — VersionPromotionService

- [x] 6.1 Create `tests/Unit/Service/VersionPromotionServiceTest.php`.
- [x] 6.2 Test `defaultStrategyFor` — production target returns
      `migrate-existing-data`; mid-chain target returns `start-with-source-data`;
      function never returns `empty-start` (spec REQ-OBVP-011).
- [x] 6.3 Test `promote` — happy-path for `start-with-source-data`: source has 5
      rows, target has 3 rows; after promote, target has the 5 source rows, target
      has source's manifest + semver (spec REQ-OBVP-002).
- [x] 6.4 Test `promote` — happy-path for `migrate-existing-data`: target's 10
      rows preserved (modulo OR's mocked schema-migration column-level changes);
      target has source's manifest + semver (spec REQ-OBVP-003).
- [x] 6.5 Test `promote` — happy-path for `empty-start`: target's 7 rows wiped;
      target's register has source's schema set; target has source's manifest +
      semver (spec REQ-OBVP-004).
- [x] 6.6 Test `promote` — `sourceVersion.promotesTo === null` raises 422 with
      `code: "no_promote_target"` (spec REQ-OBVP-001).
- [x] 6.7 Test `promote` — unknown / missing strategy raises 400 with
      `code: "invalid_strategy"` (spec REQ-OBVP-001).
- [x] 6.8 Test `promote` — semver inheritance: target's `semver` equals source's
      `semver` for production target AND for mid-chain target (spec REQ-OBVP-008).
- [x] 6.9 Test `promote` — on-failure handling: mock OR schema-import to throw;
      assert target's status is `archived`, target's `_self.promotionFailedAt` is
      set, OR lock is released, exception thrown is a 500-mapped exception with
      `{code: "promotion_failed", strategy, message}` (spec REQ-OBVP-009).
- [x] 6.10 Test `promote` — source register is unmodified on failure (the source
      is read-only throughout) (spec REQ-OBVP-009).
- [x] 6.11 Test `promote` — OR lock contention: mock lock acquisition to throw a
      `LockHeldException`; assert the service throws a 409-mapped exception with
      `{code: "version_locked", lockedBy, expiresAt}` (spec REQ-OBVP-006).
- [x] 6.12 Test `promote` — lock released on success AND on failure (separate
      assertions in two test methods covering both paths) (spec REQ-OBVP-006).

## 7. Unit tests — VersionPromotionController

- [x] 7.1 Create `tests/Unit/Controller/VersionPromotionControllerTest.php`.
- [x] 7.2 Test `promote` with a valid request from an owner user — returns 200
      with the updated target (mock the service to return a known target row).
- [x] 7.3 Test `promote` with an editor user — succeeds (spec REQ-OBVP-007).
- [x] 7.4 Test `promote` with a viewer user — returns 403 with
      `code: "insufficient_permission"` (spec REQ-OBVP-007).
- [x] 7.5 Test `promote` with a non-member user — returns 403 (spec REQ-OBVP-007).
- [x] 7.6 Test `promote` with a Nextcloud admin user who is NOT in
      `permissions.owners` or `permissions.editors` — returns 403. Admin power
      does NOT auto-grant (spec REQ-OBVP-007 — deliberate constraint).
- [x] 7.7 Test `promote` with a missing `appUuid` / `versionUuid` — returns 404.
- [x] 7.8 Test `promote` with a source whose `application` does not match the
      `appUuid` — returns 404.
- [x] 7.9 Test exception-to-HTTP mapping for every service exception type
      (NoPromoteTarget, InvalidStrategy, VersionLocked, InsufficientPermission,
      PromotionFailed) — verify the correct HTTP status code AND the correct JSON
      body for each.

## 8. Newman / Postman integration tests

- [x] 8.1 Add `tests/integration/promotion.postman_collection.json` (or append a
      `Version Promotion` folder to the existing collection).
- [x] 8.2 Happy-path request: POST `/api/applications/<appUuid>/versions/<sourceUuid>/promote`
      with `{strategy: "start-with-source-data"}`; assert 200 and the returned
      manifest matches the source's.
- [x] 8.3 422 request: POST with a source whose `promotesTo` is null; assert
      422 and body contains `"code": "no_promote_target"`.
- [x] 8.4 400 request: POST with `{strategy: "unknown-mode"}`; assert 400 and body
      contains `"code": "invalid_strategy"`.
- [ ] 8.5 **409 lock-contention request** (REQUIRED — locked prompt constraint):
      use Newman pre-request scripting (or a two-step test setup) to acquire the
      OR lock on the target via a direct OR-API call, then POST a valid promote
      request; assert 409 and body matches `{"code": "version_locked", "lockedBy":
      "<uid>", "expiresAt": "<iso8601>"}` (spec REQ-OBVP-006).
- [x] 8.6 403 permission requests: POST as a viewer and as a non-member; assert
      403 with `code: "insufficient_permission"` for each (spec REQ-OBVP-007).
- [ ] 8.7 500 on-failure request: stage an OR schema-import failure (e.g. by
      promoting from a source whose schemas reference an unknown type that OR
      will reject); assert 500 with body containing `"code": "promotion_failed"`
      AND verify the target row's status flipped to `archived` with a
      `_self.promotionFailedAt` timestamp via a follow-up GET (spec REQ-OBVP-009).
- [ ] 8.8 Run the collection in CI alongside the existing integration suite:
      `npx newman run tests/integration/promotion.postman_collection.json --bail`.

## 9. End-to-end verification

- [ ] 9.1 In a fresh dev container, create one Application via OR REST and two
      ApplicationVersions with `<v1>.promotesTo = <v2>`. Seed each per-version
      register with a couple of rows of test data.
- [ ] 9.2 POST `/api/applications/<appUuid>/versions/<v1Uuid>/promote` with
      `{strategy: "migrate-existing-data"}` as an owner; confirm 200, target's
      rows preserved, target's manifest + semver now match source's.
- [ ] 9.3 Repeat for `{strategy: "start-with-source-data"}`; confirm target's
      rows replaced by source's rows.
- [ ] 9.4 Repeat for `{strategy: "empty-start"}` via direct API (the dialog gate
      is UI-only); confirm target's rows wiped and schemas match source's.
- [ ] 9.5 Acquire the OR object lock on the target via a separate request, then
      attempt promotion; confirm 409 with `lockedBy` + `expiresAt`.
- [ ] 9.6 Mount the dialog in a Storybook or temporary test page; verify the
      default-strategy rule fires correctly for both production and mid-chain
      targets; verify the type-the-slug gate blocks Confirm for `empty-start`
      until the slug matches.
- [ ] 9.7 Stage an OR schema-import failure (an intentionally bad schema in the
      source); attempt promotion; confirm 500, target's `status` is `archived`,
      `_self.promotionFailedAt` is set, lock released; re-promote after fixing
      the bad schema and confirm success (idempotent re-promotion).

## 10. Quality gates

- [ ] 10.1 Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan); fix every
      finding (memory rule `fix-all-issues-encountered`). No `// SPDX-` line
      comments — SPDX tags live inside the docblock (memory rule
      `spdx-in-docblock`).
- [x] 10.2 Run `composer test` (full PHPUnit suite); confirm all pass.
- [x] 10.3 Run `npm run lint` and `npm run test:unit` (front-end); confirm the
      dialog component test passes and the new file has no ESLint errors.
- [ ] 10.4 Run the Hydra mechanical gates: `bash scripts/run-hydra-gates.sh`
      covers SPDX, forbidden-patterns, stub-scan, composer-audit, route-auth,
      orphan-auth, no-admin-idor, unsafe-auth-resolver, semantic-auth,
      initial-state, admin-router, nc-input-labels, modal-isolation. Specifically
      verify `hydra-gate-modal-isolation` (PromoteVersionDialog.vue is in
      `src/dialogs/`, not inline), `hydra-gate-nc-input-labels` (radio inputs +
      destructive-confirmation input both have proper labels), and
      `hydra-gate-route-auth` (the new route's controller method carries
      `#[NoAdminRequired]`).
- [ ] 10.5 Run `openspec validate openbuilt-version-promotion --strict`; confirm
      clean.
- [ ] 10.6 Open PR against `development` (memory rule
      `feature-branches-from-dev`); reference ADR-002, the foundation change
      `openbuilt-versioning-model`, and the three deferred sibling specs
      (`openbuilt-app-detail-overview`, `openbuilt-version-routing`,
      `openbuilt-app-creation-wizard`) in the description so reviewers can trace
      the chain delivery wave.
