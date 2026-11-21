## 0. Prerequisites (hard dependencies)

- [x] 0.1 Confirm `@conduction/nextcloud-vue` `manifest-delta-merge-and-flex-columns` is in the consumed version — `diffManifest`, `mergeManifestDelta`, stable `widgetEntry.id`, `$op:"remove"`, `__order`, the `useAppManifest`/`useRuntimeManifest` `mergeStrategy:'delta'` branch, and `options.endpoint` override are all exported. BLOCK all tasks below until confirmed.
  > Audited 21 Nov: Confirmed 21 Nov on the consumed `@conduction/nextcloud-vue` 2.181.0: `diffManifest`, `mergeManifestDelta` (with the `$op` remove marker and `__order`), and the `useAppManifest` / `useRuntimeManifest` `mergeStrategy` branch with `options.endpoint` are in `dist/`.
- [x] 0.2 Confirm sibling `app-delta-override` reconciliation: this change adds `AppOverride` (fleet apps, client-side merge) and does NOT touch `ApplicationVersion` / `ManifestResolverService` / the `buildiq-runtime` manifest endpoint (OpenBuilt apps, server-side merge). Record the coexistence note in the PR description.
  > Audited 21 Nov: Coexistence holds: fleet apps go through `/api/app-overrides/{appId}` with a client-side merge (`AppOverrideController`, `AppOverrideService`), and OpenBuilt apps keep `ApplicationVersion.manifest` through `ManifestResolverService`; this change does not touch the resolver or the runtime manifest endpoint.

## 1. Schema (additive, no migration)

- [x] 1.1 Add the `AppOverride` schema to `lib/Settings/openbuild_register.json`: `appId` (string, kebab-case NC-app-id pattern, the unique key), `baseRef` (optional structured object — kind/id/optional manifestVersion), `manifestDelta` (object — the keyed delta), `updatedBy` (string UID), `updatedAt` (ISO-8601 string), with descriptions and `additionalProperties: false`.
  > Audited 21 Nov: Superseded by `unify-apps-with-app-type` (archived 2026-06-20): the delta is stored on a hybrid `Application` and its delta-only `ApplicationVersion` (`baseRef` with `kind: fleet-app`, `manifestDelta`) in `lib/Settings/openbuild_register.json`, not on a separate `AppOverride` schema; `lib/Repair/MigrateAppOverridesToHybrid.php` moves old records (PHPUnit `MigrateAppOverridesToHybridTest`, `MigrateAppOverridesToHybridEdgeCasesTest`).
- [x] 1.2 Bump the register `version` so the existing `ConfigurationService::importFromApp()` repair step re-imports; verify the schema imports cleanly (no union types, nullable where optional).
  > Audited 21 Nov: Superseded as 1.1: the `ApplicationVersion` schema carries `baseRef` and `manifestDelta` (register version 0.4.2), imported by the existing repair step.

## 2. AppOverrideService (OR-backed, ADR-022)

- [x] 2.1 Create `AppOverrideService` consuming OR's `ObjectService`: `findByAppId(string $appId): ?array`, `upsert(string $appId, array $delta, string $baseRef = null, string $updatedBy): array` (find-by-appId then create-or-update, set `updatedBy`/`updatedAt`), `delete(string $appId): void` (idempotent).
  > Audited 21 Nov: `lib/Service/AppOverrideService.php`: `findByAppId()`, `upsert()` (creates or updates the hybrid app's version, sets `updatedBy`/`updatedAt`), `delete()` (idempotent).
- [x] 2.2 Add `validateDeltaShape(array $delta): array` — assert a keyed delta (plain object; page/widget entries carry ids; `$op` is the known marker; `__order` is an array of ids); return a list of violations.
  > Audited 21 Nov: `lib/Service/AppOverrideDeltaValidator.php::validateDeltaShape()`, delegated from `AppOverrideService`; PHPUnit `AppOverrideDeltaValidatorTest`.
- [x] 2.3 Add `wouldBlankApp(array $delta): bool` — detect a delta that resolves (over an empty base) to no renderable pages/menu; used to reject app-blanking writes with `422`.
  > Audited 21 Nov: `AppOverrideDeltaValidator::wouldBlankApp()`; PHPUnit `AppOverrideDeltaValidatorTest::testWouldBlankApp`, `AppOverrideServiceTest::testWouldBlankAppTrueWhenAllPagesRemovedAndMenuEmptied`.
- [x] 2.4 Unit tests for `AppOverrideService`: upsert-creates, re-save-updates-in-place, delete-idempotent, malformed-delta rejected, app-blanking-delta rejected, valid-delta passes.
  > Audited 21 Nov: PHPUnit `tests/Unit/Service/AppOverrideServiceTest.php`: upsert creates, re-save updates in place, delete idempotent, list-shaped and id-less deltas rejected, blanking delta detected, valid delta passes.
- [x] 2.5 SPDX headers + full PHPDoc; pass `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) on the new service.
  > Audited 21 Nov: Open: PHPMD reports `CouplingBetweenObjects` 14 (limit 13) on `AppOverrideService`; PHPCS and PHPStan are clean.
  > Done 21 Nov: the save time is written with `date(DATE_ATOM)` instead of `DateTimeImmutable`/`DateTimeInterface`, the same ISO-8601 value, so the coupling is 12. PHPMD, PHPCS, PHPStan and Psalm report nothing on `lib/Service/AppOverrideService.php`; `AppOverrideServiceTest::testUpsertCreatesHybridAppWhenNone` and `AppOverrideServiceUserDeltaTest` assert the `updatedAt` format.

## 3. AppOverrideController + routes

- [x] 3.1 Create `AppOverrideController` with `get(string $appId)`, `save(string $appId)` (PUT), `clear(string $appId)` (DELETE). Validate `appId` against the kebab-case NC-app-id pattern.
  > Audited 21 Nov: `lib/Controller/AppOverrideController.php` `get()`, `save()`, `clear()`, each validating `appId` first (`testInvalidAppIdIsBadRequest`).
- [x] 3.2 Implement the Buildiq-access guard used by `save`/`clear`: reject anonymous (`401`); reject a logged-in user outside Buildiq's app group-restriction (`403`); record `updatedBy` from the session UID on `save`.
  > Audited 21 Nov: Anonymous 401, outside Buildiq's group restriction 403, `updatedBy` from the session uid: `AppOverrideControllerTest` and `AppOverrideControllerOutcomesTest`.
- [x] 3.3 `save`: read body, run `validateDeltaShape` + `wouldBlankApp` (→ `422` on failure), call `AppOverrideService::upsert`, return `2xx`.
  > Audited 21 Nov: `save()` answers 422 on a shape violation or a blanking delta, then upserts (`testMalformedBodyIsUnprocessable`, `testAppBlankingDeltaIsUnprocessable`, `testInScopeSaveIsAllowed`).
- [x] 3.4 `get`: return the stored `manifestDelta` unchanged, or an empty delta `{}` (status `200`) when none — never merge server-side (Buildiq lacks the fleet base).
  > Audited 21 Nov: `get()` returns the stored delta or `{}` with 200 (`testGetReturnsStoredDelta`, `testGetReturnsEmptyObjectWhenNoOverride`).
- [x] 3.5 `clear`: call `AppOverrideService::delete` (idempotent), return `2xx`.
  > Audited 21 Nov: `clear()` deletes idempotently (`testInScopeClearSucceeds`).
- [x] 3.6 Register the three routes in `appinfo/routes.php`, SPECIFIC-FIRST before the `/{path}` SPA catch-all: `GET`/`PUT`/`DELETE /api/app-overrides/{appId}` with an `appId` kebab-case requirement.
  > Audited 21 Nov: `appinfo/routes.php` lines 319-321, with the `appId` requirement, before the SPA catch-all.
- [x] 3.7 Declare auth attributes: `#[NoAdminRequired]` on all three; `get` MAY add `#[NoCSRFRequired]` (read-only); `save`/`clear` MUST NOT carry `#[NoCSRFRequired]` (CSRF enforced on writes). Verify route-auth + no-admin-idor + route-reachability gates pass.
  > Audited 21 Nov: `#[NoAdminRequired]` on all three, `#[NoCSRFRequired]` only on `get()`.
- [x] 3.8 SPDX headers + full PHPDoc + `@spec` tags on every routed method; pass `composer check:strict`.
  > Audited 21 Nov: PHPCS, PHPMD and PHPStan report nothing on the controller (checked 21 Nov); every routed method carries `@spec`.

## 4. Capability (ICapability)

- [x] 4.1 Create a `Capabilities` class implementing `OCP\Capabilities\ICapability::getCapabilities()` returning `['buildiq' => ['enabled' => true, 'canEdit' => <computed>]]`.
  > Audited 21 Nov: `lib/Capabilities.php` returns `['buildiq' => ['enabled' => true, 'canEdit' => …]]`.
- [x] 4.2 Compute `canEdit` from the calling user's Buildiq access (same group-restriction check as the write guard); return `false` for out-of-scope users.
  > Audited 21 Nov: `canEdit` is `IAppManager::isEnabledForUser()` for the caller, false for anonymous.
- [x] 4.3 Register the `Capabilities` class in `lib/AppInfo/Application.php` (`registerCapability`).
  > Audited 21 Nov: Registered in `lib/AppInfo/Application.php` (`registerCapability(Capabilities::class)`).
- [x] 4.4 Unit test: capability shape present; `canEdit` true for in-scope user, false for out-of-scope user.
  > Audited 21 Nov: PHPUnit `tests/Unit/CapabilitiesTest.php`: shape, in scope, out of scope, anonymous.

## 5. Runtime contract proof (one app)

- [ ] 5.1 On one fleet app (proof-of-concept), wire its loader to `useAppManifest(appId, bundledManifest, { mergeStrategy: 'delta', endpoint: generateUrl('/apps/buildiq/api/app-overrides/' + appId) })` so a stored delta merges over the bundled base; the broad per-app rollout is follow-on work, not this change.
- [ ] 5.2 Manual: edit a page label via the shell → Save → reload → confirm the delta is applied; DELETE the override → reload → confirm the app reverts to its bundled manifest.

## 6. Tests

- [ ] 6.1 Newman: `app-overrides` collection — PUT valid delta (`2xx` + `updatedBy` recorded); GET returns the stored delta; GET with no override returns an empty delta; DELETE clears (subsequent GET empty); anonymous PUT rejected; malformed body `422`; app-blanking delta `422`.
- [x] 6.2 PHPUnit: `AppOverrideController` auth posture (anonymous → reject; out-of-scope → `403`; in-scope → allow) and the `Capabilities` `canEdit` matrix.
  > Audited 21 Nov: PHPUnit `AppOverrideControllerTest` and `AppOverrideControllerOutcomesTest` (auth posture per endpoint) and `CapabilitiesTest` (the `canEdit` matrix).
- [ ] 6.3 Capability/availability: integration check that the capabilities document carries `buildiq.enabled` + boolean `buildiq.canEdit`.

## 7. Verify

- [x] 7.1 Run `composer check:strict` and the hydra mechanical gates (route-auth, no-admin-idor, route-reachability, spec-coverage) green on the diff.
  > Run 21 Nov: route-auth, route-reachability and spec-coverage pass; no-admin-idor flags none of this change's endpoints (`get`, `save`, `clear`); its one `AppOverrideController` entry is `getUser()` of `application-delta-layers-ui`, which reads only the session user's own delta. `composer check:strict` reports nothing on the change's service, controller and capability; the other findings are in files this change does not touch.
- [ ] 7.2 `openspec validate "buildiq-inline-edit-persistence" --strict` passes and `openspec status` shows the artifacts complete before archiving.
