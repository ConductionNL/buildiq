## Why

Every Conduction fleet app (opencatalogi, pipelinq, decidesk, …) ships its layout as a **bundled `src/manifest.json`** baked into its JS bundle. A user who wants to reorder a dashboard, hide a widget, or relabel a page on their own instance cannot — the manifest is read-only at runtime, and the only way to change it is to fork the app and rebuild. The `@conduction/nextcloud-vue` change `manifest-delta-merge-and-flex-columns` (shipped) added the client-side primitives for this — `diffManifest(base, edited)` produces a minimal keyed delta on Save, and the `useAppManifest`/`useRuntimeManifest` loaders accept `mergeStrategy:'delta'` to apply a fetched delta over a bundled base. The sibling Buildiq change `app-delta-override` then taught Buildiq's **OpenBuilt virtual apps** (`Application`/`ApplicationVersion`) to store `baseRef + manifestDelta` instead of a frozen blob. What is still missing is the **backend half of the in-app edit feature for EXISTING fleet apps**: somewhere to persist the delta a fleet app's `cn-buildiq-edit-shell` produces, keyed to that app, and a way to serve it back so the app loads `bundled-manifest + delta`. This change is that store-and-serve backend, plus the availability signal the edit button needs.

## What Changes

- **New `AppOverride` schema** in `lib/Settings/openbuild_register.json` holding `{ appId, baseRef, manifestDelta, updatedBy, updatedAt }`, keyed by `appId`. This is the fleet-app analogue of `app-delta-override`'s `ApplicationVersion.baseRef`/`manifestDelta` fields: that change stores the delta for apps Buildiq *builds*; this one stores the delta for apps that *already exist* in the fleet. The keyed-delta shape (`manifestDelta`) is the same `diffManifest` output and is consumed by the same `mergeManifestDelta` contract.
- **Per-instance shared override (DECISION).** One `AppOverride` record per `appId`, shared by every user of the instance — an app's layout is a shared customization, not a personal preference. Writing requires NC login + an Buildiq-access check (see below). Per-user overrides are noted as a possible later mode, not built here.
- **Write endpoint** — `PUT /apps/buildiq/api/app-overrides/{appId}` accepting the `diffManifest` delta; authenticated (login + CSRF), validates the delta shape (rejects shapes that are not a keyed delta, rejects a delta that would resolve to an empty/blank manifest), upserts the `AppOverride` record, and records `updatedBy` = the calling UID.
- **Read/resolve endpoint** — `GET /apps/buildiq/api/app-overrides/{appId}` returns the stored `manifestDelta` (or `204`/empty when none). This is the **delta** the fleet app's loader fetches with `mergeStrategy:'delta'` + `options.endpoint` pointed at this URL, so the merge happens client-side over the app's own bundled manifest (the base Buildiq never has). See design D2 for why the read path returns the raw delta rather than a server-merged manifest (Buildiq does not hold fleet apps' bundled manifests).
- **`DELETE /apps/buildiq/api/app-overrides/{appId}`** — removes the override, reverting the app to its bundled manifest (reset/clear).
- **Availability capability** — Buildiq registers an `ICapability` advertising `{ buildiq: { enabled: true, canEdit: <bool> } }` so a fleet app's edit button has a robust signal beyond inspecting `OC.appswebroots`. `canEdit` is true when the Buildiq app is enabled and reachable by the calling user (the NC app group-restriction already gates reachability), false otherwise.
- **Auth posture** — every new route declares its NC auth attribute. The write/delete routes are `#[NoAdminRequired]` (login required) **with CSRF enforced** (no `#[NoCSRFRequired]`); anonymous callers are rejected. The read route is `#[NoAdminRequired]`. No route is public.
- **No BREAKING changes.** A fleet app with no `AppOverride` resolves to its bundled manifest exactly as today; the loaders' `mergeStrategy:'delta'` is opt-in per app.

## Capabilities

### New Capabilities

- `app-override-persistence`: The `AppOverride` schema (`{ appId, baseRef, manifestDelta, updatedBy, updatedAt }`, keyed by appId, per-instance shared), the `PUT`/`GET`/`DELETE /apps/buildiq/api/app-overrides/{appId}` endpoints (write/read/clear), delta-shape validation + fail-soft on a blank-resolving delta, `updatedBy` provenance, the runtime contract with nc-vue's `mergeStrategy:'delta'` loader, and the auth posture (login + CSRF on writes, reject anonymous).
- `buildiq-capability`: The `ICapability` advertising `{ buildiq: { enabled, canEdit } }`, where `canEdit` reflects Buildiq-access for the calling user, so the fleet-app edit button has a robust availability signal.

### Modified Capabilities

_None._ This change is additive — new schema, new routes, new capability. It does NOT modify the `buildiq-runtime` manifest endpoint (that is `app-delta-override`'s surface, for OpenBuilt apps) and does NOT modify `buildiq-application-register` (the `baseRef`/`manifestDelta` fields there are for `ApplicationVersion`, a different schema).

## Impact

- **Hard dependency:** `@conduction/nextcloud-vue` `manifest-delta-merge-and-flex-columns` (shipped) — `diffManifest`, `mergeManifestDelta`, `widgetEntry.id`, `$op:"remove"`, `__order`, and the `mergeStrategy:'delta'` + `options.endpoint` loader path are the contract this backend serves.
- **Sibling coordination:** `app-delta-override` (drafted in this repo). Both store a keyed `manifestDelta`; they coexist on different schemas (`AppOverride` for fleet apps here, `ApplicationVersion` for OpenBuilt apps there) and a different resolve path (client-side merge here because Buildiq lacks the fleet base; server-side merge there because Buildiq owns the OpenBuilt base). See design "Reconciliation".
- **Schema:** `lib/Settings/openbuild_register.json` — new `AppOverride` schema; register `version` bump; imported by the existing `ConfigurationService::importFromApp()` repair step.
- **Backend:** new `AppOverrideController` (or methods on a small controller) + `AppOverrideService` (upsert/get/delete + delta-shape validation), both consuming OR's `ObjectService` (ADR-022); new `Capabilities` class implementing `ICapability`, registered in `lib/AppInfo/Application.php`.
- **Routes:** `appinfo/routes.php` — three `app-overrides` routes, specific-first before the SPA catch-all.
- **Frontend (out of scope here, contract only):** `cn-buildiq-edit-shell` in nc-vue calls these endpoints; this change defines the HTTP contract it consumes.
- **Hydra gates:** route-auth (every new route declares its attribute), no-admin-idor (the write/delete are `#[NoAdminRequired]` and MUST carry an authorization guard — here the guard is the Buildiq-access check + login), route-reachability (every routed method exists; every Response-returning method is routed), spec-coverage, spec/e2e traceability.
- **Theming / i18n:** no new colours; any user-facing strings (e.g. a save-error toast surfaced by the shell) follow the standard l10n flow and live in nc-vue, not here.
