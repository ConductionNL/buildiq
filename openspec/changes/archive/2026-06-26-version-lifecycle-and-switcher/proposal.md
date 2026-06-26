---
kind: code
---

## Why

OpenBuild's two-object version model (ADR-002) is implemented backend-first — drafts,
the per-version manifest, `?_version=` routing, the publish lifecycle, and the
`Application.productionVersion` pointer all exist — but the maintainer has no working
UI to drive it. The version list is broken (it queries the wrong OR endpoint and
filters on a non-existent field, so it always renders empty), there is no way to open
or edit a non-production version from that list, no "new draft" affordance, no
"release" (set-as-production + publish) action, and the "Open app" button only ever
opens production with no way to reach other versions. This change adds the missing
maintainer cockpit so the safe-playground promise of ADR-002 becomes usable, on top
of the backend that already exists, with only thin backend glue.

## What Changes

- **BUG FIX — empty version list.** `src/views/VersionHistory.vue` calls the wrong OR
  endpoint (`/apps/openregister/api/objects/openbuild/application-version`, returns 0)
  and filters on the non-existent field `applicationUuid`. Repoint it to the working
  slug-based endpoint `GET /apps/openbuild/api/applications/{slug}/versions` and read
  the real fields (`name`, `slug`, `semver`, `status`, `application`, `register`,
  `manifest`). `ManifestLayersDetail.vue` (its only caller) must resolve and pass the
  app **slug** (it loads the `Application` object already, so the slug is available).
- **Click-to-open + per-row edit.** Clicking a version row opens the live builder at
  `?_version={versionSlug}` (view/use). A per-row **Edit** affordance opens the
  page/schema designer scoped to that version (designer routes already forward
  `?_version=` via `buildVersionedRoute`). The production version is clearly marked and
  the active version highlighted.
- **New-draft action.** From the app detail / manifest-layers surface, create an
  `ApplicationVersion` (`status: draft`, `manifest` cloned from the current production
  version, `application` = parent uuid, a generated name/slug). **Shared-register glue
  (small backend):** on create the new version SHALL inherit the **same** `register`
  as the current production version — data is shared, not duplicated.
- **Release action.** Set `Application.productionVersion` to the chosen version AND
  transition its status `draft → published` (the existing per-version lifecycle), and
  demote the previous production version (it loses the production role). Owner-only.
  Wires the existing publish lifecycle + `productionVersion` update.
- **"Open app" split button.** In `src/components/ApplicationDetailActions.vue`, the
  primary "Open app" still opens **production** (`/builder/{slug}`); an attached chevron
  (`NcActions`, already imported) lists all versions with View/Use (builder
  `?_version={slug}`) and Edit (designer `?_version={slug}`), production clearly marked.
- **i18n** EN + NL for all new strings.

This change deliberately **departs from ADR-002's per-version-register convention**: a
new draft shares production's register (manifest-only versioning). The deviation is
called out and reconciled in design.md.

## Capabilities

### New Capabilities
- `version-lifecycle-ui`: the maintainer cockpit for the version lifecycle — the
  fixed/repointed version list, click-to-open + per-row edit, the new-draft action, the
  release (set-as-production + publish + demote) action, and the "Open app" split-button
  version switcher. Frontend behaviour plus the thin backend release/demote wiring it
  drives.

### Modified Capabilities
- `application-versions`: the create path SHALL inherit the production version's
  `register` when no `register` is supplied (manifest-only / shared-register decision),
  and the "release" operation (set-production + publish-transition + demote-previous) is
  declared as an owner-only sequence over the existing lifecycle + `productionVersion`
  pointer.
- `version-routing-ui`: `VersionHistory` is repointed to the working slug-based versions
  endpoint and real fields (replacing the broken OR-object-time-travel query + the
  non-existent `applicationUuid` filter), and gains click-to-open + per-row edit.

## Impact

- **Frontend:** `src/views/VersionHistory.vue` (endpoint + fields + row click + edit),
  `src/views/ManifestLayersDetail.vue` (pass slug; surface new-draft + release),
  `src/components/ApplicationDetailActions.vue` (Open-app split button), reuse of
  `src/router/helpers.js` `buildVersionedRoute`, `src/composables/useApplicationVersion.js`.
- **Backend (thin glue):** `lib/Controller/ApplicationVersionsController.php` create path
  (shared-register inheritance), a release/set-production + demote controller method
  (owner-only, on `ApplicationPublishController` or `ApplicationVersionsController`),
  `appinfo/routes.php` route for the release endpoint.
- **Schema:** `lib/Settings/openbuild_register.json` only if a lifecycle/default tweak is
  needed (no new OR schemas — reuse `Application` / `ApplicationVersion`).
- **No new dependencies.** Related to (not duplicating) the separate
  `layered-versioned-app-deltas` change, which covers per-user deltas.
