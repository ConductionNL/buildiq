## 1. ApplicationInsightsService — schema-set walk, KPI fan-out, activity payload

- [x] 1.1 Confirm the OR-side `AuditTrailMapper::getDistinctActorCount(schemaIds, hours)`
      has landed via `openregister-distinct-actor-aggregation`. Without it the
      Active-users KPI will be a stub. Record the dependency status in the apply-time
      notes.
- [x] 1.2 Create `lib/Service/ApplicationInsightsService.php`. Constructor injects
      OR's `ObjectService`, OR's `AuditTrailMapper`, OR's `FileService` (or the
      equivalent file-counting service available at apply time), `IUserSession`,
      and `LoggerInterface`. Per ADR-022 — no app-local DB access.
- [x] 1.3 Implement
      `computeInsights(string $appUuid, string $versionUuid, string $window, ?IUser $caller): ?array`
      following the contract in REQ-OBAI-001 through REQ-OBAI-006:
      - Step 1: resolve the Application by `appUuid` via `ObjectService`. Return
        `null` (caller maps to `404`) if not found.
      - Step 2: resolve the ApplicationVersion by `versionUuid`. Return `null` if
        not found OR if `version.application !== Application.uuid` (no cross-app
        version leak).
      - Step 3: RBAC gate per REQ-OBAI-002. If the resolved version is the
        production version (`version.uuid === Application.productionVersion.uuid`)
        require viewer-or-better; otherwise require editor-or-better. Failure → `null`.
        Nextcloud admin role is NOT a bypass.
      - Step 4: walk `version.manifest.pages[]` per REQ-OBAI-003. Unique-by-schemaId,
        filter to tuples where `registerSlug === openbuilt-{appSlug}-{versionSlug}`.
      - Step 5: fan out the four KPI calls per REQ-OBAI-004:
        - `activeUsers` = `auditTrailMapper->getDistinctActorCount($schemaIds, $hours)`
        - `objectCount` = sum of `objectService->countObjects($schemaId)` over the schema-set
        - `filesCount` = `fileService->countAttachedFilesForRegister($registerSlug)`
        - `auditEventCount` = `auditTrailMapper->countByRegisterAndWindow($schemaIds, $hours)`
      - Step 6: `activity` = `auditTrailMapper->getActionChartData($schemaIds, $hours)`.
      - Step 7: assemble the response payload per REQ-OBAI-001 and return it.
- [x] 1.4 Window-to-hours mapping per REQ-OBAI-004: `7d → 168`, `30d → 720`, `90d → 2160`.
      Centralise the map in a private const on the service.
- [x] 1.5 Empty schema-set returns four zeros + empty activity (REQ-OBAI-003 last scenario).
      Do not short-circuit to skip OR calls — defensive zeros are simpler.
- [x] 1.6 PHPDoc on the class and every public method. SPDX header inside the opening
      docblock (per memory rule `spdx-in-docblock`). No forbidden patterns (`var_dump`,
      `die`, `error_log`, `print_r`, `dd`, `dump`).

## 2. ApplicationInsightsController — wire path, query, auth, cache header

- [x] 2.1 Create `lib/Controller/ApplicationInsightsController.php` extending
      `OCP\AppFramework\Controller`. Constructor injects `ApplicationInsightsService`,
      `IUserSession`, `IRequest`.
- [x] 2.2 Implement `getInsights(string $appUuid, string $versionUuid): JSONResponse`:
      - Read `window` from `$this->request->getParam('window')`. Validate against
        the set `['7d', '30d', '90d']`. Invalid or missing → return
        `JSONResponse(['status' => 400, 'message' => 'Invalid window parameter; expected one of: 7d, 30d, 90d'], 400)`.
      - Delegate to `applicationInsightsService->computeInsights($appUuid, $versionUuid, $window, $this->userSession->getUser())`.
      - Map `null` → `JSONResponse(['status' => 404, 'message' => 'Not Found'], 404)`.
      - Map non-null → `JSONResponse($payload, 200)` with `Cache-Control: public, max-age=60`
        set on the response (REQ-OBAI-006).
- [x] 2.3 Annotate the method with `#[NoAdminRequired]` (REQ-OBAI-002, REQ-OBAI-007).
      The RBAC gate lives inside the service.
- [x] 2.4 Add the route entry to `appinfo/routes.php`: verb `GET`, URL
      `/api/applications/{appUuid}/versions/{versionUuid}/insights`, mapping to
      `ApplicationInsightsController::getInsights` (REQ-OBAI-007).
- [x] 2.5 PHPDoc on the class and every public method. SPDX header inside the opening
      docblock. No forbidden patterns.

## 3. ApplicationDetailHeader.vue — main area, hero, pill tabs, window toggle, KPI grid, activity graph

- [x] 3.1 Create `src/components/applicationDetail/ApplicationDetailHeader.vue` (single-file
      component). Props: `objectId` (Application UUID from the route param).
- [x] 3.2 Read `$route.query._version` for the active version slug. If absent, fall
      back to the production version's slug (via the `useApplicationVersion` composable
      from `openbuilt-version-routing`).
- [x] 3.3 Render the hero strip (REQ-OBADO-001): icon from
      `Application.icon` (per ADR-001), name, description, status badge, caller role
      badge, production version semver.
- [x] 3.4 Render the version pill strip (REQ-OBADO-002): one pill per version in
      `Application.versions`, ordered by the `promotesTo` chain (most-upstream first).
      Mark `productionVersion` with leading asterisk. HIDE pills the caller cannot
      access (Decision 9 in design.md):
      - Production version: visible to any authenticated caller.
      - Non-production: visible only to callers in `permissions.editors ∪ permissions.owners`.
      Click handler updates `?_version=` via the `buildVersionedRoute` helper from
      `openbuilt-version-routing`.
- [x] 3.5 Render the window toggle (REQ-OBADO-003): three buttons `7d` / `30d` / `90d`,
      default `7d`. Local component state. Right-align on the same row as the pill strip.
- [x] 3.6 Create `src/composables/useApplicationInsights.js` — wraps the insights
      endpoint, fetches on mount and on `(versionUuid, window)` change. Debounce 200ms
      on the watcher (Decision-11 risk mitigation in design.md). Returns
      `{ kpis, activity, loading, error, refresh }`. Handle `404` by setting a banner
      flag (`versionNoLongerAccessible: true`) and falling back to production.
- [x] 3.7 Render the KPI grid (REQ-OBADO-004): four `CnCard` instances in a
      responsive grid (desktop 4 / tablet 2 / mobile 1). Do NOT depend on `CnKpiGrid`
      (the locked design choice avoids a specific nc-vue component dependency for
      this spec). Pass values from `useApplicationInsights().kpis`. Tooltip on the
      Files card per REQ-OBADO-004.
- [x] 3.8 Render the activity graph card (REQ-OBADO-005): use `CnActivityChart` (or
      similar) from `@conduction/nextcloud-vue` if available; else wrap the
      `@nextcloud/vue` chart primitive. Pass `useApplicationInsights().activity`.
      Render an empty-state message when the array is empty.
- [x] 3.9 Render the five structural widget cards in a responsive grid below the
      activity card (REQ-OBADO-006 through REQ-OBADO-010). Each widget is a separate
      component (tasks 4–8); this file just composes them.
- [x] 3.10 Render a "version no longer accessible" banner when the insights endpoint
      returns 404 (Decision-9 fallback in design.md). The banner SHALL offer a
      one-click "Switch to production" action that updates `?_version=` to the
      production version's slug.

## 4. RegisterWidget.vue — read-only card with OpenRegister deep-link

- [x] 4.1 Create `src/components/applicationDetail/widgets/RegisterWidget.vue`.
      Props: `appSlug`, `versionSlug`, `kpis` (object with `schemaCount`, `objectCount`,
      `filesCount`).
- [x] 4.2 Render the register name, slug (`openbuilt-{appSlug}-{versionSlug}`), schema
      count, object count, files count.
- [x] 4.3 "Open in OpenRegister" button — clicking navigates the browser to
      `/apps/openregister/registers/openbuilt-{appSlug}-{versionSlug}` (REQ-OBADO-006).
      Use `window.location` (top-level Nextcloud URL, not a Vue Router route).

## 5. SchemasWidget.vue — schemas list with deep-link rows and inline "+ Add"

- [x] 5.1 Create `src/components/applicationDetail/widgets/SchemasWidget.vue`.
      Props: `appSlug`, `versionSlug`, `schemas` (array of `{ id, name, objectCount, status }`).
- [x] 5.2 Render each schema as a row with name + object count + status. Row click
      → router push using `buildVersionedRoute('schema-designer', { slug: appSlug, schemaId: id }, versionSlug)`
      (REQ-OBADO-007). Verify the route name at apply time against the installed
      router config.
- [x] 5.3 Inline "+ Add schema" header button. On click:
      - Check whether a global create-schema dialog is registered (look for a Pinia
        store or event bus entry — pattern verified at apply time).
      - If present, open it (optionally pre-filling the register slug).
      - If absent, emit a `debug` log entry "schema-create dialog not yet
        registered — deferred to schema-designer spec" and no-op (REQ-OBADO-007).

## 6. GroupsWidget.vue — permissions list with role badges

- [x] 6.1 Create `src/components/applicationDetail/widgets/GroupsWidget.vue`.
      Props: `application` (the full Application record so the widget can read
      `permissions.{owners,editors,viewers}`).
- [x] 6.2 Flatten the three permissions arrays into a single rows array with role
      badges. Group entries display member count from a group lookup
      (`useGroupMembers` or analogous — verified at apply time). User entries display "1".
- [x] 6.3 Row click opens the existing permissions editor. The exact route (e.g.
      `/builder/{slug}/permissions` or a modal) is verified at apply time and recorded
      in the apply-time task notes (Decision 6 + DEFERRED_QUESTIONS in design.md).

## 7. PagesWidget.vue — manifest.pages list with deep-link rows

- [x] 7.1 Create `src/components/applicationDetail/widgets/PagesWidget.vue`.
      Props: `appSlug`, `versionSlug`, `pages` (the manifest's `pages[]` array).
- [x] 7.2 Render each page as a row with id, route, type, title. Row click → router
      push to `/builder/{appSlug}/pages?_version={versionSlug}&pageId={id}` via
      `buildVersionedRoute` (REQ-OBADO-009).

## 8. MenuWidget.vue — manifest.menu list with deep-link rows

- [x] 8.1 Create `src/components/applicationDetail/widgets/MenuWidget.vue`.
      Props: `appSlug`, `versionSlug`, `menu` (the manifest's `menu[]` array).
- [x] 8.2 Render each entry as a row with label, route, order, section. Row click →
      router push to `/builder/{appSlug}/pages?_version={versionSlug}&focus=menu`
      via `buildVersionedRoute` (REQ-OBADO-010).

## 9. Promote affordance on non-terminal pills

- [x] 9.1 In `ApplicationDetailHeader.vue`, render a small Promote affordance (icon
      button or trailing chevron) on each pill whose corresponding ApplicationVersion
      has a non-null `promotesTo` target (REQ-OBADO-012).
- [x] 9.2 Hide the Promote affordance on the production pill (it is the terminal
      node).
- [x] 9.3 Click handler:
      - Check whether the promotion dialog from `openbuilt-version-promotion` is
        registered (pattern verified at apply time).
      - If present, open it pre-targeted at the pill's version.
      - If absent, emit a `debug` log entry and no-op.

## 10. Manifest config — add headerComponent, drop Overview sidebar tab

- [x] 10.1 Open `src/manifest.json`. Locate the `VirtualAppDetail` page entry.
- [x] 10.2 Add the key `"headerComponent": "ApplicationDetailHeader"` to that entry
      (REQ-OBADO-011).
- [x] 10.3 Locate the `sidebarTabs` array on the entry. Remove the element whose `id`
      is `overview` (REQ-OBADO-011). Preserve all other entries unchanged in id, order,
      and count.
- [ ] 10.4 Validate the resulting manifest against
      `node_modules/@conduction/nextcloud-vue/src/schemas/app-manifest.schema.json`
      (or the equivalent path resolved at apply time). The validation MUST pass.
- [x] 10.5 Re-parse the JSON to confirm no duplicate keys (per the
      `json-merge-revalidate` memory rule).

## 11. Register ApplicationDetailHeader.vue as a global component

- [x] 11.1 Locate the component-registration site (usually `src/main.js` or
      `src/index.js`). Register `ApplicationDetailHeader` so the manifest-driven
      `headerComponent` lookup can resolve it by name.
- [x] 11.2 Confirm the lazy-loading pattern matches the existing convention for
      other manifest-referenced components.

## 12. PHPUnit — ApplicationInsightsService

- [x] 12.1 Create `tests/Unit/Service/ApplicationInsightsServiceTest.php`. Use
      PHPUnit with `@phpstan-ignore-line` only where unavoidable.
- [x] 12.2 Tests covering every scenario in `specs/application-insights/spec.md`
      REQ-OBAI-001 through REQ-OBAI-005:
      - Valid call returns the four KPIs + activity payload (mock OR mappers).
      - Missing window → `null` (controller maps to 400, but service receives a
        valid window pre-validated; alternatively unit-test 400 at controller layer).
      - Unknown appUuid → `null`.
      - Unknown versionUuid → `null`.
      - versionUuid whose `application` ≠ appUuid → `null`.
      - Viewer can read production → non-null.
      - Viewer reading non-production → `null` (404 at controller layer).
      - Editor reading non-production → non-null.
      - Admin (no listed permission) reading non-production → `null`.
      - Schema-set walk: dedupes schema IDs from manifest.pages.
      - Schema-set walk: ignores tuples referencing other registers.
      - Empty manifest pages: returns four zeros + empty activity.
      - Window-to-hours mapping: `7d → 168`, `30d → 720`, `90d → 2160`.
      - `objectCount` ignores window; `filesCount` ignores window.

## 13. PHPUnit — ApplicationInsightsController

- [x] 13.1 Create `tests/Unit/Controller/ApplicationInsightsControllerTest.php`.
- [x] 13.2 Tests covering REQ-OBAI-001 and REQ-OBAI-006:
      - Missing `window` query param → 400 with the expected body.
      - Invalid `window` value (e.g. `24h`) → 400.
      - Valid call returns 200 with `Cache-Control: public, max-age=60`.
      - Service returns `null` → controller returns 404 without the cache header.

## 14. Playwright e2e — application-detail-overview

- [x] 14.1 Create `tests/e2e/application-detail-overview.spec.ts`. Use the existing
      fixtures from `openbuilt-versioning-model` (`hello-world` Application with
      `development → staging → production` chain).
- [x] 14.2 Test: page renders all six rows in DOM order (REQ-OBADO-001).
- [x] 14.3 Test: pill strip renders chain order with production starred
      (REQ-OBADO-002).
- [ ] 14.4 Test: non-authorised version is hidden from the pill strip (use a viewer
      session — only the `* production` pill should render).
- [ ] 14.5 Test: pill click updates `?_version=` and reloads dependent rows
      (REQ-OBADO-002). Assert the network request for the insights endpoint fires
      with the new versionUuid and that KPI values re-render.
- [x] 14.6 Test: window toggle change reloads windowed KPIs only — Object count
      and Files count do not change (REQ-OBADO-003).
- [ ] 14.7 Test: structural widget deep-links land on the correct paths with the
      active `?_version=` preserved (REQ-OBADO-007 / REQ-OBADO-009 / REQ-OBADO-010):
      - Register card "Open in OpenRegister" → `/apps/openregister/registers/openbuilt-hello-world-{slug}`
      - Schemas card row → `/builder/hello-world/schemas/{id}?_version={slug}`
      - Pages card row → `/builder/hello-world/pages?_version={slug}&pageId={id}`
      - Menu card row → `/builder/hello-world/pages?_version={slug}&focus=menu`
- [ ] 14.8 Test: activity graph renders chart for non-empty `activity[]` and the
      empty-state message for `activity: []` (REQ-OBADO-005).
- [x] 14.9 Test: Promote affordance renders only on non-terminal pills, hidden on
      `* production` (REQ-OBADO-012). Assert it is clickable on `development` and
      `staging`.

## 15. Hydra mechanical quality gates

- [ ] 15.1 Run `bash scripts/run-hydra-gates.sh` (or the equivalent path resolved at
      apply time). All thirteen gates MUST pass before push.
- [ ] 15.2 Specific gates to verify locally before push:
      - SPDX headers on every new PHP file under `lib/`.
      - No forbidden patterns (`var_dump`, `die`, `error_log`, `print_r`, `dd`, `dump`)
        anywhere under `lib/`.
      - `composer audit` clean.
      - Route auth: the new insights route's controller method MUST carry
        `#[NoAdminRequired]`.
      - No orphan auth: every auth helper invoked from the service MUST be reachable
        from `getInsights`.
      - No-admin-IDOR: the service MUST guard on `appUuid` ownership before reading
        the version (REQ-OBAI-002).
      - Initial state: no `document.getElementById(...).dataset.*` reads in the new
        Vue components.
      - NcInput labels: every `NcSelect` (if used) in the new Vue components carries
        `inputLabel`.
      - Modal isolation: any new modal (e.g. for the Promote dialog wrapper) lives in
        `src/modals/` or `src/dialogs/` (likely N/A — Promote dialog is owned by
        `openbuilt-version-promotion`).
