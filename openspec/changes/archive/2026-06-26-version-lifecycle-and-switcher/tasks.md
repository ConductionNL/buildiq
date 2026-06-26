## 1. Backend glue — shared-register-on-create

- [x] 1.1 In `lib/Controller/ApplicationVersionsController.php::create()`, when the create
      payload has no `register`, resolve the parent Application's production version
      (`Application.productionVersion`) and set `payload['register']` to that version's
      `register` before saving. Honour an explicitly-supplied `register` unchanged. No new
      register is minted. (spec application-versions REQ-OBV-107)
- [x] 1.2 Guard the shared-register path: if the app has no production version yet (no
      register to inherit and none supplied), return a clear `422` rather than persisting a
      register-less version.
- [x] 1.3 PHPUnit: add `ApplicationVersionsControllerTest` cases — create inherits
      production register when omitted; create honours an explicit register; create with no
      production and no register → 422. (≥3 methods.)

## 2. Backend glue — release endpoint (set-production + publish + demote)

- [x] 2.1 Add an owner-only `release` controller method (on `ApplicationPublishController`
      or `ApplicationVersionsController`) that: (a) owner-gates via the existing owner check
      / `ApplicationVersionOwnerGuard`; (b) transitions the chosen version
      `draft → published` via the existing per-version lifecycle; (c) sets
      `Application.productionVersion` to the chosen version (validated by
      `guardProductionVersionOwnership`); (d) demotes the previous production version to
      `status: archived`. (spec application-versions REQ-OBV-110, design Decision 3)
- [x] 2.2 Register the release route in `appinfo/routes.php` with `#[NoAdminRequired]`
      (owner check in the method body). Ensure route-auth + route-reachability gates pass.
- [x] 2.3 Ensure release never drops or mints a register; when the chosen version shares
      production's register, the demoted previous production keeps its register intact.
- [x] 2.4 PHPUnit: release happy path (pointer moves, new published, old archived);
      foreign version → 422; editor (non-owner) → 403; NC-admin-without-owner → 403.
      (≥3 methods.)

## 3. Backend glue — shared-register delete guard

- [x] 3.1 In the version-delete path (`ApplicationVersionService::deleteVersion` /
      `dropPerVersionRegister`), when the version's `register` equals the production
      version's `register`, do NOT drop the register on `delete-now` — treat it as
      `keep-register` (drop the row only) or reject with `422` naming the shared-register
      constraint. (spec application-versions REQ-OBV-111, design Decision 2)
- [x] 3.2 PHPUnit: `delete-now` on a production-shared draft leaves the register intact;
      `delete-now` on a version with its own register still drops it (regression).

## 4. Schema patch (if needed)

- [x] 4.1 Review `lib/Settings/openbuild_register.json`: confirm
      `Application.productionVersion` is single-valued (one relation, not array) so the
      single-production invariant holds structurally. No new OR schemas are added; reuse
      `Application` / `ApplicationVersion`. Land any tweak as a JSON patch (not a new
      service class). Re-validate the JSON after editing.

## 5. Frontend — VersionHistory.vue bug fix + reachability

- [x] 5.1 Repoint `src/views/VersionHistory.vue` to
      `GET /apps/openbuild/api/applications/{slug}/versions`; remove the
      `/apps/openregister/api/objects/openbuild/application-version` call and the
      `applicationUuid` filter; read real fields (`name`, `slug`, `semver`, `status`,
      `application`, `register`, `manifest`). Add an `appSlug` prop. (spec
      version-routing-ui MODIFIED)
- [x] 5.2 In `src/views/ManifestLayersDetail.vue`, resolve the app slug from the loaded
      `Application` object and pass it to `VersionHistory` (it currently passes only
      `applicationUuid`). (spec version-lifecycle-ui)

## 6. Frontend — click-to-open + per-row edit

- [x] 6.1 Make each `VersionHistory` row activatable: production row → open
      `/apps/openbuild/builder/{slug}` (no `?_version=`); non-production row → open
      `/apps/openbuild/builder/{slug}?_version={versionSlug}`. (spec version-lifecycle-ui)
- [x] 6.2 Add a per-row Edit affordance (editor+ only) using
      `buildVersionedRoute(<designerRoute>, { slug }, versionSlug)` from
      `src/router/helpers.js`. Hide Edit for viewers.
- [x] 6.3 Mark the production version row and highlight the active version (resolved via
      `useApplicationVersion`).

## 7. Frontend — New-draft action

- [x] 7.1 Add a New-draft action (owner/editor only) on the Manifest-detail / app-detail
      surface that POSTs to `…/applications/{slug}/versions` with `status: draft`,
      `manifest` cloned from production, `application` = parent uuid, generated
      `name`/`slug` (`"Draft N"` / `draft-n`; fallback timestamp suffix on collision); omit
      `register` so the backend inherits production's. Refresh the list on success. (spec
      version-lifecycle-ui)

## 8. Frontend — Release action

- [x] 8.1 Add a Release action (owner-only) on a draft version that calls the release
      endpoint, then refreshes the list (new draft becomes production, old production
      demoted). Surface success toast + error message; do not move the production marker on
      failure. Confirm any confirmation prompt lives in its own modal file under
      `src/modals/` per ADR-004. (spec version-lifecycle-ui)

## 9. Frontend — Open-app split button

- [x] 9.1 In `src/components/ApplicationDetailActions.vue`, keep the primary "Open app"
      opening production (`/builder/{slug}`); add a chevron (`NcActions`, already imported)
      listing versions with View/Use (builder `?_version={slug}`) and Edit (designer
      `?_version={slug}`, editor+ only). Mark production; hide archived by default. (spec
      version-lifecycle-ui)

## 10. i18n EN + NL

- [x] 10.1 Wrap all new strings in `t('openbuild', …)`; English source as key. Add Dutch
      translations to the `openbuild` `nl` catalogue. No Dutch string used as a key. (spec
      version-lifecycle-ui)

## 11. Build, gates, deploy

- [x] 11.1 `npm run build`; bump `info.xml` `<version>` for cache-bust per the
      immutable-asset rule; deploy to the served path
      (`openregister/custom_apps/openbuild`).
- [x] 11.2 Run Hydra mechanical gates (modal-isolation, nc-input-labels, route-auth,
      route-reachability, spec-coverage, no-admin-idor, spdx). Fix any failures.

## 12. Playwright UI validation (browser-1)

- [x] 12.1 On `http://localhost:8080/apps/openbuild/applications/45c8a87f-c94c-44d3-ade1-765d182a1b0b`
      (test23): verify the version list renders at least one version (not empty);
      click a version row opens the builder at `?_version={slug}`; per-row Edit opens the
      designer with `?_version=`.
- [x] 12.2 Exercise New-draft → a new draft appears in the list sharing production's
      register; then Release the draft → it becomes production (marker moves), previous
      production demoted.
- [x] 12.3 Verify the "Open app" split button: primary opens production; chevron lists
      versions with View/Use + Edit and the production marker.
