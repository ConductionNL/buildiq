## Context

Buildiq renders virtual apps by mounting a nested `CnAppRoot` and feeding it a manifest fetched from `GET /apps/buildiq/api/applications/{slug}/manifest` (`ApplicationsController::getManifest` → `ManifestResolverService::resolve`). Per ADR-002 the manifest lives on `ApplicationVersion.manifest` (a full JSON blob; the `Application` carries only identity + a `productionVersion` relation). When a citizen developer clones a template or extends a base app, the editor copies that source manifest **in full** into the new version's blob, and from then on the two are unrelated copies.

`@conduction/nextcloud-vue`'s `manifest-delta-merge-and-flex-columns` change supplies the missing primitives: an optional stable `widgetEntry.id` (the v2 schema previously had no widget identity, which forced array-replace merges), the pure utils `mergeManifestDelta(base, delta)` / `diffManifest(base, edited)`, the `{ "$op": "remove" }` deletion marker, the `__order` reorder key, keyed page/widget merging, and an `orphanedDeltaPaths` surface for patches that match nothing in the base. That change is **the contract** this one consumes. It is explicitly framed there as "a separate OpenBuilt change will consume it to store `baseRef + delta` instead of a blob" — this is that change.

Constraints: Buildiq owns no DB tables (ADR-022 — all state is OR objects); business logic is schema-declarative where possible (ADR-031), with cross-row / security-shaped resolution allowed as imperative exceptions; the manifest must validate against `@conduction/nextcloud-vue/src/schemas/app-manifest.schema.json` after resolution. RBAC (the per-Application `permissions` block, `PermissionResolver`, and the non-production-version gate in `ManifestResolverService`) is load-bearing and must stay exactly as-is.

## Goals / Non-Goals

**Goals:**
- Store a built app as `baseRef` (what it extends) + `manifestDelta` (the keyed diff) on the manifest-carrying `ApplicationVersion`, instead of a frozen whole-manifest blob.
- Resolve base + delta **server-side** in the manifest endpoint so every consumer receives a complete, already-merged manifest and stays dumb.
- Let built apps inherit later base-app upgrades automatically (resolution re-reads the live base each request).
- Keep every existing legacy-blob app working byte-for-byte with zero forced migration.
- Surface orphaned delta paths (base drift) so staleness is observable without blanking the app.
- Have the editor persist a **minimal** delta computed via the canonical JS `diffManifest`, and preview live via the canonical JS `mergeManifestDelta`.

**Non-Goals:**
- Re-implementing the merge semantics from scratch — the JS util in nextcloud-vue is the contract; the PHP port mirrors it.
- Backfilling `widgetEntry.id` across existing fleet/template manifests (a separate optional codemod, tracked upstream).
- Changing the RBAC model, the version lifecycle (draft/published/archived), or the `productionVersion` resolution.
- A general JSON Patch (RFC 6902) / Merge Patch (RFC 7386) implementation — see D1 of the upstream change; keyed-by-id is deliberate.
- Forcing existing blob apps onto the delta model.

## Decisions

### D1 — Resolution is server-side in the manifest endpoint (primary); client merge is preview-only
**Choice:** `ManifestResolverService` resolves `baseRef` → base manifest, applies the PHP `mergeManifestDelta(base, delta)`, and returns the fully-merged manifest from `getManifest`/`diffVersions`. The runtime `CnAppRoot`, the export pipeline, Newman, and any HTTP caller receive a complete manifest and never see `baseRef`/`manifestDelta`.
**Why:** Keeps every consumer dumb — only one place (the server) understands the delta model, so a CnAppRoot bug, an export bug, and a Newman assertion can't each re-derive the merge differently. It also means built apps inherit base upgrades the instant the base changes, because resolution re-reads the live base on every request. The editor still merges client-side for **live preview** using the identical JS util, so the preview and the served result agree by construction.
**Alternative considered:** Return `{ base, delta }` and let `CnAppRoot` merge via `mergeStrategy:'delta'` (the upstream loader already supports this). Rejected as the *primary* path — it would push the merge contract into every consumer and the export/Newman/CLI paths that don't run the Vue loader, multiplying the surface that must agree. The upstream `mergeStrategy:'delta'` option remains available as a future opt-in for thin clients, but server-side resolution is the default the runtime relies on.

### D2 — PHP `mergeManifestDelta` is a faithful port of the JS util, in its own small service
**Choice:** A `ManifestDeltaMerger` service (or equivalent helper in `ManifestResolverService`) implements the keyed merge: plain objects recurse; `pages[]` merge by `page.id`; `widgets[]` merge by `widget.id`; `{ "$op": "remove" }` deletes a keyed entry; `__order: [...ids]` reorders; a delta entry whose key matches nothing in the base is **skipped** and its path collected. Arrays without a keyed identity (e.g. `menu[]` unless the upstream change keys it) replace wholesale, matching the JS util.
**Why:** The merge must produce **bit-identical** output to the JS util the editor previews with, or the preview lies. Pinning the PHP behaviour to the JS contract (mirrored from the upstream `design.md` D1/D4) is the only way to guarantee that. Keeping it as a discrete, pure function makes it unit-testable against shared fixtures lifted from the nextcloud-vue util tests.
**Alternative considered:** Shell out to a Node process running the real JS util. Rejected — a runtime Node dependency on the manifest hot path is operationally fragile and slow; a small, fixture-tested PHP port is cheaper and matches ADR-031's imperative-exception allowance for structural transforms.

### D3 — `baseRef` resolves to another OpenBuilt app/template OR a fleet app's bundled manifest
**Choice:** `baseRef` is a structured reference (e.g. `{ "kind": "buildiq-app" | "template" | "fleet-app", "id": "<slug-or-appId>", "version"?: "<versionSlug|semver>" }`). Resolution order: a `template` ref reads the `ApplicationTemplate.manifest`; an `buildiq-app` ref reads that app's resolved production manifest (recursively, with a depth cap); a `fleet-app` ref reads the named fleet app's bundled manifest as published by the runtime. A `null`/absent `baseRef` means "no base" (legacy/standalone).
**Why:** Templates and other built apps are the two real "extend from here" sources today (clone-from-template already deep-copies a template manifest), and fleet bundled manifests are the upgrade source the whole feature exists to track. A structured ref (vs a bare string) lets resolution disambiguate the source kind without guessing.
**Alternative considered:** Bare slug string. Rejected — ambiguous between a template slug, an app slug, and a fleet appId, and offers no seam to pin a base version.

### D4 — Legacy blob is `baseRef=null` → blob IS the manifest
**Choice:** Resolution branches first on `baseRef`. If `baseRef` is null/absent, the existing `manifest` blob is returned unchanged (today's exact behaviour). If `baseRef` is set, resolve base + apply `manifestDelta` over it. The `manifest` blob field is retained on the schema for legacy apps and as the resolution output's shape.
**Why:** Zero forced migration. Every app created before this change has a blob and no `baseRef`, so it falls into the legacy branch and serves byte-for-byte as it does now. New/edited apps opt into delta storage by gaining a `baseRef`. This mirrors the upstream change's "no consumer behaviour shifts" stance.
**Alternative considered:** A one-shot migration that diffs every existing blob against a guessed base and rewrites to delta form. Rejected as risky and unnecessary — the base for a legacy app is often unknowable, and the legacy branch costs nothing.

### D5 — Orphaned-delta paths are non-fatal and surfaced to the editor/admin
**Choice:** When applying `manifestDelta`, a patch whose key matches no base entry is skipped (the rendered app stays usable) and its path is collected into an orphaned-paths list. The merged manifest is still served (resolution never 500s on an orphan); the orphaned paths are attached as a diagnostic the editor and an admin surface can read (parallel to the upstream `orphanedDeltaPaths` ref). On the public manifest response the diagnostics are omitted — only the merged manifest is returned (mirrors the `permissions`-stripping in `getManifest`).
**Why:** Base drift (a base app deletes a page a delta patched) must never blank a derived app. Fail-soft + observable matches the upstream sentinel/orphan contract (D4 there) and gives citizen developers a way to notice and re-base.
**Alternative considered:** Hard-fail resolution on any orphan. Rejected — it would let an unrelated base edit take down every derived app at once.

### D6 — The editor persists a minimal delta, not a blob
**Choice:** On save, the editor loads the resolved base, runs the JS `diffManifest(base, edited)` to produce the minimal keyed delta, and persists `{ baseRef, manifestDelta }` to the `ApplicationVersion` (clearing/ignoring the legacy `manifest` blob for delta-mode apps). Live preview runs `mergeManifestDelta(base, editedDelta)` with the same JS util so what the developer sees equals what the server will later resolve.
**Why:** Storing the diff (not the whole edited manifest) is the entire point — it shrinks storage and keeps the app coupled to its base. Using the same JS util for diff-on-save and preview, and the PHP port for serve, closes the loop so the three never disagree.
**Alternative considered:** Persist the whole edited manifest and diff lazily on read. Rejected — it re-freezes the blob (the original problem) and makes "inherit base upgrades" impossible.

### Declarative-vs-imperative (ADR-031)
The `baseRef`/`manifestDelta` fields and the legacy `manifest` blob are declarative schema properties. The base resolution (cross-object/recursive lookup), the keyed structural merge, and the security-shaped omission of diagnostics from the public response are imperative paths — permitted under ADR-031 §Exceptions(1) (cross-row resolution) and the existing `ManifestResolverService` precedent for two-step lookups + RBAC-shaped responses.

## Risks / Trade-offs

- **PHP port drifts from the JS util** → the merge is fixture-tested against fixtures lifted from the nextcloud-vue `mergeManifestDelta` unit tests; CI runs the shared fixtures on both sides. The JS util is named the canonical contract in this change and the upstream one.
- **Base drift orphans a delta** → D5 fail-soft skip + surfaced orphaned paths; editor warning + admin surface; the app stays renderable.
- **Recursive `baseRef` (app extends app extends app)** → resolution applies a depth cap and detects cycles (reusing the cycle-guard precedent already in `ApplicationVersionService::guardNoCycle`); a cycle or over-deep chain resolves to the deepest safe base + a diagnostic.
- **A built app silently changes when its base ships a breaking manifest edit** → this is the intended "inherit upgrades" behaviour, but it can surprise. Mitigation: `baseRef` MAY pin a base version (D3 `version`), letting a developer opt out of live inheritance for stability.
- **Upstream change not yet landed** → this change is hard-blocked on `manifest-delta-merge-and-flex-columns`; tasks.md sequences the dependency check first and the spec references the JS contract rather than redefining it.
- **`widgetEntry.id` absent on a base/edited manifest** → `diffManifest` falls back to whole-array replace for that array (upstream D2) with a warning; correctness preserved, delta granularity degraded until ids are backfilled.

## Migration Plan

1. Confirm `manifest-delta-merge-and-flex-columns` is published in the consumed `@conduction/nextcloud-vue` (stable `widgetEntry.id`, `mergeManifestDelta`, `diffManifest`, `orphanedDeltaPaths`).
2. Add the optional `baseRef` + `manifestDelta` properties to `ApplicationVersion` in `openbuild_register.json` (additive; no data migration).
3. Land the PHP `mergeManifestDelta` port + fixture tests (fixtures shared with the JS util).
4. Wire base+delta resolution into `ManifestResolverService::resolve` behind the `baseRef`-present branch; legacy blob apps stay on the existing branch.
5. Update the editor to diff-on-save (`diffManifest`) and live-preview (`mergeManifestDelta`); add the orphaned-delta warning surface.
6. Roll out — existing apps are untouched; new/edited apps gain `baseRef` + delta storage.

**Rollback:** Remove the `baseRef`-present branch in `ManifestResolverService` (resolution falls back to the legacy blob branch for everyone) and stop the editor writing `baseRef`. The optional schema fields are harmless if unread; no data migration is required to revert. Apps that already saved a delta would need a one-time re-save to a blob, or the resolver branch can be left in place read-only.

## Open Questions

- Should `baseRef` default to pinning the base version (stable) or tracking it live (auto-upgrade)? Lean: track live by default (the feature's purpose) with an explicit pin available.
- Where does the admin-facing orphaned-delta surface live — the existing Application detail/observability page, or a new panel? Lean: reuse the observability surface (`settings-and-observability`).
- Should `fleet-app` `baseRef` resolution read the live bundled manifest at request time, or a snapshot taken at base-link time? Lean: live, consistent with the inherit-upgrades goal.
- Does the PHP port live in `ManifestResolverService` or a standalone `ManifestDeltaMerger` service injected into it? Lean: standalone service for unit-test isolation.
