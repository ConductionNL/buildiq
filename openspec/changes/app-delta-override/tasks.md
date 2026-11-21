## 0. Prerequisite (hard dependency)

- [x] 0.1 Confirm `@conduction/nextcloud-vue` `manifest-delta-merge-and-flex-columns` has landed in the consumed version — stable `widgetEntry.id`, `mergeManifestDelta`, `diffManifest`, `$op:"remove"`, `__order`, and `orphanedDeltaPaths` are all exported and documented. BLOCK all tasks below until confirmed.
- [x] 0.2 Pin the consumed nextcloud-vue version in Buildiq's `package.json` to the release that includes the foundation, and capture the canonical JS `mergeManifestDelta` unit-test fixtures for reuse as the PHP port's shared fixtures.

## 1. Schema fields (additive, no migration)

- [x] 1.1 Add the optional `baseRef` structured-reference property to the manifest-carrying `ApplicationVersion` schema in `lib/Settings/openbuild_register.json` (kind = template | buildiq-app | fleet-app, id, optional version pin), with a description and `additionalProperties: false`.
  > Built 21 Nov: `ApplicationVersion.baseRef` in `lib/Settings/openbuild_register.json` is an object with `kind` (enum `template`, `buildiq-app`, `fleet-app`, and `application-version` for the user-layer deltas `AppOverrideService` already writes), `id` and the optional pin `manifestVersion`, `additionalProperties: false`. PHPUnit `tests/Unit/Settings/ApplicationVersionBaseRefSchemaTest.php::testTheBaseReferenceNamesItsKind` validates it through the import's merge.
- [x] 1.2 Add the optional `manifestDelta` object property to `ApplicationVersion` (keyed delta: page-by-id, widget-by-id, `$op:"remove"`, `__order`), with a description pointing at the nextcloud-vue contract.
  > Present since unify-apps-with-app-type: `ApplicationVersion.manifestDelta`, an object pointing at nextcloud-vue's `diffManifest`/`mergeManifestDelta`; optional. `ApplicationVersionBaseRefSchemaTest::testTheDeltaIsAKeyedObject`.
- [x] 1.3 Add a backwards-compat note to the existing `manifest` blob property: blob present + no `baseRef` ⇒ blob IS the manifest (legacy/standalone). Bump the schema `version`.
  > Built 21 Nov: the `manifest` description says a version with the blob and no `baseRef` is a legacy or standalone app whose blob is its manifest; `ApplicationVersion` version 0.4.2 → 0.4.3. `ApplicationVersionBaseRefSchemaTest::testALegacyBlobVersionNeedsNeitherField`.

## 2. PHP delta merge (port of the JS contract)

- [x] 2.1 Create a `ManifestDeltaMerger` service implementing `merge(array $base, array $delta): array` — plain-object recursion, `pages[]` keyed by `page.id`, `widgets[]` keyed by `widget.id`, `$op:"remove"` deletion, `__order` reordering, non-keyed arrays replace wholesale (matches JS util).
- [x] 2.2 Collect orphaned-delta paths (a delta key matching no base entry is skipped, never fatal) and return them alongside the merged manifest (e.g. `{ merged, orphanedPaths }`).
- [x] 2.3 Add unit tests for `ManifestDeltaMerger` using the fixtures captured in 0.2; assert byte-identical output to the JS `mergeManifestDelta` for each fixture (page/widget merge, remove, reorder, orphan).
- [x] 2.4 SPDX headers, full PHPDoc, and pass `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) on the new service.

## 3. Base resolution

- [x] 3.1 In `ManifestResolverService`, add `resolveBase(baseRef)` — template ref → `ApplicationTemplate.manifest`; buildiq-app ref → that app's resolved production manifest (recursive); fleet-app ref → named fleet app's bundled manifest; honour an optional version pin.
  > Built 21 Nov: `lib/Service/ManifestBaseResolver.php` `resolveBase()` reads a `template` base (by slug or uuid, `manifestVersion` must equal the template's `version`), a `buildiq-app` base (its live production version, or a pinned released version by slug or semver; a draft is never a base, and an app the caller's organisation scope hides is not read), a `fleet-app` base (`src/manifest.json` in the fleet app's folder, its `version` checked against a pin) and an `application-version` base by uuid. A base that cannot be read leaves the version on its stored blob, so a hybrid app whose bundle is not on disk serves exactly what it served before. PHPUnit `tests/Unit/Service/ManifestBaseResolverTest.php`.
- [x] 3.2 Add a depth cap + cycle guard to recursive `buildiq-app` base resolution (reuse the `guardNoCycle` precedent); a cycle/over-deep chain resolves to the deepest safe base + a diagnostic.
  > Built: a base chain stops at `ManifestBaseResolver::MAX_DEPTH` (5) hops or at the first reference already on the chain; the last version reached serves its own blob and the reason is logged and returned as a diagnostic. `ManifestBaseResolverTest::testALoopOfBasesStopsWithAReason`, `::testAChainDeeperThanTheCapStops`.

## 4. Endpoint resolution wiring

- [x] 4.1 In `ManifestResolverService::resolve()`, branch on `baseRef`: null/absent ⇒ return the legacy `manifest` blob unchanged; set ⇒ resolve base (3.x) + apply `ManifestDeltaMerger` (2.x) and return the merged manifest. Keep the RBAC gate ahead of any payload emission.
  > Built: `ManifestResolverService::resolve()` serves a named version, an inline production version and a production version by uuid through `ManifestBaseResolver::manifestOf()` after the RBAC and organisation gates; without a `baseRef` the blob is returned unchanged. PHPUnit `tests/Unit/Service/ManifestResolverDeltaTest.php`.
- [x] 4.2 Ensure `ApplicationsController::getManifest()` and `diffVersions()` return the resolved/merged manifest, strip `baseRef`/`manifestDelta` and orphaned-path diagnostics from the public response (mirror the existing `permissions`-stripping).
  > Built 21 Nov: `getManifest()` serves what `ManifestResolverService::resolve()` returns, which is the merged manifest. `diffVersions()` diffs each side as `ManifestResolverServiceInterface::servedManifest()` returns it. Neither response contains `baseRef`, `manifestDelta` or orphan diagnostics: the resolver returns only the manifest. PHPUnit `tests/Unit/Controller/ApplicationsControllerDiffDeltaTest.php` and `ManifestResolverDeltaTest::testServedManifestMergesAVersionDirectly`.
- [ ] 4.3 Validate the merged manifest against the canonical app-manifest schema before responding; on validation failure emit the existing correlation-id error path, not a 500 stack.
  > Open (decision, 21 Nov): strict validation against `app-manifest.schema.json` would answer the correlation-id error for every app built on a shipped template. All four `lib/Settings/templates/*.json` have menu items without the required `id`, and the delta fixtures in `tests/Unit/Service/ManifestResolverDeltaTest.php` have pages without `route` and `type`. Also, neither the schema (it sits in `node_modules`) nor a JSON Schema validator (`opis/json-schema` is a dev-only dependency) ships with the app. Options: A check only what the renderer needs on the merged manifest (a string `version`, `menu` and `pages` arrays, and every page with a unique string `id` and string `route`, `type` and `title`), the way `AppRepoParser::validateManifest()` and `ApplicationsController::manifestShapeError()` already do; B first give the templates' menu items ids and migrate stored bases, then validate against the full schema with a validator that ships at runtime; C validate only when a delta is saved, and log on read. Recommended: A now, and B as its own change.

## 5. Editor: diff-on-save + preview + orphan surface

- [x] 5.1 In the manifest editor / `BuilderHost.vue`, load the resolved base for a delta-mode app and compute the minimal delta on save via the JS `diffManifest(base, edited)`; persist `{ baseRef, manifestDelta }` to the `ApplicationVersion` instead of a blob.
  > Built 21 Nov: `PageDesignerHost` asks `GET /api/applications/{slug}/manifest-base?_version=` (`ManifestBaseController` → `EditorBaseService::authorizeBase()`: owners and editors only, without admin bypass, for a version they may open, and a base app only when the caller's organisation scope admits it) and edits the stored delta merged over that base. On save it PATCHes `{manifestDelta: diffManifest(base, edited)}` instead of a blob (`src/services/deltaEditing.js`). A base that cannot be loaded turns saving off, and a version without a base saves its `manifest` as before. The version keeps the `baseRef` it already has: this change does not set a base on a version that has none. PHPUnit `EditorBaseServiceTest`, `ManifestBaseControllerTest`. Vitest `tests/services/deltaEditing.spec.js` and the delta cases in `tests/views/PageDesignerHost.spec.js`.
- [x] 5.2 Wire live preview through the JS `mergeManifestDelta(base, editedDelta)` so preview equals the server-resolved result.
  > Built: the live preview renders the designer's `manifest`, which is `mergeManifestDelta(base, storedDelta)` on load. After an edit, `mergeManifestDelta(base, diffManifest(base, edited))` reproduces it, asserted in `PageDesignerHost.spec.js` and `deltaEditing.spec.js`, so the preview equals what the server resolves.
- [ ] 5.3 Surface orphaned-delta paths in the editor (and reuse the `settings-and-observability` admin surface) as a non-blocking warning; add the one new l10n string via the standard flow.
  > Partly built 21 Nov: the designer shows the orphaned delta paths `ManifestBaseResolver::baseOf()` reports as a non-blocking warning (`data-testid="orphaned-delta-paths"`), with its string in English and Dutch. Open: the admin surface. Which `settings-and-observability` screen should list them, and for which apps, is a design question.

## 6. Backwards-compatibility

- [x] 6.1 Verify a pre-existing blob app (blob present, no `baseRef`) resolves byte-for-byte unchanged — no base resolution, no merge attempted.
  > Verified: a version with no `baseRef` returns its blob identically and makes no lookup (`ManifestBaseResolverTest::testALegacyBlobIsServedUnchanged`, `ManifestResolverDeltaTest::testANamedVersionIsMergedAndALegacyBlobIsNot`).
- [x] 6.2 Confirm no data migration is triggered for existing apps; new fields default to absent.
  > Confirmed: this change adds no repair step or migration; `baseRef` and `manifestDelta` stay optional and absent on existing versions.

## 7. Tests

- [ ] 7.1 Newman: extend `manifest-endpoint.spec` — legacy-blob app serves verbatim; delta-mode app serves a merged manifest; raw `baseRef`/`manifestDelta`/diagnostics absent from the response.
- [x] 7.2 PHPUnit: `ManifestResolverService` base+delta resolution, legacy fallback, pinned-vs-live base, orphan skip + diagnostic collection, cycle/depth guard.
  > Built: `ManifestBaseResolverTest` (base + delta, legacy fallback, pinned vs live, orphan skip and report, loop and depth) and `ManifestResolverDeltaTest`.
- [x] 7.3 Frontend unit test: editor diff-on-save persists a minimal delta and preview equals merged base+delta.
  > Built: `tests/services/deltaEditing.spec.js` and the delta cases in `tests/views/PageDesignerHost.spec.js`. A one-entry edit persists only the changed entries (no `menu`, no `version`, no blob), and base plus delta equals the previewed manifest.

## 8. Verify

- [ ] 8.1 Run `composer check:strict` and the relevant hydra mechanical gates (spec-coverage, route-auth, no-admin-idor) green on the diff.
- [ ] 8.2 Manual: clone-from-template app stores `baseRef` + delta; edit one page; add a page to the base; confirm the derived app inherits it on next manifest fetch.
- [ ] 8.3 `openspec validate "app-delta-override"` passes and `openspec status` shows 4/4 complete before archiving.
