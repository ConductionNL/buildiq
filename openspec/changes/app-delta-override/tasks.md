## 0. Prerequisite (hard dependency)

- [x] 0.1 Confirm `@conduction/nextcloud-vue` `manifest-delta-merge-and-flex-columns` has landed in the consumed version — stable `widgetEntry.id`, `mergeManifestDelta`, `diffManifest`, `$op:"remove"`, `__order`, and `orphanedDeltaPaths` are all exported and documented. BLOCK all tasks below until confirmed.
- [x] 0.2 Pin the consumed nextcloud-vue version in Buildiq's `package.json` to the release that includes the foundation, and capture the canonical JS `mergeManifestDelta` unit-test fixtures for reuse as the PHP port's shared fixtures.

## 1. Schema fields (additive, no migration)

- [x] 1.1 Add the optional `baseRef` structured-reference property to the manifest-carrying `ApplicationVersion` schema in `lib/Settings/openbuild_register.json` (kind = template | buildiq-app | fleet-app, id, optional version pin), with a description and `additionalProperties: false`.
- [x] 1.2 Add the optional `manifestDelta` object property to `ApplicationVersion` (keyed delta: page-by-id, widget-by-id, `$op:"remove"`, `__order`), with a description pointing at the nextcloud-vue contract.
- [x] 1.3 Add a backwards-compat note to the existing `manifest` blob property: blob present + no `baseRef` ⇒ blob IS the manifest (legacy/standalone). Bump the schema `version`.
  - `baseRef` and `manifestDelta` already existed on `ApplicationVersion` for hybrid apps (unify-apps-with-app-type); `baseRef.kind` gains the enum `template | buildiq-app | fleet-app` and the optional `version` pin, and the three descriptions say how a delta-mode version is served. ApplicationVersion 0.5.0, register 0.16.0.

## 2. PHP delta merge (port of the JS contract)

- [x] 2.1 Create a `ManifestDeltaMerger` service implementing `merge(array $base, array $delta): array` — plain-object recursion, `pages[]` keyed by `page.id`, `widgets[]` keyed by `widget.id`, `$op:"remove"` deletion, `__order` reordering, non-keyed arrays replace wholesale (matches JS util).
- [x] 2.2 Collect orphaned-delta paths (a delta key matching no base entry is skipped, never fatal) and return them alongside the merged manifest (e.g. `{ merged, orphanedPaths }`).
- [x] 2.3 Add unit tests for `ManifestDeltaMerger` using the fixtures captured in 0.2; assert byte-identical output to the JS `mergeManifestDelta` for each fixture (page/widget merge, remove, reorder, orphan).
- [x] 2.4 SPDX headers, full PHPDoc, and pass `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) on the new service.

## 3. Base resolution

- [x] 3.1 In `ManifestResolverService`, add `resolveBase(baseRef)` — template ref → `ApplicationTemplate.manifest`; buildiq-app ref → that app's resolved production manifest (recursive); fleet-app ref → named fleet app's bundled manifest; honour an optional version pin.
- [x] 3.2 Add a depth cap + cycle guard to recursive `buildiq-app` base resolution (reuse the `guardNoCycle` precedent); a cycle/over-deep chain resolves to the deepest safe base + a diagnostic.

## 4. Endpoint resolution wiring

- [x] 4.1 In `ManifestResolverService::resolve()`, branch on `baseRef`: null/absent ⇒ return the legacy `manifest` blob unchanged; set ⇒ resolve base (3.x) + apply `ManifestDeltaMerger` (2.x) and return the merged manifest. Keep the RBAC gate ahead of any payload emission.
- [x] 4.2 Ensure `ApplicationsController::getManifest()` and `diffVersions()` return the resolved/merged manifest, strip `baseRef`/`manifestDelta` and orphaned-path diagnostics from the public response (mirror the existing `permissions`-stripping).
- [x] 4.3 Validate the merged manifest against the canonical app-manifest schema before responding; on validation failure emit the existing correlation-id error path, not a 500 stack.
  - `ManifestResolverService::resolveVersionManifest()` serves a version without `baseRef` (and a hybrid app's version, which keeps its client-side merge) as stored; otherwise it resolves the base (`ManifestBaseResolver` for templates and an installed fleet app's `src/manifest.json`; another built app through `resolve()`, so a pinned version passes that app's own gate, at most 5 deep with a cycle guard), merges `manifestDelta` with `ManifestDeltaMerger`, strips the raw keys, and keeps orphans, an unresolved base and a cycle in `diagnostics()`, never in the response. An unresolved base falls back to the stored blob. The merged manifest must carry a string `version` and a `pages` array (the same structural rules the save path and `AppRepoParser` apply); otherwise `ManifestResolutionException` reaches getManifest's correlation-id error. `diffVersions()` diffs the merged manifests.

## 5. Editor: diff-on-save + preview + orphan surface

- [ ] 5.1 In the manifest editor / `BuilderHost.vue`, load the resolved base for a delta-mode app and compute the minimal delta on save via the JS `diffManifest(base, edited)`; persist `{ baseRef, manifestDelta }` to the `ApplicationVersion` instead of a blob.
- [ ] 5.2 Wire live preview through the JS `mergeManifestDelta(base, editedDelta)` so preview equals the server-resolved result.
- [ ] 5.3 Surface orphaned-delta paths in the editor (and reuse the `settings-and-observability` admin surface) as a non-blocking warning; add the one new l10n string via the standard flow.

## 6. Backwards-compatibility

- [x] 6.1 Verify a pre-existing blob app (blob present, no `baseRef`) resolves byte-for-byte unchanged — no base resolution, no merge attempted.
- [x] 6.2 Confirm no data migration is triggered for existing apps; new fields default to absent.

## 7. Tests

- [ ] 7.1 Newman: extend `manifest-endpoint.spec` — legacy-blob app serves verbatim; delta-mode app serves a merged manifest; raw `baseRef`/`manifestDelta`/diagnostics absent from the response.
- [x] 7.2 PHPUnit: `ManifestResolverService` base+delta resolution, legacy fallback, pinned-vs-live base, orphan skip + diagnostic collection, cycle/depth guard.
  - `ManifestResolverDeltaTest` (11), `ManifestBaseResolverTest` (6), `ApplicationsControllerDiffVersionsTest` (delta-mode diff).
- [ ] 7.3 Frontend unit test: editor diff-on-save persists a minimal delta and preview equals merged base+delta.

## 8. Verify

- [ ] 8.1 Run `composer check:strict` and the relevant hydra mechanical gates (spec-coverage, route-auth, no-admin-idor) green on the diff.
- [ ] 8.2 Manual: clone-from-template app stores `baseRef` + delta; edit one page; add a page to the base; confirm the derived app inherits it on next manifest fetch.
- [ ] 8.3 `openspec validate "app-delta-override"` passes and `openspec status` shows 4/4 complete before archiving.
