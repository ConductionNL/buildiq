## Context

OpenBuild carries two parallel models for "a manifest a maker shapes":

1. **Virtual apps** — `Application` (identity + `productionVersion` relation, ADR-002) + `ApplicationVersion` (manifest carrier). The `app-delta-override` change taught `ApplicationVersion` to store `baseRef` + `manifestDelta` instead of a frozen blob, and `ManifestResolverService` server-merges base + delta on read. OpenBuild owns the base, so it can merge server-side and hand consumers a complete manifest.

2. **Hybrid / fleet apps** — a separate `AppOverride` record (`{ appId, baseRef, manifestDelta, updatedBy, updatedAt }`, keyed by appId, per-instance shared) from `openbuild-inline-edit-persistence`. The delta customizes an *already-installed* NC app (opencatalogi, pipelinq, …). OpenBuild does **not** hold the fleet app's bundled base manifest, so the delta is served raw via `GET /api/app-overrides/{appId}` and merged **client-side** by the fleet app's loader (`mergeStrategy:'delta'`).

The data shapes are nearly identical (`baseRef` + keyed `manifestDelta`), the merge contract is identical (`diffManifest`/`mergeManifestDelta`, already ported to PHP), yet the storage entity, the endpoints, the detail UI, and the maker's mental model are all forked. The Apps list, the creation wizard, and the detail page only know about virtual apps; hybrid apps are invisible in the maker's home.

Constraints (unchanged): OpenBuild owns no DB tables — all state is OR objects (ADR-022); business logic is schema-declarative where possible (ADR-031), cross-row/security/one-time-transform allowed as imperative exceptions; the resolved manifest must validate against the nextcloud-vue app-manifest schema; the existing per-Application `permissions` RBAC and the fleet-app client-side-merge contract are load-bearing and must not regress.

## Goals / Non-Goals

**Goals:**
- One unified "Apps" concept with an `appType` (`virtual`|`hybrid`) discriminator on the `Application` schema, so both kinds are first-class `Application` records with versions.
- Fold the fleet-app override into the existing `Application`+`ApplicationVersion(baseRef+delta)` model — a hybrid app is just an `Application` whose version's delta layers over an installed NC app's bundled manifest.
- Migrate existing `AppOverride` records into hybrid `Application` records idempotently, with zero forced client change.
- Preserve the `GET/PUT/DELETE /api/app-overrides/{appId}` HTTP contract as a compatibility shim sourced from the hybrid Application's version, so live fleet apps keep merging the delta client-side.
- Enforce a metadata-lock: a hybrid app's identity metadata (id/slug/name) is read-only (mirrors the underlying NC app); pages/widgets/menus/schemas-as-delta stay editable.
- Unify the UI: rename "Virtual apps" → "Apps", add a Virtual/Hybrid badge + an all/virtual/hybrid filter, branch the creation wizard, render hybrid identity fields read-only.

**Non-Goals:**
- Renaming the OR entity (`Application` stays the internal name — lowest churn).
- Re-implementing the merge: `app-delta-override`'s PHP `mergeManifestDelta` port + the JS util are the contract, reused unchanged.
- Changing the RBAC model, the version lifecycle (draft/published/archived), or the `productionVersion` resolution.
- Server-merging a fleet app's manifest (OpenBuild has no fleet base — the shim still hands out the raw delta for client-side merge).
- Per-user overrides (the `AppOverride` model was per-instance shared; hybrid apps stay per-instance shared).
- Branching DAG promotion / CI-CD triggers (ADR-002 roadmap, untouched).

## Decisions

### D1 — `appType` + `baseRef` are declarative fields on `Application`
**Choice:** Add `appType` (enum `virtual`|`hybrid`, `default: "virtual"`) and `baseRef` (a structured ref, same shape as `app-delta-override`'s `{ kind, id, manifestVersion? }`, here `kind: "fleet-app"`, `id: <NC appId>`) to the `Application` schema in `openbuild_register.json`. A *virtual* app has `appType: "virtual"` and (usually) no app-level `baseRef`. A *hybrid* app has `appType: "hybrid"` and `baseRef.id` = the installed NC app's appId.
**Why:** The discriminator is pure declarative data; OR aggregations/filters read it directly. Defaulting to `virtual` means every existing Application is unchanged on read. `baseRef` on the Application mirrors the per-version `baseRef` already on `ApplicationVersion` — for a hybrid app the version's `baseRef` resolves the fleet base, and the Application-level `baseRef` records the canonical link for listing/filtering without a version hop.
**Alternative considered:** A boolean `isHybrid`. Rejected — an enum leaves room for future kinds (e.g. `forked`) without a schema migration, matching ADR-002's "no schema change to add a tier" instinct.

### D2 — A hybrid app is `Application(hybrid)` + a delta-only `ApplicationVersion`
**Choice:** Migrate each `AppOverride` into one `Application` (`appType: hybrid`, `slug` = appId, `name` = the NC app's display name, `baseRef.id` = appId) plus one `ApplicationVersion` (status `published`, `baseRef.kind: "fleet-app"`, `manifestDelta` = the override's delta, no full `manifest` blob), with the Application's `productionVersion` pointing at it. The version owns no per-version data register beyond what the delta references (a fleet app already has its own data).
**Why:** This is the existing virtual-app shape with `appType: hybrid` flipped on, so the detail page, version history, and resolution path all work for hybrid apps with no new entity. The delta-only version reuses `app-delta-override`'s `baseRef`+`manifestDelta` storage verbatim.
**Alternative considered:** Keep `AppOverride` and just teach the UI to list it alongside Applications. Rejected — that perpetuates two entities and two endpoints; the whole point is one model.

### D3 — `GET/PUT/DELETE /api/app-overrides/{appId}` become compatibility shims
**Choice:** The three endpoints keep their exact HTTP contract (raw delta body, empty-delta-on-none, login + CSRF on writes, OpenBuild-access guard, `#[NoAdminRequired]`) but repoint their implementation to the unified model as the **single source** (no legacy `AppOverride` fallback): GET resolves the hybrid `Application` whose `baseRef.id == {appId}` (or `slug == {appId}`), reads its `productionVersion.manifestDelta`, and returns it raw. PUT upserts the hybrid Application + its delta-only version (creating both on first write, exactly as the wizard's hybrid branch would). DELETE archives/removes the hybrid Application's override (reverting the fleet app to its bundled manifest).
**Why:** Live fleet apps fetch this URL with `mergeStrategy:'delta'` and merge client-side — OpenBuild holds no fleet base, so the shim must still return the **raw delta**, not a server-merged manifest. Repointing the storage under the hood keeps every client byte-for-byte compatible while collapsing the entity. The shim is the seam that lets the migration be non-breaking.
**Alternative considered:** Drop the endpoints and require fleet apps to call a new `/api/applications/{slug}/manifest-delta`. Rejected — it forces a coordinated client release across the whole fleet; the shim avoids that entirely.

### D4 — Metadata-lock is a save-time / lifecycle validation guard, not a Service
**Choice:** For an `Application` with `appType: "hybrid"`, the backend REJECTS any update that changes `slug` or `name` (and the `appType`/`baseRef` linkage itself). Enforcement point: a declarative `x-openregister-validation` rule on the `Application` schema where expressible (e.g. "when appType==hybrid, name/slug are immutable vs the prior row"), backed by an OR lifecycle/update guard (`requires`) for the cross-row before/after comparison that a pure field-rule cannot express. Everything else on the version (pages, widgets, menus, schemas-as-delta) stays editable. Virtual apps keep full edit of name/slug.
**Why:** A hybrid app's identity *is* the underlying NC app's identity — letting a maker rename the slug would desync the `baseRef.id` link and the `/api/app-overrides/{appId}` shim key. ADR-031 §Exceptions allows an imperative guard for a validation that compares prior vs proposed state (a cross-row/temporal check a static field rule cannot do). The guard is a lifecycle/validation hook, not a CRUD Service (ADR-022 — no redundant controller wrappers).
**Alternative considered:** Make `slug`/`name` `readOnly: true` in the schema unconditionally. Rejected — virtual apps must keep editing them; the lock is conditional on `appType`.

### D5 — Resolution: hybrid `baseRef.kind: "fleet-app"` reuses the existing path, shim hands out raw delta
**Choice:** `ManifestResolverService` already branches on `baseRef`. For a hybrid app's `ApplicationVersion`, `baseRef.kind: "fleet-app"` means OpenBuild has no base to merge, so the version's own runtime manifest endpoint is **not** the consumer path; instead the `/api/app-overrides/{appId}` shim returns the raw `manifestDelta` for the fleet app to merge client-side (D3). A virtual app's `baseRef` (template/openbuild-app) continues to server-merge as today.
**Why:** Keeps the one place that understands the delta model (`ManifestResolverService` + the PHP merge port) authoritative, while honouring the physical reality that OpenBuild cannot merge a base it does not hold. The discriminator on `baseRef.kind` already exists in `app-delta-override` D3 — this change just wires the hybrid case to the raw-delta shim.

### D-RETIRE — `AppOverride` schema and rows are removed in this change (clean break)
**Choice (user-confirmed):** The migration copies every `AppOverride` into a hybrid `Application` + delta-only version, **then deletes the source `AppOverride` row**, and the `AppOverride` schema is **removed from `openbuild_register.json` in this same change**. After migration the unified model is the single source of truth; there is no legacy read path and no transition-window fallback.
**Why:** The user chose a clean break over a soft-deprecation window — one model, no cruft, no dual-source ambiguity in the `/api/app-overrides/{appId}` shim. The migration runs before the schema removal within the same release, and its idempotent find-by-`baseRef.id` guard (see Risks) makes the copy safe to re-run; once a row is copied and verified it is deleted in the same pass.
**Ordering within the change:** schema fields added → shim repointed to the hybrid Application → migration (copy + delete each row) → `AppOverride` schema removed. The shim never needs a legacy fallback because the migration completes before any consumer reaches the post-removal state.
**Alternative considered:** Soft-deprecate `AppOverride` for one release and remove later. Rejected by the user — adds a dual-source window and a follow-up change for no benefit in a single-instance migration that the repair step completes atomically.

### Declarative-vs-imperative decision (ADR-031)
- **Declarative:** `appType` and `baseRef` are plain schema properties (enum + structured object). The Apps-list filter and the Virtual/Hybrid badge read them directly. Where the metadata-lock can be expressed as a static field rule it lives in `x-openregister-validation`.
- **Imperative (justified exceptions):**
  - **Migration** (`AppOverride`→`Application(hybrid)`+`ApplicationVersion`) is a genuine one-time data transform across rows — ADR-031 §Exceptions (one-time data migration). It runs in a repair step / migration class, is idempotent, and creates no ongoing imperative business logic.
  - **Metadata-lock cross-row guard** (D4) compares the proposed update against the stored row's `slug`/`name` — a temporal/cross-state check a static field rule cannot express; ADR-031 §Exceptions(1) (cross-row guard), mirroring the existing `ApplicationVersionService::guardNoCycle()` precedent.
  - **The `/api/app-overrides/{appId}` shim** is a security-shaped + cross-object resolution path (resolve hybrid Application → version → raw delta), permitted under the existing `ManifestResolverService` two-step-lookup + RBAC-shaped-response precedent.

## Seed Data (ADR-001)

The install seeds two example apps so the unified model is testable out of the box. All identifiers below are **safe placeholders** — replace with real values at seed time.

**1. Virtual app — "Travel Permit Tracker" (from scratch, `appType: virtual`)**
A small from-scratch app for a fictional travel agency / municipal travel-permit desk.

```jsonc
// Application
{
  "@self": { "id": "00000000-0000-0000-0000-000000000000" },
  "slug": "travel-permit-tracker",
  "name": "Travel Permit Tracker",
  "description": "Track travel-permit requests for a municipal desk.",
  "appType": "virtual",
  "productionVersion": "00000000-0000-0000-0000-000000000000"
}
// ApplicationVersion (virtual: full manifest or baseRef:template)
{
  "@self": { "id": "00000000-0000-0000-0000-000000000000" },
  "name": "Production",
  "slug": "production",
  "application": "00000000-0000-0000-0000-000000000000",
  "register": "openbuild-travel-permit-tracker-production",
  "semver": "0.1.0",
  "status": "published",
  "baseRef": null,
  "manifest": { "version": "2.0.0", "menu": [ /* … */ ], "pages": [ /* … */ ] }
}
```

**2. Hybrid app — "Catalog (Gemeente Voorbeeld)" (override of installed `opencatalogi`, `appType: hybrid`)**
A municipality customizing the installed OpenCatalogi app: hides one dashboard widget and relabels a page. Identity is locked to the underlying app.

```jsonc
// Application (hybrid — slug/name mirror the NC app, read-only)
{
  "@self": { "id": "00000000-0000-0000-0000-000000000000" },
  "slug": "opencatalogi",
  "name": "OpenCatalogi",
  "description": "Local layout customization of the installed OpenCatalogi app.",
  "appType": "hybrid",
  "baseRef": { "kind": "fleet-app", "id": "opencatalogi", "manifestVersion": "<BUNDLED_MANIFEST_VERSION>" },
  "productionVersion": "00000000-0000-0000-0000-000000000000"
}
// ApplicationVersion (hybrid: delta-only, no full manifest blob)
{
  "@self": { "id": "00000000-0000-0000-0000-000000000000" },
  "name": "Production",
  "slug": "production",
  "application": "00000000-0000-0000-0000-000000000000",
  "register": "openbuild-opencatalogi-production",
  "semver": "0.1.0",
  "status": "published",
  "baseRef": { "kind": "fleet-app", "id": "opencatalogi" },
  "manifestDelta": {
    "pages": {
      "Dashboard": {
        "widgets": { "legacy-stats": { "$op": "remove" } }
      },
      "Publications": { "title": "Open Data" }
    }
  }
}
```

The hybrid example's delta is exactly the shape the `/api/app-overrides/opencatalogi` shim returns raw to the installed OpenCatalogi app for client-side merge — proving the shim end-to-end.

## Risks / Trade-offs

- **Migration runs twice / double-creates a hybrid Application** → Idempotent by design: find-by (`appType==hybrid` AND `baseRef.id == appId`) before create; if found, update the existing hybrid Application's version delta instead of creating a second one, and skip an already-deleted source row. Mirrors the `AppOverride` upsert-by-appId precedent.
- **Schema removed (D-RETIRE) before a row is migrated** → The repair step migrates (copy + delete) every `AppOverride` row *before* the `AppOverride` schema is removed, within the same change/release; the schema removal is the last ordered step. The shim is the single source from the moment it is repointed, so there is no window where a consumer reads a half-removed legacy entity.
- **A maker renames a hybrid app's slug and desyncs the shim key** → D4 metadata-lock rejects slug/name edits on hybrid apps server-side; the UI renders them read-only as defence-in-depth.
- **Route-id rename (`VirtualApps`→`Apps`) breaks existing deep-links/bookmarks** → Keep a redirect/alias from the old route id/path to the new one for one release; deep-link emitters updated in the same change.
- **A hybrid app's fleet base drifts (NC app rebuild removes a page the delta targeted)** → Inherited from `app-delta-override` D5: fail-soft, orphaned delta paths surfaced; the fleet app's client loader's `orphanedDeltaPaths` already handles this client-side.
- **`appType` default not applied to legacy rows on read** → OR applies schema `default` on read for absent fields; the filter/badge treat missing `appType` as `virtual`. Verified in the migration + a Vitest fixture.

## Migration Plan

1. Add `appType` (default `virtual`) + `baseRef` to `Application` in `openbuild_register.json`; bump register version. (Declarative; no data change yet — existing apps read as `virtual`.)
2. Land the metadata-lock guard (field rule where possible + lifecycle/update guard for the cross-row comparison) and its tests.
3. Repoint the `/api/app-overrides/{appId}` GET/PUT/DELETE methods to source/write the delta from the hybrid Application's production version (the shim) as the **single source** — no legacy fallback.
4. Run the idempotent migration (repair step) converting every `AppOverride` → `Application(hybrid)` + `ApplicationVersion(delta-only)`, then **deleting each source `AppOverride` row** once its copy is verified; re-runnable.
5. Remove the `AppOverride` schema from `openbuild_register.json` (ordered last among backend steps, after the migration has emptied it).
6. Ship the UI: rename to "Apps", badge, filter (URL query param), wizard hybrid branch, read-only hybrid metadata; keep the old-route redirect.
7. Seed the two example apps.

**Rollback:** The `appType`/`baseRef` fields are additive and harmless if unread. The UI rename reverts independently. Because the migration deletes `AppOverride` rows and removes the schema (D-RETIRE, user-confirmed clean break), rollback after migration means restoring from backup rather than re-reading legacy rows — acceptable for a single-instance, idempotent repair step run under maintenance. Before the migration step runs, revert is trivial (drop the new fields + un-repoint the shim).

## Implementation notes (live-verified 2026-06-20)

Live verification on the `:8080` dev env surfaced four integration realities that the design's stub-level assumptions missed. All are fixed and re-verified live:

1. **Repair steps run as the Anonymous system user** — the migration's `Application` writes hit the schema's `create:[admin]` guard and failed. Fix: the migration writes in system context (OR `_rbac:false` + `_multitenancy:false`), threaded through `AppOverrideService::upsert(..., systemContext: true)`. The HTTP shim keeps RBAC on (admin session), preserving the old `AppOverride` create:[admin] posture.
2. **Schema-drop must be all-or-nothing** — dropping the `app-override` schema cascade-deletes any rows still under it. The first run dropped the schema *despite* a failed row and destroyed that override's data. Fix: drop only when `failed == 0`; otherwise retain the schema + rows for retry.
3. **`productionVersion` linking** — `$applicationData + ['productionVersion'=>…]` (PHP array union) kept the pre-existing null key, so the link was silently dropped and the GET shim returned `{}`. Fix: explicit assignment after stripping the `@self` envelope.
4. **Metadata-lock scoping** — OR's `ObjectUpdatingEvent` exposes the schema as a numeric id (`@self.schema == '28'`), not the `application` slug, so a slug match never fired. Fix: scope the lock via the `appType` discriminator (unique to OpenBuild's Application schema). Note: the pre-existing `ProductionVersionGuardListener` shares the same dead slug-match and should be migrated to the same approach in a follow-up.

## Open Questions

_All resolved (user-confirmed):_
- **Migration deletes `AppOverride` rows after copying** (clean break, D-RETIRE) — not left for a follow-up.
- **`AppOverride` schema is removed in this change** (D-RETIRE) — not soft-deprecated.
- **Ships as one change with phased tasks** (not a hard chain) — see proposal "Change shape".
- **The all/virtual/hybrid filter persists in a URL query param** (shareable filtered views, matching ADR-002's URL-param version switching precedent).
