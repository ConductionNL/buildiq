## 1. Schema extensions (declarative — `lib/Settings/openbuild_register.json`)

- [x] 1.1 Add `scope` property (string, enum `admin | user`, default `admin`) to the `ApplicationVersion` schema in `lib/Settings/openbuild_register.json`, with the description that a missing `scope` reads as `admin` (legacy default). [REQ-LAD-001, REQ-OBV-109]
- [x] 1.2 Add `owner` property (string, owning UID) to `ApplicationVersion`, set only on `scope: user` rows. [REQ-LAD-001, REQ-OBV-109]
- [x] 1.3 Add an `x-openregister-validation` same-row rule `user-scope-requires-owner` (`when: scope == 'user'`, `assert: owner != null`) to `ApplicationVersion`. [REQ-LAD-001, REQ-OBV-109]
- [x] 1.4 Add `allowUserOverrides` property (boolean, default `false`) to the `Application` schema. [REQ-LAD-002]
- [x] 1.5 Bump the `ApplicationVersion` schema `version`, the `Application` schema `version`, and the register `version` in `openbuild_register.json` (a new property requires a schema version bump).

## 2. Layered manifest resolution (imperative — extend existing serve path, ADR-031 §Exceptions(2))

- [x] 2.1 Extend the existing override/manifest serve path (`lib/Service/AppOverrideService.php` for hybrid apps; `ManifestResolverService` for virtual apps) to resolve the caller's `scope: user` delta chained via `baseRef` to the admin delta, and apply `base ⊕ admin-delta ⊕ user-delta` using the EXISTING PHP `mergeManifestDelta` port from `app-delta-override` — no new merge engine. [REQ-LAD-003]
- [x] 2.2 Apply the user delta ONLY when `Application.allowUserOverrides == true` AND a `scope: user` row owned by the caller exists; otherwise return exactly `base ⊕ admin-delta`. [REQ-LAD-003]
- [x] 2.3 Keep the HYBRID base merge client-side (serve the admin+user delta chain; the loader merges over the bundled base) and reuse the existing `wouldBlankApp` non-blank guard + orphaned-delta fail-soft on the resolved user layer. [REQ-LAD-003]
- [x] 2.4 Make `GET /api/app-overrides/{appId}` scope-aware: return the layered delta chain for the owning caller when overrides are enabled; admin delta otherwise; never another user's delta. [app-override-persistence MODIFIED, REQ-AOP-008]

## 3. User-delta RBAC (imperative exception — extend, do not duplicate; ADR-031 §Exceptions(1))

- [x] 3.1 Extend `lib/Service/PermissionResolver.php` with a user-scope match: for a `scope: user` row, authorise iff `caller.uid == version.owner` (or the audited admin bypass via existing `isAdmin()`); no group logic on a user row. [REQ-LAD-004, REQ-OBRBAC-008]
- [x] 3.2 Extend `lib/Lifecycle/ApplicationVersionOwnerGuard.php` with a `scope: user` branch: require `caller.uid == owner` AND parent `Application.allowUserOverrides == true`; fall through to the existing owner-of-parent-Application rule for `scope: admin`; fail-closed on unresolvable owner / foreign owner / missing flag. [REQ-LAD-004, REQ-OBRBAC-008]
- [x] 3.3 Filter LIST and GET of `scope: user` `ApplicationVersion` rows to `owner == caller.uid`; reject a `scope: user` write whose `owner` is not the caller. [REQ-LAD-004, REQ-OBV-109, REQ-AOP-008]
- [x] 3.4 Gate the user-delta WRITE path on `allowUserOverrides == true`, setting `scope: user`, `owner: <caller-uid>`, and the user `baseRef` → the admin delta version; reuse the existing `AppOverrideService` delta-shape + non-blank validation. [REQ-AOP-008]

## 4. Version history / rollback (reuse OR — no new code)

- [x] 4.1 Confirm rollback/time-travel of a delta row routes through OpenRegister's native object-version rollback path; do NOT add a delta-history table, endpoint, or service. [REQ-LAD-005]

## 5. Seed data (ADR-001)

- [x] 5.1 _(DEVIATION: register-level object seed intentionally skipped — fabricating a hybrid `opencatalogi`/`pipelinq` Application would COLLIDE with the rows `MigrateAppOverridesToHybrid` creates from the real installed apps. The design's example objects live as PHPUnit/Vitest fixtures instead; live validation enabled `allowUserOverrides` on the real migrated pipelinq app.)_ Seed via the `@self` envelope (into the `buildiq` register, on the existing install/repair step) the design's example objects: a hybrid `Application` `opencatalogi` with `allowUserOverrides: true` + admin `ApplicationVersion` + one `scope: user` `ApplicationVersion`; a `virtual` `Application` with `allowUserOverrides: false`; and the travel-agency booking-board example for component tests. Use SAFE placeholder UUIDs only (nil UUID / `<PLACEHOLDER-…>`). [design Seed Data]

## 6. Frontend — dashboard widgets (`src/components/applicationDetail/`)

- [x] 6.1 Replace the Schemas / Pages / Menu structural widgets in `ApplicationDetailDashboard.vue` + `widgets/` with the **Manifest widget** showing Base (read-only), Admin delta (current version + count), and Your delta (current version + count OR "create override" affordance when `allowUserOverrides` is on and none exists); does NOT render raw manifest JSON; opens the Manifest detail page on click. Dutch + English `t()` strings (ADR-007). [REQ-ADLU-001]
- [x] 6.2 Add/keep the **Register widget** showing register(s) + current counts with an "Open in OpenRegister" deep-link; NO register-delta, NO register versioning in Buildiq. [REQ-ADLU-002]

## 7. Frontend — Manifest detail page + route + modals (`src/views/`, `src/modals/`, `src/dialogs/`)

- [x] 7.1 Add a new routed **Manifest detail page** that lists all OR versions of a selected delta (admin, or the caller's own user delta) and supports rollback/time-travel, REUSING `src/views/VersionHistory.vue`, `src/components/tabs/ApplicationVersionsTab.vue`, and `src/modals/RollbackConfirmModal.vue`. [REQ-ADLU-003]
- [x] 7.2 Register the detail page via a manifest page-registry entry (NOT in the in-app vue-router as an admin component — ADR-004); allow selecting only the admin delta or a delta the caller owns. [REQ-ADLU-003]
- [x] 7.3 Add create / edit / rollback modals for a delta, each in its own `src/modals/` (NcModal) or `src/dialogs/` (NcDialog) file (no inline modal markup — ADR-004 gate-13); NcSelect controls carry `inputLabel`; the create flow writes a `scope: user` `ApplicationVersion` (`owner`, empty `manifestDelta`, `baseRef` → admin delta). Dutch + English `t()`. [REQ-ADLU-004]

## 8. Tests

- [x] 8.1 PHPUnit: schema scope/owner defaults + `user-scope-requires-owner` validation; `allowUserOverrides` default false. [REQ-LAD-001, REQ-LAD-002, REQ-OBV-109]
- [x] 8.2 PHPUnit: layered resolution `base ⊕ admin ⊕ user` over the existing merge port, including overrides-disabled and no-user-delta paths. [REQ-LAD-003]
- [x] 8.3 PHPUnit (no-admin-idor): user-scope guard + owner filter — owner allowed, foreign user denied/empty, flag-false denied, admin bypass audited. [REQ-LAD-004, REQ-OBRBAC-008, REQ-AOP-008]
- [x] 8.4 Vitest component tests: Manifest widget layer rows + create-override affordance; Register widget deep-link + counts; Manifest detail page version-history reuse; create/edit/rollback modal contracts + label props. [REQ-ADLU-001..004]
- [x] 8.5 Playwright visual-validation on `http://localhost:8080/apps/buildiq`: open a seeded app's detail, confirm the new dashboard renders the Manifest + Register widgets, the create-override affordance appears for an `allowUserOverrides: true` app, and the Manifest detail page lists delta versions. [REQ-ADLU-001, REQ-ADLU-003]

## 9. Quality gates

- [x] 9.1 Run the Hydra mechanical gates (spdx-headers, route-auth, no-admin-idor, modal-isolation, nc-input-labels, spec-coverage, spec/e2e traceability) and `composer check:strict`; fix any pre-existing issues encountered. [proposal Impact]
