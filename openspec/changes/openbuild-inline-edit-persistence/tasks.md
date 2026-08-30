## 0. Prerequisites (hard dependencies)

- [ ] 0.1 Confirm `@conduction/nextcloud-vue` `manifest-delta-merge-and-flex-columns` is in the consumed version — `diffManifest`, `mergeManifestDelta`, stable `widgetEntry.id`, `$op:"remove"`, `__order`, the `useAppManifest`/`useRuntimeManifest` `mergeStrategy:'delta'` branch, and `options.endpoint` override are all exported. BLOCK all tasks below until confirmed.
- [ ] 0.2 Confirm sibling `app-delta-override` reconciliation: this change adds `AppOverride` (fleet apps, client-side merge) and does NOT touch `ApplicationVersion` / `ManifestResolverService` / the `buildiq-runtime` manifest endpoint (OpenBuilt apps, server-side merge). Record the coexistence note in the PR description.

## 1. Schema (additive, no migration)

- [ ] 1.1 Add the `AppOverride` schema to `lib/Settings/openbuild_register.json`: `appId` (string, kebab-case NC-app-id pattern, the unique key), `baseRef` (optional structured object — kind/id/optional manifestVersion), `manifestDelta` (object — the keyed delta), `updatedBy` (string UID), `updatedAt` (ISO-8601 string), with descriptions and `additionalProperties: false`.
- [ ] 1.2 Bump the register `version` so the existing `ConfigurationService::importFromApp()` repair step re-imports; verify the schema imports cleanly (no union types, nullable where optional).

## 2. AppOverrideService (OR-backed, ADR-022)

- [ ] 2.1 Create `AppOverrideService` consuming OR's `ObjectService`: `findByAppId(string $appId): ?array`, `upsert(string $appId, array $delta, string $baseRef = null, string $updatedBy): array` (find-by-appId then create-or-update, set `updatedBy`/`updatedAt`), `delete(string $appId): void` (idempotent).
- [ ] 2.2 Add `validateDeltaShape(array $delta): array` — assert a keyed delta (plain object; page/widget entries carry ids; `$op` is the known marker; `__order` is an array of ids); return a list of violations.
- [ ] 2.3 Add `wouldBlankApp(array $delta): bool` — detect a delta that resolves (over an empty base) to no renderable pages/menu; used to reject app-blanking writes with `422`.
- [ ] 2.4 Unit tests for `AppOverrideService`: upsert-creates, re-save-updates-in-place, delete-idempotent, malformed-delta rejected, app-blanking-delta rejected, valid-delta passes.
- [ ] 2.5 SPDX headers + full PHPDoc; pass `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) on the new service.

## 3. AppOverrideController + routes

- [ ] 3.1 Create `AppOverrideController` with `get(string $appId)`, `save(string $appId)` (PUT), `clear(string $appId)` (DELETE). Validate `appId` against the kebab-case NC-app-id pattern.
- [ ] 3.2 Implement the Buildiq-access guard used by `save`/`clear`: reject anonymous (`401`); reject a logged-in user outside Buildiq's app group-restriction (`403`); record `updatedBy` from the session UID on `save`.
- [ ] 3.3 `save`: read body, run `validateDeltaShape` + `wouldBlankApp` (→ `422` on failure), call `AppOverrideService::upsert`, return `2xx`.
- [ ] 3.4 `get`: return the stored `manifestDelta` unchanged, or an empty delta `{}` (status `200`) when none — never merge server-side (Buildiq lacks the fleet base).
- [ ] 3.5 `clear`: call `AppOverrideService::delete` (idempotent), return `2xx`.
- [ ] 3.6 Register the three routes in `appinfo/routes.php`, SPECIFIC-FIRST before the `/{path}` SPA catch-all: `GET`/`PUT`/`DELETE /api/app-overrides/{appId}` with an `appId` kebab-case requirement.
- [ ] 3.7 Declare auth attributes: `#[NoAdminRequired]` on all three; `get` MAY add `#[NoCSRFRequired]` (read-only); `save`/`clear` MUST NOT carry `#[NoCSRFRequired]` (CSRF enforced on writes). Verify route-auth + no-admin-idor + route-reachability gates pass.
- [ ] 3.8 SPDX headers + full PHPDoc + `@spec` tags on every routed method; pass `composer check:strict`.

## 4. Capability (ICapability)

- [ ] 4.1 Create a `Capabilities` class implementing `OCP\Capabilities\ICapability::getCapabilities()` returning `['buildiq' => ['enabled' => true, 'canEdit' => <computed>]]`.
- [ ] 4.2 Compute `canEdit` from the calling user's Buildiq access (same group-restriction check as the write guard); return `false` for out-of-scope users.
- [ ] 4.3 Register the `Capabilities` class in `lib/AppInfo/Application.php` (`registerCapability`).
- [ ] 4.4 Unit test: capability shape present; `canEdit` true for in-scope user, false for out-of-scope user.

## 5. Runtime contract proof (one app)

- [ ] 5.1 On one fleet app (proof-of-concept), wire its loader to `useAppManifest(appId, bundledManifest, { mergeStrategy: 'delta', endpoint: generateUrl('/apps/buildiq/api/app-overrides/' + appId) })` so a stored delta merges over the bundled base; the broad per-app rollout is follow-on work, not this change.
- [ ] 5.2 Manual: edit a page label via the shell → Save → reload → confirm the delta is applied; DELETE the override → reload → confirm the app reverts to its bundled manifest.

## 6. Tests

- [ ] 6.1 Newman: `app-overrides` collection — PUT valid delta (`2xx` + `updatedBy` recorded); GET returns the stored delta; GET with no override returns an empty delta; DELETE clears (subsequent GET empty); anonymous PUT rejected; malformed body `422`; app-blanking delta `422`.
- [ ] 6.2 PHPUnit: `AppOverrideController` auth posture (anonymous → reject; out-of-scope → `403`; in-scope → allow) and the `Capabilities` `canEdit` matrix.
- [ ] 6.3 Capability/availability: integration check that the capabilities document carries `buildiq.enabled` + boolean `buildiq.canEdit`.

## 7. Verify

- [ ] 7.1 Run `composer check:strict` and the hydra mechanical gates (route-auth, no-admin-idor, route-reachability, spec-coverage) green on the diff.
- [ ] 7.2 `openspec validate "buildiq-inline-edit-persistence" --strict` passes and `openspec status` shows the artifacts complete before archiving.
