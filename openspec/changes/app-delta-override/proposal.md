## Why

Buildiq freezes every built app as a **whole-manifest blob** on its `ApplicationVersion.manifest`: the editor copies the base app/template manifest in full and persists it, then `ManifestResolverService` serves that frozen copy verbatim. Three problems fall out of this. (1) A built app is **permanently severed from its base** — once the blob is saved, every later base-app bugfix, new page, or widget added upstream is frozen out and never reaches the derived app. (2) **Storage bloat** — each built app stores a complete copy of a manifest that is mostly identical to its base, and every version snapshot multiplies it. (3) **You cannot patch one page** — because arrays merge by replacement, the editor must re-persist the entire `pages[]`/`widgets[]` tree to change a single page, so a one-field tweak rewrites (and re-freezes) everything.

The `@conduction/nextcloud-vue` change `manifest-delta-merge-and-flex-columns` has now shipped the foundation that fixes the root cause: an optional stable `widgetEntry.id` merge key, the pure utils `mergeManifestDelta(base, delta)` and `diffManifest(base, edited)`, a `{ "$op": "remove" }` marker, `__order` reordering, keyed page/widget merge, and an `orphanedDeltaPaths` surface. This change consumes that foundation so Buildiq stores **`baseRef` + `manifestDelta`** instead of a frozen blob, and resolves base + delta at serve time.

## What Changes

- **Add `baseRef` and `manifestDelta` fields to the manifest-carrying schema** (`ApplicationVersion` in `openbuild_register.json`, where the blob lives today per ADR-002). `baseRef` names what the design extends — another OpenBuilt app/template slug, or a fleet app's bundled manifest id, or `null`. `manifestDelta` is the keyed delta (the `diffManifest` output) layered over the resolved base. Both are **optional and additive**.
- **Server-side resolution in the manifest endpoint** (primary). `ManifestResolverService::resolve()` resolves `baseRef` → base manifest, applies a PHP port of `mergeManifestDelta(base, delta)`, and returns the fully-merged manifest. Chosen over client-side merge so every consumer (the runtime `CnAppRoot`, the export pipeline, Newman, any HTTP caller) stays dumb and receives a complete manifest — no consumer needs the merge util.
- **PHP `mergeManifestDelta` port** matching the JS contract exactly (keyed page/widget merge by `id`, `$op:"remove"`, `__order`, plain-object recursion, orphaned-patch skip + surfacing). It is the server-side twin of the canonical JS util; the JS util remains the contract reference and is reused unchanged client-side for editor preview.
- **Editor computes the delta on save.** `ApplicationEditor` / `BuilderHost` use the JS `diffManifest(base, edited)` for live preview and persist the **minimal delta** (not the whole manifest) to `manifestDelta`, plus the chosen `baseRef`.
- **Backwards-compat for legacy blob apps.** An `ApplicationVersion` with a populated `manifest` blob and **no** `baseRef` is treated as `baseRef=null` → the blob IS the manifest, served unchanged. No data migration is forced; legacy apps keep working byte-for-byte.
- **Orphaned-delta surfacing.** When `baseRef` drift orphans a delta patch (the base deleted a page/widget the delta targeted), resolution is fail-soft (skip the orphan, still serve a usable app) and the orphaned paths are surfaced to the editor/admin so the staleness is observable.
- **No BREAKING changes.** Every new field defaults to today's behaviour; an app with neither `baseRef` nor `manifestDelta` resolves exactly as it does now (blob → manifest).

## Capabilities

### New Capabilities

- `app-delta-storage`: The `baseRef` + `manifestDelta` storage model on the manifest-carrying schema, the server-side base+delta resolution in the manifest endpoint (PHP `mergeManifestDelta` port matching the JS contract), the legacy-blob backwards-compat path, the editor's diff-on-save persistence of a minimal delta, and the orphaned-delta surfacing.

### Modified Capabilities

- `buildiq-runtime`: The slug-keyed manifest endpoint's resolution contract changes — it MAY now resolve a `baseRef` + `manifestDelta` pair into a merged manifest before responding, while still serving a legacy blob unchanged when no `baseRef` is set. (Delta spec at `specs/buildiq-runtime/spec.md`.)
- `buildiq-application-register`: The schema declaration gains the optional `baseRef` and `manifestDelta` properties on the manifest-carrying `ApplicationVersion` schema. (Delta spec at `specs/buildiq-application-register/spec.md`.)

## Impact

- **Hard dependency:** the `@conduction/nextcloud-vue` change `manifest-delta-merge-and-flex-columns` (stable `widgetEntry.id`, `mergeManifestDelta`/`diffManifest`, `$op`/`__order`, `orphanedDeltaPaths`) MUST land first — the JS contract is the reference the PHP port mirrors and the util the editor calls.
- **Schema:** `lib/Settings/openbuild_register.json` — `ApplicationVersion` gains `baseRef` + `manifestDelta` (and a matching backwards-compat note on the existing `manifest` blob).
- **Backend:** `lib/Service/ManifestResolverService.php` — base+delta resolution + new PHP `mergeManifestDelta` helper (likely a small `ManifestDeltaMerger` service); `lib/Controller/ApplicationsController.php` — `getManifest()`/`diffVersions()` now serve the resolved manifest and may attach orphaned-delta diagnostics.
- **Frontend:** `src/views/BuilderHost.vue` + the manifest editor — compute and persist `manifestDelta` via the JS `diffManifest`; live-preview via `mergeManifestDelta`; render an orphaned-delta warning surface.
- **RBAC:** unchanged — the existing per-Application `permissions` gate (`PermissionResolver`, `requirePermission`, the non-production version RBAC in `ManifestResolverService`) is untouched; resolution happens after the auth gate.
- **Theming / i18n:** no new colours or CSS variables; one new editor warning string (orphaned delta) follows the standard l10n flow.
