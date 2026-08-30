## Context

A fleet app renders its shell from a **bundled `src/manifest.json`** loaded at runtime by `@conduction/nextcloud-vue`'s `useAppManifest(appId, bundledManifest, options)` (or `useRuntimeManifest`). Today that manifest is read-only. The `manifest-delta-merge-and-flex-columns` change (shipped) added two things that make in-app editing possible:

1. **Author side** — `diffManifest(base, edited)` returns a minimal keyed delta (pages keyed by `page.id`, widgets by `widget.id`, `{ "$op": "remove" }` deletions, `__order` reordering, plain-object recursion). `cn-buildiq-edit-shell` calls this on Save to turn a user's in-place edits into a small delta.
2. **Loader side** — `useAppManifest`/`useRuntimeManifest` accept `options.mergeStrategy === 'delta'`: the fetched payload is treated as a delta and applied to the **bundled** manifest via `mergeManifestDelta(bundled, delta)`, surfacing `orphanedDeltaPaths`. The fetch URL is `GET /apps/{appId}/api/manifest` by default but is overridable via `options.endpoint`.

The sibling `app-delta-override` change taught Buildiq's **OpenBuilt virtual apps** to store `baseRef + manifestDelta` on `ApplicationVersion` and resolve them **server-side** in `ApplicationsController::getManifest`, because Buildiq *owns* those apps' base manifests (templates / other OpenBuilt apps / fleet bundled manifests it can read). That works for apps Buildiq builds.

What this change adds is the missing store for apps Buildiq does **not** build — the existing fleet apps. There is no `ApplicationVersion` for opencatalogi; its base manifest is bundled inside opencatalogi's own JS and Buildiq has no copy of it. So Buildiq can store and serve the *delta* keyed to the `appId`, but cannot server-merge it — the merge must run client-side in the fleet app's own loader, over the bundled base only that app holds.

Constraints: Buildiq owns no DB tables (ADR-022 — all state is OR objects via `ObjectService`); business logic is schema-declarative where possible (ADR-031), with cross-row/security-shaped logic allowed as imperative exceptions; every route must declare an NC auth attribute (route-auth gate); `#[NoAdminRequired]` write routes must carry an authorization guard (no-admin-idor gate).

## Goals / Non-Goals

**Goals:**
- Persist a fleet app's user edits as a keyed `manifestDelta` keyed to its `appId`, in a per-instance shared `AppOverride` record.
- Serve the stored delta back so the fleet app's loader applies it over its bundled base via `mergeStrategy:'delta'`.
- Advertise an availability + can-edit capability so the edit button has a robust signal.
- Let a user reset an app to its bundled manifest (clear the override).
- Authenticate every write (login + CSRF), reject anonymous, and record who saved.
- Reconcile cleanly with `app-delta-override` (different schema, different resolve path, same delta shape).

**Non-Goals:**
- Re-implementing the merge or diff semantics — the JS utils in nextcloud-vue are the contract; this change stores and serves their output.
- The `cn-buildiq-edit-shell` UI itself (lives in nextcloud-vue; this change is its backend contract).
- Server-side merge of fleet-app deltas — Buildiq does not hold fleet apps' bundled manifests (see D2).
- Per-user overrides (D1 picks per-instance shared; per-user is noted as a later mode).
- Editing OpenBuilt virtual apps — that is `app-delta-override`'s `ApplicationVersion` surface.
- A general JSON Patch / Merge Patch implementation — keyed-by-id delta is deliberate (mirrors the upstream contract).

## Decisions

### D1 — Persistence scope is per-instance shared, written by anyone with Buildiq access
**Choice:** One `AppOverride` record per `appId`, shared by every user of the instance. The write endpoint requires NC login + CSRF and an **Buildiq-access** check (the calling user can reach the enabled Buildiq app — the same NC app group-restriction that gates the edit button). Any such user MAY write; the record stores `updatedBy` so the last writer is attributable.
**Why:** An app's layout (page order, which widgets show, labels) is a **shared customization of the instance**, not a personal preference — the same intent as a tenant admin theming the instance. Gating the *button* on "anyone with Buildiq access" (the stated product decision) and the *write* on the same check keeps one coherent model: if you can open the editor, you can save. Per-instance also means a single resolve (`GET` returns one delta) with no per-user fan-out, and it matches `app-delta-override`'s instance-level `ApplicationVersion` storage.
**Alternative considered:** Per-user overrides (one delta per `(appId, uid)`). Rejected as the default — it multiplies storage, complicates the resolve (the loader would have to pass the uid), and turns a layout change into an invisible personal fork that no one else sees, which is the opposite of a shared customization. It remains a clean future mode: add a `scope: 'user'|'instance'` discriminator and key the record by `(appId, uid)` for user scope. Documented, not built.

### D2 — The read path returns the RAW delta; the merge runs client-side in the fleet app's loader
**Choice:** `GET /apps/buildiq/api/app-overrides/{appId}` returns the stored `manifestDelta` JSON unchanged (or empty/`204` when none). The fleet app calls its loader with `mergeStrategy:'delta'` and `options.endpoint` set to this URL, so `mergeManifestDelta(bundledManifest, delta)` runs in the app, over the bundled base. Buildiq never merges a fleet-app manifest.
**Why:** Buildiq does **not hold** fleet apps' bundled manifests — opencatalogi's base lives inside opencatalogi's JS bundle. A server-side merge (the path `app-delta-override` chose for OpenBuilt apps) is impossible here because the base is unavailable to Buildiq. The loader already supports exactly this: `mergeStrategy:'delta'` treats the fetched payload as a delta over the `bundledManifest` it was given. So the only correct split is: Buildiq stores+serves the delta, the app's loader (which holds the base) merges. This is precisely the `useAppManifest` branch `if (options.mergeStrategy === 'delta') { mergeManifestDelta(bundledManifest, response.data) }`.
**Alternative considered:** Have Buildiq fetch the fleet app's bundled manifest (e.g. from its static assets) and server-merge to return a complete manifest (parity with `app-delta-override` D1). Rejected — there is no reliable, versioned way for Buildiq to obtain another app's bundled manifest at request time; it would couple Buildiq to every fleet app's asset layout and bundle version, and break the instant a fleet app rebuilds. Client-side merge keeps the base with the only component that authoritatively has it.

### D3 — `AppOverride` is a new schema, NOT a reuse of `ApplicationVersion`
**Choice:** Add `AppOverride` to `openbuild_register.json` with `{ appId (kebab-case NC app id, unique key), baseRef (optional, see D5), manifestDelta (keyed delta object), updatedBy (uid), updatedAt (ISO-8601) }`. `appId` is the natural key; the upsert finds-by-appId then creates-or-updates.
**Why:** `ApplicationVersion` models a versioned OpenBuilt virtual app (draft/published lifecycle, productionVersion relation, slug). A fleet override has none of that — it is a single current delta keyed to an external NC app id, with no version lifecycle. Overloading `ApplicationVersion` would force a fake Application row for every fleet app and entangle the two resolve paths. A dedicated, tiny schema keeps the two models orthogonal and the resolve trivial (find by `appId`).
**Alternative considered:** Store the delta as a field on a synthetic `Application` record for each fleet app. Rejected — it pollutes the OpenBuilt app list (`listMine`) with phantom apps and couples fleet overrides to OpenBuilt RBAC, version routing, and the `BuiltAppRoute` index, none of which apply.

### D4 — Delta-shape validation + fail-soft on a blank-resolving delta
**Choice:** The write endpoint validates that the body is a **keyed delta** (a plain object; arrays of page/widget entries carry ids where the upstream contract requires them; `$op` values are the known marker; `__order` is an array of ids) before persisting. It additionally rejects a delta that, applied to an empty base, would resolve to a manifest with no pages/menu (a "blank-the-app" delta) — the write returns `422` rather than persisting a record that bricks the app on next load. Read never 500s on a stored delta; if a stored delta later orphans against a drifted base, that is surfaced client-side via the loader's `orphanedDeltaPaths` (the loader already does this).
**Why:** The whole risk of in-place editing is a bad delta blanking the app for everyone (it is per-instance shared — one bad save affects all users). Validating shape on write and refusing an obviously app-blanking delta is the cheapest guard. We cannot fully validate the *merged* result on write (Buildiq lacks the base — D2), so the deep guard (orphan surfacing, schema validation of the merged manifest) correctly lives client-side where the base is present; Buildiq does the shape + non-blank guard it *can* do.
**Alternative considered:** Accept any JSON and rely entirely on the client. Rejected — a malformed body (e.g. a whole manifest mistakenly POSTed, or `null`) would be stored and break every consumer; a cheap shape gate on write fails fast with a clear `422`.

### D5 — `baseRef` is stored but optional (provenance / future server-merge), not load-bearing here
**Choice:** `AppOverride.baseRef` is an optional structured note of what the delta was diffed against (e.g. `{ kind: 'fleet-app', id: appId, manifestVersion?: '<bundled manifest version>' }`). It is **not** used to resolve a base in this change (D2 — client merges over its own bundled base). It is captured so (a) base drift is diagnosable and (b) a future server-merge mode (if Buildiq ever gains access to fleet bundled manifests) has the reference.
**Why:** It costs nothing to record what the delta extends, and it aligns the `AppOverride` field shape with `app-delta-override`'s `baseRef` so the two stay conceptually parallel. Making it non-load-bearing avoids forcing Buildiq to resolve a base it cannot reach.
**Alternative considered:** Omit `baseRef` entirely. Rejected — without it, a stale delta (diffed against an old bundled manifest version) is undiagnosable; the field is the breadcrumb for "this delta was authored against manifest vX".

### D6 — Availability via a single `ICapability` advertising `{ enabled, canEdit }`
**Choice:** A `Capabilities` class implements `OCP\Capabilities\ICapability` and returns `['buildiq' => ['enabled' => true, 'canEdit' => <bool>]]`, registered in `Application.php`. `canEdit` is computed for the calling user: true when the Buildiq app is enabled and the user is in scope of its group-restriction (i.e. the same condition that makes the in-app edit button reachable), false otherwise. The fleet app reads this via `@nextcloud/capabilities` (`getCapabilities().buildiq?.canEdit`).
**Why:** The product gating decision is "anyone with Buildiq access may edit". Inferring that from `OC.appswebroots` (does `buildiq` have a webroot?) is brittle — it answers "is the app installed" not "can THIS user reach it under the group-restriction". `ICapability` is computed server-side per request with the real user context, so it is the robust signal. It also gives the fleet app one boolean to gate the button on, instead of duplicating group-restriction logic client-side.
**Alternative considered:** Frontend-only `OC.appswebroots` check. Rejected as the *authoritative* gate — it ignores the NC app group-restriction (a user not in the allowed group still sees `buildiq` in `appswebroots` on some NC versions) and cannot express `canEdit:false` for a reachable-but-restricted case. The capability supersedes it; `appswebroots` remains a cheap pre-check the button MAY use before reading the capability.

### Declarative-vs-imperative (ADR-031)
The `AppOverride` schema (`appId`/`baseRef`/`manifestDelta`/`updatedBy`/`updatedAt`) is a declarative OR schema. The upsert-by-appId, the delta-shape validation, and the `canEdit` computation are imperative paths — permitted under ADR-031 §Exceptions (cross-row find-or-create resolution and security-shaped computation), mirroring the existing `ApplicationsController` precedent for find-then-write + RBAC-shaped responses.

## Risks / Trade-offs

- **A bad delta blanks the app for everyone** (per-instance shared) → D4 write-time shape + non-blank guard rejects obvious cases with `422`; the loader's client-side `orphanedDeltaPaths` + schema validation catch the rest and fall back to the bundled manifest (the loader already falls back on validation failure). Net: a bad save cannot 500 the app; worst case the loader ignores an orphaned patch.
- **Base drift orphans a stored delta** (the fleet app rebuilds and renames/removes a page the delta targeted) → the loader skips the orphaned patch and surfaces it via `orphanedDeltaPaths`; `AppOverride.baseRef.manifestVersion` (D5) lets an admin see the delta was authored against an older bundle. Mitigation is observability, not prevention — the app stays renderable.
- **Concurrent writes** (two users save different layouts) → last-writer-wins on the single per-instance record; `updatedBy`/`updatedAt` record who/when. Acceptable for a shared customization; a future optimistic-concurrency token (`If-Match` on `updatedAt`) is a clean add if churn becomes a problem. Noted as an open question.
- **`canEdit` says true but the write is later rejected** → the write endpoint re-checks Buildiq-access server-side; `canEdit` is a UI hint, the endpoint is the boundary. No trust is placed in the client signal.
- **Anonymous or cross-app abuse of the write endpoint** → `#[NoAdminRequired]` (login required) + CSRF enforced (no `#[NoCSRFRequired]` on writes) + the Buildiq-access guard; anonymous is rejected. The `{appId}` is a path param, but there is no per-object ownership to leak (the override is instance-shared) — the guard is "can this user use Buildiq", satisfying no-admin-idor for a shared resource.
- **`appId` spoofing** → `appId` is validated against the kebab-case NC-app-id pattern; an unknown `appId` simply stores an override no app will ever fetch (harmless), and a `DELETE` clears it. No cross-tenant data is exposed (instance-shared, no PII beyond `updatedBy`).

## Migration Plan

1. Confirm `manifest-delta-merge-and-flex-columns` is in the consumed `@conduction/nextcloud-vue` (`diffManifest`, `mergeManifestDelta`, `mergeStrategy:'delta'`, `options.endpoint`, `orphanedDeltaPaths`). Hard block.
2. Add the `AppOverride` schema to `openbuild_register.json` (additive; bump register `version`; imported via the existing repair step).
3. Land `AppOverrideService` (find-by-appId, upsert, delete, delta-shape + non-blank validation) + unit tests.
4. Land `AppOverrideController` with the three routes; register them specific-first in `routes.php`; declare auth attributes.
5. Land the `Capabilities` class + register it in `Application.php`.
6. Wire a fleet app's loader to `mergeStrategy:'delta'` + `options.endpoint` (proof-of-concept on one app; the broad rollout is per-app follow-on work, not this change).

**Rollback:** Remove the three routes and the `Capabilities` registration. The `AppOverride` schema is harmless if unread; existing override records become inert and fleet apps fall back to their bundled manifests (the loader silently falls back when the endpoint 404s). No data migration required to revert.

## Open Questions

- Should writes carry optimistic concurrency (`If-Match` on `updatedAt`) to make concurrent shared edits safe, or is last-writer-wins acceptable for v1? Lean: last-writer-wins for v1, add `If-Match` if churn appears.
- Should `GET` return `204 No Content` or `200` with an empty body / `null` when no override exists? Lean: `200` with an empty object `{}` so the loader's delta merge is a no-op and the bundled manifest passes through unchanged.
- Does `canEdit` need to also reflect a future per-app allow-list (some fleet apps opt out of in-place editing)? Lean: out of scope; if needed, add an app-config allow-list the capability consults.
- Where should the admin-facing "this instance has N app overrides" surface live — reuse `settings-and-observability`, or a small new panel? Lean: reuse observability, parallel to `app-delta-override`'s orphaned-delta surface.
