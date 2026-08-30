## 1. Schema register (config — declarative)

- [x] 1.1 In `lib/Settings/openbuild_register.json`, add `appType` to the `Application` schema: enum `["virtual","hybrid"]`, `default: "virtual"`, with a description of the unified-app discriminator (no new Service).
- [x] 1.2 In the same file, add `baseRef` to the `Application` schema: object `{ kind, id, manifestVersion? }`, optional, `additionalProperties: false`, describing the `fleet-app` link for hybrid apps (mirrors the `app-delta-override` baseRef shape).
- [x] 1.3 Add an `x-openregister-validation` rule on `Application`: when `appType == "hybrid"`, require `baseRef.id` to be present (reject hybrid without a base).
- [x] 1.4 Remove the `AppOverride` schema from `openbuild_register.json` (clean break, D-RETIRE) — this edit is ordered LAST among backend steps, after the migration (3.x) has copied + deleted all rows; capture the removal rationale in REQ-OBA-009.
- [x] 1.5 Bump the register `info.version` and the `Application` schema `version`.

## 2. Backend — metadata-lock (code, ADR-031 exception)

- [x] 2.1 Express the metadata-lock as a declarative `x-openregister-validation` field rule on `Application` where possible (block `appType`/`baseRef` linkage churn on hybrid rows).
- [x] 2.2 Implement the cross-row metadata-lock guard (lifecycle/update `requires` guard, e.g. `OCA\OpenBuild\Lifecycle\HybridMetadataLockGuard`) that REJECTS an update changing `slug` or `name` when the stored row's `appType == "hybrid"`; allow all content (version delta) edits. Add SPDX header.
- [x] 2.3 Wire the guard into the `Application` schema's update path (lifecycle `requires` / save-time hook) — virtual apps keep full slug/name edit.

## 3. Backend — AppOverride→hybrid migration (code, ADR-031 one-time-transform exception)

- [x] 3.1 Implement an idempotent migration (repair step or migration class) that converts each `AppOverride` → `Application(appType:hybrid, slug=appId, baseRef.id=appId)` + a delta-only `ApplicationVersion` (status `published`, `baseRef.kind:"fleet-app"`, `manifestDelta` = override delta), with `productionVersion` pointed at the new version. Add SPDX header.
- [x] 3.2 Make the migration find-before-create: skip/update when a hybrid Application already exists for the `appId` (find by `appType==hybrid` AND `baseRef.id==appId`). After each copy is verified, DELETE the source `AppOverride` row (clean break, D-RETIRE); re-runnable when the row is already gone.
- [x] 3.3 Register the migration in the existing repair-step pipeline (alongside `ConfigurationService::importFromApp()`); ensure it runs after the schema import and BEFORE the `AppOverride` schema removal (task 1.4).

## 4. Backend — app-overrides compatibility shim (code)

- [x] 4.1 Repoint `GET /api/app-overrides/{appId}` to resolve the hybrid Application (`baseRef.id == {appId}` or `slug == {appId}`) and return its production-version `manifestDelta` raw; empty delta when none. Single source — no legacy `AppOverride` fallback (D-RETIRE). Preserve `#[NoAdminRequired]` + login.
- [x] 4.2 Repoint `PUT /api/app-overrides/{appId}` to validate the delta shape then upsert the hybrid Application + delta-only version (create both on first write); preserve login + CSRF + OpenBuild-access guard; reject anonymous.
- [x] 4.3 Repoint `DELETE /api/app-overrides/{appId}` to clear the hybrid Application's override (idempotent when none); preserve login + CSRF + OpenBuild-access guard.
- [x] 4.4 Confirm `appinfo/routes.php` entries for the three shim routes still resolve to existing methods (route-reachability) and keep their auth attributes (route-auth, no-admin-idor).

## 5. Frontend — rename Virtual apps → Apps (code)

- [x] 5.1 In `src/manifest.json`: rename menu item label `Virtual apps` → `Apps`, route id `VirtualApps` → `Apps` and `VirtualAppDetail` → `AppDetail`; update page titles ("Virtual apps"→"Apps", "Virtual app"→"App"); update the Dashboard "Virtual apps" stat label → "Apps".
- [x] 5.2 Keep deep-links working: add a redirect/alias from the old route ids/paths (`VirtualApps`, `/applications`) to the new ones for one release.
- [x] 5.3 In `src/registry.js`, rename the registered `VirtualAppsActions` references as needed and update component copy.
- [x] 5.4 Update user-facing copy in `src/components/VirtualAppsActions.vue` and `BuilderHostView` from "virtual app" → "app".

## 6. Frontend — badge, filter, wizard, read-only hybrid (code)

- [x] 6.1 Add a Virtual/Hybrid status pill to `src/components/ApplicationCard.vue` (existing NC/nldesign pill variants — no hardcoded colours), reading `appType`.
- [x] 6.2 Add the Virtual/Hybrid badge to `src/components/applicationDetail/ApplicationDetailHeader.vue`.
- [x] 6.3 Add an all/virtual/hybrid filter to the Apps list (`VirtualAppsActions.vue` / list config), persisted as a URL query param.
- [x] 6.4 In `src/dialogs/CreateApplicationWizard.vue`, add a first-step branch: create a *virtual* app (scratch/template — today's flow) OR a *hybrid* app (pick an installed NC app → seed `appType:hybrid` + `baseRef.id`). Keep the modal in `src/dialogs/` (modal-isolation).
- [x] 6.5 In `ApplicationDetailHeader.vue`, render the identity-metadata fields (slug/name) read-only when `appType == "hybrid"`.
- [x] 6.6 Add English source i18n keys for all new strings ("Apps", "Virtual", "Hybrid", filter labels, wizard branch copy, read-only hint) and Dutch translations; English keys.

## 7. Seed data (ADR-001 — schemas modified)

- [x] 7.1 Seed one virtual example app ("Travel Permit Tracker", `appType:virtual`) via `components.objects` with safe placeholder UUIDs (nil UUID), per design Seed Data section.
- [x] 7.2 Seed one hybrid example app ("OpenCatalogi" override, `appType:hybrid`, `baseRef.kind:"fleet-app"`) + its delta-only version with a small `manifestDelta`, safe placeholders.

## 8. Tests

- [x] 8.1 PHPUnit: metadata-lock guard rejects slug/name edits on hybrid, accepts on virtual, accepts content edits on hybrid.
- [x] 8.2 PHPUnit: migration creates one hybrid Application+version per `AppOverride` and deletes the source row; second run is a no-op (idempotence) with no rows left; the `AppOverride` schema is absent post-migration.
- [x] 8.3 PHPUnit: `/api/app-overrides/{appId}` shim — GET returns hybrid version delta raw; empty when none; PUT upserts hybrid Application; DELETE clears; anonymous rejected; CSRF enforced.
- [x] 8.4 Vitest: `ApplicationCard`/`ApplicationDetailHeader` render the correct Virtual/Hybrid badge by `appType`; missing `appType` renders as Virtual.
- [x] 8.5 Vitest: Apps-list all/virtual/hybrid filter narrows the list and round-trips through the URL query param.
- [x] 8.6 Vitest: `CreateApplicationWizard` virtual branch creates `appType:virtual`; hybrid branch seeds `appType:hybrid` + `baseRef.id`; hybrid detail renders slug/name read-only.
- [x] 8.7 Hydra gates green (route-auth, no-admin-idor, route-reachability, spec-coverage, modal-isolation) + spec/e2e traceability on changed methods (`@spec` tags).
