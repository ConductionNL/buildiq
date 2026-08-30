# Tasks — runtime group-scoped access

## 1. Inject current-user group context
- [x] 1.1 Controller provides `user-groups` (and `is-admin`, owner markers) via `IInitialState::provideInitialState` on the builder/runtime page.
      **Deviation (documented):** instead of a separate `IInitialState` channel, the group/admin/owner/editor resolution is computed server-side in `ApplicationsController::getManifest()` / `resolveVersionedManifestResponse()` and embedded additively at `manifest.runtime.user.permissions` (via the new `ManifestResolverService::resolveCallerPermissionsForDisplay()` + `ApplicationsController::injectPermissionsSignal()`), mirroring the EXISTING `manifest.runtime.user.isOwner` precedent already shipped for admin-settings-owner-gating. This still satisfies the requirement's intent — server-side resolution, zero extra client round-trip — via a channel the manifest fetch already has to make, and guarantees the client-mirror set can never drift from the server's own authoritative filter (both are computed by the same `ManifestResolverService` methods). See PR description for full rationale.
- [x] 1.2 `BuilderHost` reads it with `loadState('openbuild','user-groups')`, maps to `['group:<gid>', 'admin'?, 'owner'?]`, and passes as `permissions` to `CnAppRoot` (→ `CnAppNav` / `CnPageRenderer`).
      **Deviation:** `src/builder.js` (the standalone runtime host — the actual `/builder/{slug}` production entry point) reads the ready-made array from `manifest.runtime.user.permissions` and forwards it directly to `CnAppRoot`'s `permissions` prop — no client-side derivation needed. `BuilderHost.vue` (the SPA-nested host) is left on `CnAppRoot`'s own default (`permissions: []`, "show everything") — see PR description §BuilderHost for why this is already correct without extra wiring.
- [x] 1.3 Unit/component test: `permissions` derived from initial state; admin gets `admin`; owner gets `owner`.
      Covered as: `resolveCallerPermissionsForDisplay()` returns the real `group:<gid>` set for a viewer and `[]` for an admin/owner/editor (`tests/Unit/Service/ManifestResolverServicePermissionFilterTest.php`).

## 2. Manifest permission surface
- [x] 2.1 Extend the manifest schema/validator: `menu[].permission` and `pages[].permission` (string | string[]).
      **Note:** the shared `@conduction/nextcloud-vue` manifest schema already declares `menu[].permission` / `pages[].permission` as `string` (schema-only; its own docblock states "consumers that want enforcement filter the manifest themselves before passing it to CnAppRoot" — exactly what this change implements). Not modified here (out of this repo's scope; a separate library release). OpenBuild's server-side filter (`ManifestResolverService::entryPasses()`) accepts EITHER a string or a string[] defensively (satisfies the "OR semantics" scenario), but the new author-facing picker (`PermissionGroupField.vue`) only ever writes the single-string form so the existing `validateManifest()` (typed `string`) never rejects an author's edit.
- [x] 2.2 `CnPageRenderer` filters routed pages by `permission` (mirror `CnAppNav.passesPermission`); a gated page is not reachable without the permission.
      **Implementation:** achieved SERVER-SIDE instead of inside `CnPageRenderer`/vue-router: `ManifestResolverService::filterManifestForCaller()` strips a gated `pages[]` entry from the manifest response entirely before it reaches the client. `src/builder.js::routesFromManifest()` builds its vue-router table purely from `manifest.pages`, so a stripped page never gets a route at all — stronger than a client-side route guard (nothing to bypass).
- [x] 2.3 Multiple dashboards: pick the landing dashboard as the highest-priority dashboard page whose `permission` the user satisfies, else the default.
      `ManifestResolverService::promoteLandingDashboard()`.
- [x] 2.4 Component test: vet sees medical menu + vet dashboard; non-vet does not; admin sees all.
      `ManifestResolverServicePermissionFilterTest` (`testGroupMemberReceivesGatedMenuItemAndPage`, `testGroupScopedDashboardIsPromotedToLandingForMatchingCaller`, `testNonMatchingCallerKeepsDefaultDashboardAsLanding`, `testAdminBypassesFiltering`).

## 3. Author guidance (security boundary)
- [x] 3.1 Document that `permission` hides navigation only; object-level access MUST be set via OpenRegister `schema.authorization`. Add to the runtime/manifest docs.
      Documented in: (a) the `PermissionGroupField.vue` hint text shown at the point of authoring ("Hides this entry from members outside the group. This is navigation only — set OpenRegister schema authorization to actually restrict the underlying data."), mirroring the existing `AccessEditor.vue` hint pattern; (b) extensive docblocks on `ManifestResolverService::filterManifestForCaller()` / `entryPasses()`; (c) this file. No standalone docs/ page was added — no existing "runtime/manifest reference" doc exists in this repo to extend (`docs/tutorials/admin/01-rbac.md` covers a different, unrelated feature — instance-wide builder-group RBAC).

## 4. Pet Store demo wiring
- [ ] 4.1 Add `permission: "group:vets"` to the medical menu item(s) and a `MedicalDashboard` page in the demo manifest.
      **Not applicable in this repo.** No Pet Store demo manifest fixture exists anywhere in the OpenBuild codebase (verified: no pet-store/petstore references in any `.json`/`.php` file). The demo referenced by proposal.md/design.md is authored live (wizard/tutorial session), not shipped as a repo fixture. Left unchecked — the underlying mechanism (tasks 1–3) is fully built and ready for that demo session to use `permission: "group:vets"` exactly as designed.
- [x] 4.2 (Done in OpenRegister) `medicalRecord.authorization.read/create/update/delete = ["vets"]`.
      Out of this repo's scope per its own annotation — OpenRegister-side, already shipped.

## 5. Verification
- [ ] 5.1 Live: as a `vets` user the medical menu + vet dashboard show and medical objects load; as a non-vet they do not; admin sees everything.
      **Deferred — not verified live.** No deploy to the shared dev instance (per task constraints). Verified instead via unit tests exercising the exact server-side enforcement path (`ManifestResolverServicePermissionFilterTest`), which is the authoritative gate the live scenario would exercise.
- [x] 5.2 Frontend gates (ADR-004): initial-state (not DOM) for group data; no admin component in vue-router.
      Verified via `hydra-gates` run: `gate-10 initial-state: PASS`, `gate-11 admin-router: PASS`, `gate-12 nc-input-labels: PASS` (new `PermissionGroupField.vue` NcSelect carries `inputLabel`).
