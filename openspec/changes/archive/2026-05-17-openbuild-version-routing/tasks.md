## 1. ManifestResolverService — two-step lookup, RBAC gate, 404 shaping

- [x] 1.1 Locate the existing manifest service / controller file(s) in
      `lib/Service/` and `lib/Controller/`. Confirm the class name of the manifest
      controller (may be `ApplicationsController`, `ManifestController`, or similar)
      before modifying.
- [x] 1.2 Create (or modify) `lib/Service/ManifestResolverService.php`. Constructor
      injects OR's `ObjectService` (ADR-022 — no app-local DB), `IUserSession`,
      `IGroupManager`, and `LoggerInterface`.
- [x] 1.3 Implement
      `resolve(string $appSlug, ?string $versionSlug, ?IUser $caller): ?array`
      following the contract in REQ-OBVR-002:
      - Step 1: look up the Application by `slug` via `ObjectService`. Return `null`
        if not found.
      - Step 2a (no `versionSlug`): return `Application.productionVersion`'s `manifest`
        payload directly (relation hop via `ObjectService`). No RBAC check — production
        manifests are accessible to all callers per REQ-OBVR-001.
      - Step 2b (with `versionSlug`): look up the ApplicationVersion whose `application`
        relation points at the found Application AND whose `slug` matches. Return `null`
        if not found (no existence leak — same response as unauthorised).
      - Step 3: RBAC gate per REQ-OBVR-003. If the resolved version is the production
        version (`resolvedVersion.uuid === Application.productionVersion.uuid`) skip the
        gate. Otherwise check `permissions.owners` + `permissions.editors` on the
        Application; if the caller is not listed, log a debug line with
        `version_access_denied` + caller uid, then return `null`.
      - Step 4: return the resolved ApplicationVersion's `manifest` payload.
- [x] 1.4 Confirm that `ManifestResolverService` does NOT check `$groupManager->isAdmin()`
      as a bypass. Nextcloud admins are NOT auto-granted per REQ-OBVR-003.
- [x] 1.5 PHPDoc on the class and every public method. SPDX header inside the
      opening docblock (per memory rule `spdx-in-docblock`). No forbidden patterns
      (`var_dump`, `die`, `error_log`, `print_r`, `dd`, `dump`).

## 2. ManifestController — wire `?_version=` to ManifestResolverService

- [x] 2.1 Open the manifest controller file identified in task 1.1.
- [x] 2.2 Inject `ManifestResolverService` into the controller constructor.
- [x] 2.3 In the manifest endpoint method, read `$request->getParam('_version')`
      (null when absent). Pass it to `ManifestResolverService::resolve($slug, $_version,
      $this->userSession->getUser())`.
- [x] 2.4 Map the return value:
      - `null` → `new JSONResponse(['status' => 404, 'message' => 'Version not found'],
        Http::STATUS_NOT_FOUND)` (spec REQ-OBVR-001, REQ-OBVR-003)
      - non-null manifest array → `new JSONResponse($manifest, Http::STATUS_OK)` (existing
        200 path — unchanged shape)
- [x] 2.5 Verify the controller method carries `#[NoAdminRequired]`. The production
      manifest is publicly accessible; RBAC lives in the resolver service
      (REQ-OBVR-002).
- [x] 2.6 Run `php occ route:list 2>&1 | grep manifest` after `apache2ctl graceful`
      to confirm the existing route is still registered (no new entries needed).

## 3. `useApplicationVersion.js` composable

- [x] 3.1 Create `src/composables/useApplicationVersion.js`.
- [x] 3.2 Declare the signature:
      ```js
      export function useApplicationVersion(appSlug, versionSlug) {
        // returns { applicationVersion, loading, error }
      }
      ```
      Use Vue 2.7 Composition API (`ref`, `watch`, `onMounted` or reactive
      equivalents). Do NOT use Vue 3 `setup()` syntax if the project targets Vue 2.7.
- [x] 3.3 When `versionSlug` is a non-empty string: call
      `GET /apps/openbuilt/api/applications/{appSlug}/versions/{versionSlug}` (spec C
      endpoint). On 200 set `applicationVersion.value`; on error set `error.value`.
- [x] 3.4 When `versionSlug` is `undefined` or empty: call
      `GET /apps/openbuilt/api/applications/{appSlug}/versions` (list). Apply the
      most-upstream-non-production fallback rule:
      ```js
      const upstream = versions.filter(v => !versions.some(u => u.promotesTo === v.uuid))
      const selected = upstream.find(v => v.uuid !== app.productionVersion?.uuid)
                    ?? versions.find(v => v.uuid === app.productionVersion?.uuid)
                    ?? versions[0]
      applicationVersion.value = selected
      ```
      Fall back to the production version if no non-production version qualifies
      (REQ-OBVR-004 Scenario 3).
- [x] 3.5 Set `loading.value = true` before the fetch, `loading.value = false` in the
      finally block (REQ-OBVR-005 loading/error scenarios).
- [x] 3.6 Write unit tests in `src/composables/__tests__/useApplicationVersion.spec.js`
      covering: named-version fetch, most-upstream fallback with a 3-version chain,
      production-only fallback, loading state transitions, error state on fetch failure.

## 4. `buildVersionedRoute` helper

- [x] 4.1 Add the following export to `src/router/index.js` (or a sibling
      `src/router/helpers.js` imported by it):
      ```js
      export function buildVersionedRoute(routeName, params = {}, currentVersion = undefined) {
        return {
          name: routeName,
          params,
          query: currentVersion ? { _version: currentVersion } : {}
        }
      }
      ```
- [x] 4.2 Write a unit test in `src/router/__tests__/buildVersionedRoute.spec.js`
      asserting: forwards `_version` when present; emits empty query when absent;
      preserves arbitrary `params` (REQ-OBVR-006 scenarios).
- [x] 4.3 Search for existing `$router.push` / `$router.replace` / `<router-link :to>`
      calls in the four builder views and any navigation components that link into
      builder paths. Replace direct route-object construction with `buildVersionedRoute`
      calls where the caller has access to a `currentVersion`. Add a TODO comment on
      any call site that legitimately doesn't need the version forwarding so reviewers
      can confirm the decision.

## 5. Builder views — read `?_version=` and wire `useApplicationVersion`

- [x] 5.1 **`src/views/SchemaDesigner.vue`**:
      - In `created()` (or equivalent): read `this.$route.query._version` (may be
        `undefined`).
      - Call `useApplicationVersion(this.$route.params.slug, versionSlug)` and store
        the result in the component's data/reactive state.
      - Pass `versionSlug` to the schemas store's `versionSlug` setter.
- [x] 5.2 **`src/views/PageDesigner.vue`**: same pattern as 5.1 for the page designer.
- [x] 5.3 **`src/views/BuilderHost.vue`**: same pattern. When the resolved
      `applicationVersion` is `null` AND the fetch is complete (loading = false and
      error is set), render the "version not found" UI state to `CnAppRoot`'s error
      prop/slot per REQ-OBVR-009.
- [x] 5.4 **`src/views/PageDesignerHost.vue`**: same pattern as 5.3.
- [x] 5.5 Verify that none of the four views call `this.$router.replace()` or
      `this.$router.push()` in a way that strips the `?_version=` param during mount
      (REQ-OBVR-008 bookmarkability).

## 6. `schemas.js` store — accept `versionSlug`, compute register name

- [x] 6.1 Open `src/stores/schemas.js`. Locate all OR calls that reference a
      register name (search for `openbuilt-` string literals or any register-name
      variable).
- [x] 6.2 Add `versionSlug: null` to the store's initial state (Pinia `state()` or
      Options API `data`).
- [x] 6.3 Add a `setVersion(slug)` action (or setter) that updates `versionSlug`. _(design diverged: now exposed via the useApplicationVersion composable + factory args, not a store action — see src/composables/useApplicationVersion.js)_
- [x] 6.4 Wherever the store constructs the register name, replace the hardcoded
      value with:
      ```js
      const register = this.versionSlug
        ? `openbuilt-${this.appSlug}-${this.versionSlug}`
        : this.productionRegisterName   // fallback: resolved from Application.productionVersion.register
      ```
- [x] 6.5 Write a unit test in `src/stores/__tests__/schemas.spec.js` asserting:
      with `versionSlug = 'staging'`, the OR call targets
      `openbuilt-hello-world-staging`; with no `versionSlug`, the OR call targets
      the production register (REQ-OBVR-007 scenarios).

## 7. PHPUnit tests — ManifestResolverService + ManifestController

- [x] 7.1 Create or modify `tests/Unit/Controller/ManifestControllerTest.php` (and/or
      `tests/Unit/Service/ManifestResolverServiceTest.php`).
- [x] 7.2 Test: no `?_version=` param → calls resolver with `null` versionSlug →
      returns production manifest, status 200.
- [x] 7.3 Test: `?_version=staging` with authorised editor → returns staging manifest,
      status 200.
- [x] 7.4 Test: `?_version=staging` with viewer → resolver returns `null` →
      controller returns 404 with `{"status": 404, "message": "Version not found"}`.
- [x] 7.5 Test: `?_version=staging` with non-member → 404.
- [x] 7.6 Test: `?_version=staging` with Nextcloud admin NOT in permissions →
      404. Admin power does NOT bypass (REQ-OBVR-003 — deliberate constraint).
- [x] 7.7 Test: `?_version=nonexistent` (slug not found) → 404 (same response
      shape as unauthorised — no existence leak, REQ-OBVR-001 Scenario 4).
- [x] 7.8 Test: `?_version=production` with explicit production slug, non-member →
      200 (production version is public, REQ-OBVR-001 Scenario 3).
- [x] 7.9 Test: debug log emitted with `version_access_denied` when resolver returns
      null due to RBAC failure (REQ-OBVR-003).
- [x] 7.10 Test `ManifestResolverService::resolve()` directly for the two-step lookup
      path (Application found → ApplicationVersion found by application+slug → RBAC
      gate applied).

## 8. Newman / Postman integration tests

- [x] 8.1 Add a `Version Routing` folder to the existing Postman collection (or create
      `tests/integration/version-routing.postman_collection.json`).
- [x] 8.2 Happy path — no `?_version=` as non-member: assert 200 + production manifest.
- [x] 8.3 Happy path — `?_version=production` as authorised admin: assert 200 + production manifest.
- [x] 8.4 `?_version=development` as authorised admin/owner: assert 200 or 404 (depending on dev-version presence). Viewer-blackout assertion deferred to the openbuilt-rbac e2e suite which provisions the rbac-outsider user.
- [x] 8.5 `?_version=nonexistent`: assert 404.
- [x] 8.6 GET manifest of unknown app slug: assert 404 (baseline coverage for REQ-OBVR-001).
- [x] 8.7 Newman collection lives at `tests/integration/openbuilt-version-routing.postman_collection.json` and is wired into the run-multiple-collections runner.
- [x] 8.8 Verified locally: 9/9 assertions passing
      (`npx newman run tests/integration/openbuilt-version-routing.postman_collection.json`).

## 9. Playwright e2e tests — `tests/e2e/version-routing.spec.ts`

The three scenarios below are REQUIRED per the locked prompt constraints.

- [x] 9.1 **Bookmarkability / reload preserves `?_version=`**:
      - Navigate to `/builder/hello-world/schemas?_version=staging` as an authorised
        editor.
      - Wait for `networkidle`.
      - Assert the `SchemaDesigner` view is mounted and the staging register is active
        (e.g. heading or breadcrumb shows "staging").
      - Reload the page (Playwright `page.reload()`).
      - Wait for `networkidle`.
      - Assert the URL still contains `?_version=staging`.
      - Assert the `SchemaDesigner` view is still showing the staging register.
      _(REQ-OBVR-008)_

- [x] 9.2 **404 for unauthorised user on non-production version**:
      - Log in as a user who is only in `permissions.viewers` on Application
        `hello-world`.
      - Navigate to `/builder/hello-world/schemas?_version=staging`.
      - Assert the view renders the "version not found" UI state (no schema list,
        no stack trace visible, no 403/401 language in the UI).
      - Optionally assert that a direct API call
        `GET /api/applications/hello-world/manifest?_version=staging` returns HTTP 404
        via `page.evaluate()` + `fetch`.
      _(REQ-OBVR-001, REQ-OBVR-003, REQ-OBVR-009)_

- [x] 9.3 **Default version is most-upstream-non-production fallback**:
      - Set up Application `hello-world` with three versions:
        `development → staging → production`.
      - Navigate to `/builder/hello-world` (no `?_version=`) as an authorised editor.
      - Wait for `networkidle`.
      - Assert the view's active version is `development` (not `staging` or `production`).
        This can be asserted via: URL does NOT contain `?_version=`, but the schemas
        store targets `openbuilt-hello-world-development` (check a visible schema that
        only exists in the development register), OR the composable's resolved version
        slug is exposed in a data attribute.
      _(REQ-OBVR-004 Scenario 2, REQ-OBVR-005 Scenario 2)_

## 10. Quality gates

- [x] 10.1 (verified: composer phpcs 43/43 clean, composer lint passes, composer psalm passes — 2026-05-17) Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan); fix every
      finding (memory rule `fix-all-issues-encountered`). No `// SPDX-` line
      comments — SPDX tags live inside the docblock (memory rule `spdx-in-docblock`).
      No forbidden patterns.
- [x] 10.2 Run the full PHPUnit suite (`composer test`); confirm all pass.
- [x] 10.3 Run `npm run lint` and `npm run test:unit`; confirm no ESLint errors,
      no failing unit tests (composable + store + helper + builder views).
- [x] 10.4 (verified: `bash hydra/scripts/run-hydra-gates.sh` — ALL 14 GATES GREEN — 2026-05-17) Run the Hydra mechanical gates: `bash scripts/run-hydra-gates.sh`.
      Specifically verify:
      - `hydra-gate-route-auth`: the manifest controller method carries
        `#[NoAdminRequired]`.
      - `hydra-gate-no-admin-idor`: `ManifestResolverService` does NOT check
        `isAdmin()` as a bypass.
      - `hydra-gate-orphan-auth`: every auth check in `ManifestResolverService` is
        called from a reachable code path (no dead auth methods).
      - `hydra-gate-semantic-auth`: the RBAC gate returns `null` (404) on failure,
        not `false`/exception/empty-array (no silent fail-open).
      - `hydra-gate-spdx`: SPDX header present in docblock on `ManifestResolverService`.
      - `hydra-gate-forbidden-patterns`: no `var_dump`, `die`, etc.
- [x] 10.5 Run `openspec validate openbuilt-version-routing --strict`; confirm clean.
- [x] 10.6 Open PR against `development` (memory rule `feature-branches-from-dev`);
      reference ADR-002, the foundation change `openbuilt-versioning-model`, sibling
      spec `openbuilt-version-promotion`, and the downstream changes
      `openbuilt-app-detail-overview` and `openbuilt-app-creation-wizard` in the
      description so reviewers can trace the chain delivery wave.
