## Context

This is spec #6 of the OpenBuilt 9-spec chain (ADR-032). Spec #1
(`bootstrap-openbuilt`) declared the Application lifecycle
(`draft → published → archived`) via `x-openregister-lifecycle` and
the foundational schemas; THIS spec ships the **version snapshot
mechanism** and the **draft / publish / rollback UX** that make the
lifecycle actually useful for citizen developers.

The architectural commitment from ADR-031 holds: the snapshot data
model (`ApplicationVersion`) and the snapshot-on-publish action are
**declarative** OpenRegister metadata. The UI surface — the Publish
button, the version-history panel, the rollback modal, the diff
component — is unavoidably code. The split mirrors bootstrap-openbuilt
(declarative lifecycle + thin-glue PHP + Vue UI) and applies ADR-032's
thin-glue exception for `kind: mixed`.

The runtime workaround documented in bootstrap-openbuilt's Decision 4
(`options.fetcher` redirect for `useAppManifest`) is **not** affected
by this spec — versioning operates on the stored manifest blob in OR,
not on the runtime loader path.

## Goals / Non-Goals

**Goals**

- Ship the `ApplicationVersion` schema declaratively, with the
  snapshot-on-publish action declared as `x-openregister-lifecycle.on_transition`
  metadata (or, per ADR-031 §Exceptions(1), as a single PHP listener
  if OR's engine lacks the hook — same OQ-1 fallback as
  bootstrap-openbuilt).
- Extend the Application schema with a `currentVersion` reference,
  kept in sync by the same lifecycle action.
- Ship the draft-vs-published indicator, the Publish action,
  `VersionHistory.vue`, the rollback action with confirmation modal,
  and a client-side `ManifestDiff.vue` component.
- Ship a thin `diffVersions` endpoint that returns two manifest blobs
  in one round-trip so the client diff component doesn't double-fetch.

**Non-Goals**

- JSON Patch / delta compression for snapshots (Decision 1 below
  picks full-blob copies for v1; chain it later if storage bites).
- Server-side diff rendering or PHP diff libraries (Decision 5).
- Retention caps / time-based expiry / "keep last N" (Decision 4).
- Branching, named release channels, parallel drafts (out of scope;
  if needed, spec it as a follow-on in the chain).
- Cross-Application snapshot inheritance / template-from-snapshot
  (that's chain spec #8 / marketplace territory).
- A standalone visual diff editor with in-place merge / patch
  application (out of scope; v1 is read-only diff visualisation).

## Decisions

### Decision 1 — Snapshot data shape: full manifest blob copy (not JSON Patch chain)

`ApplicationVersion.manifest` SHALL hold a **full deep-copy** of the
Application's manifest at snapshot time, not a JSON Patch against the
previous snapshot.

**Alternatives considered**

- *JSON Patch chain (RFC 6902 deltas)*. Compact storage, but
  reconstructing version N requires applying N-1 patches. Adds an
  unavoidable PHP service class (a "patch applier") that violates
  ADR-031's declarative-first stance. Failure modes (a corrupt patch
  in the middle of the chain) are recoverable in theory but ugly in
  practice; for a citizen-developer audience the simpler "every
  snapshot is a self-contained blob" mental model wins.
- *Hybrid (every N versions a full blob, deltas between)*. Same
  reconstruction complexity, same service-class objection. Defer.

Manifest blobs are kilobyte-scale JSON. At the expected publish
cadence (a handful per day per app on the high end) full-blob
retention costs are negligible compared to e.g. OR's audit-trail
storage. If a future spec shows storage pressure, JSON Patch chains
can be introduced behind a `compaction` flag without breaking the
schema contract (the `manifest` field stays full-blob; a sidecar
field would hold the patched form).

### Decision 2 — Lifecycle hook: declarative first, PHP listener fallback (ADR-031)

The snapshot-on-publish action SHALL be declared as
`x-openregister-lifecycle.on_transition` metadata on the
`Application` schema's `draft → published` edge. The action SHALL
create the `ApplicationVersion` row, update
`Application.currentVersion`, and (per Decision 3 below) reset the
Application's `status` to `draft`.

If OR's lifecycle engine cannot yet express an `on_transition` action
that creates a sibling object (and updates the parent), the spec MAY
fall back to a single PHP listener
`lib/Listener/ApplicationVersionSnapshotListener.php` subscribed to
OR's `ObjectLifecycleTransitionedEvent`. This is identical in pattern
to bootstrap-openbuilt's `BuiltAppRouteSyncListener` exception (its
OQ-1) and is permitted by ADR-031 §Exceptions(1). The implementer
SHALL:

- attempt the declarative path first and verify with an integration
  test that the action fires;
- only on demonstrated engine gap, ship the listener;
- if the listener path is taken, file an OR-side issue requesting
  the missing hook (referencing this spec and bootstrap-openbuilt's
  OQ-1) so the listener can be removed when the engine catches up;
- record the chosen path in this change's `hydra.json` under
  `decisions[]` for self-learning.

**Anti-pattern explicitly avoided.** This spec ships no
`VersioningService.publish()` / `SnapshotService.snapshot()` /
`ApplicationVersionManager` class. Anything that looks like a
generic versioning service is an ADR-031 review-block.

**Alternatives considered**

- *Always-PHP listener (skip declarative)*. Rejected. Even if the
  engine gap exists today, the declarative path is the canonical
  ADR-031 example we want to push OR's engine to support. Trying
  declarative first surfaces the gap as an actionable OR issue
  rather than hiding it behind code.
- *Inline the snapshot create in `ApplicationsController::publish`*.
  Rejected. No such controller method exists (per spec #1, CRUD is
  via OR REST + the lifecycle endpoint). Adding one duplicates the
  lifecycle abstraction OR already owns.

### Decision 3 — Rollback semantics: audit-clean (create new snapshot, never destroy)

Rollback SHALL be **non-destructive**. Rolling back to
`ApplicationVersion` N SHALL:

1. Copy N's `manifest` onto the Application's draft manifest.
2. Set the Application's `version` field to N's `version` suffixed
   with a rollback marker (e.g. `+rollback` or
   `<version>-rollback-<short-uuid>`) so that semver tooling
   recognises the next publish as a new distinct version.
3. Leave the Application's `status` as `draft` (the rolled-back
   manifest is now the working draft; the integrator can edit
   further and republish to create a fresh `ApplicationVersion` row).
4. Leave every existing `ApplicationVersion` row untouched —
   `ApplicationVersion` history is append-only.

Republishing after a rollback produces a NEW `ApplicationVersion`
row (the snapshot-on-publish action fires as for any normal publish).
This preserves a clean audit trail: every published state in the
Application's life is represented by exactly one
`ApplicationVersion` row, in append order.

A consequence — and a deliberate choice — is that on the `draft →
published` transition the lifecycle action ALSO resets the
Application's `status` back to `draft`. The published state is
represented by "an `ApplicationVersion` row exists and
`currentVersion` points at it", not by a flag on the Application.
This avoids the friction of "publish → must transition back to
draft manually before editing again" which would otherwise turn
the editor into a modal experience.

**Alternatives considered**

- *Destructive rollback (truncate history past version N)*. Rejected.
  Loses the auditable record of "we tried v1.2 in production and
  rolled back at 14:32" — exactly the kind of evidence a citizen
  developer needs when they call support.
- *Soft-delete (mark rows past N as "superseded")*. Rejected. Adds
  a state field that the UI must filter on. The append-only model
  is conceptually simpler and OR's standard `@self.deleted` is
  available if true deletion is ever needed.
- *Keep Application status `published` between transitions*. Rejected
  per the friction argument above. The "status = `draft` always when
  editable, `published` ephemerally at the moment of transition"
  model matches how integrators actually work (edit → publish → edit
  again).

### Decision 4 — Retention policy: keep all versions in v1

`ApplicationVersion` rows SHALL NOT be auto-deleted, trimmed, or
expired by OpenBuilt in v1. Retention is unlimited.

Rationale:

- Manifest blobs are small (kilobytes).
- Audit-trail loss is irreversible.
- A retention cap is opt-in and easy to add later (a
  `retentionPolicy` field on `Application` declaratively driving an
  OR cleanup job) without breaking the v1 contract.

If a deployment hits real storage pressure, OR's standard
`@self.deleted` soft-delete is available and can be wired to an
admin-initiated cleanup later. v1 ships without the lever.

**Alternatives considered**

- *Keep last N (e.g. 50)*. Rejected for v1. Imposes a choice on
  every installation without evidence the choice is needed. Easy
  to add as an opt-in field later.
- *Time-based (e.g. 90 days)*. Rejected for v1. Same objection.

### Decision 5 — Diff rendering: client-side via `jsdiff` (or equivalent)

The side-by-side diff SHALL be computed client-side. The frontend
SHALL add `jsdiff` (or an equivalent JS diff library — `diff` on
npm, `fast-diff`, etc.) to `package.json`. The `diffVersions`
endpoint returns both manifest blobs and nothing else; rendering is
the client's job.

**Alternatives considered**

- *Server-side diff via PHP `DiffMatchPatch` port or similar*.
  Rejected. Adds a PHP dependency, a server endpoint that returns
  hunks-not-text (forcing a new contract), and a tier of state the
  client needs to interpret. The client can pretty-print manifests
  and run a JS diff in tens of milliseconds for kilobyte blobs.
- *Use NC's existing diff display*. Investigated; NC's diff utilities
  target file diffs, not JSON object diffs. Unsuitable.

The `ManifestDiff.vue` component SHALL JSON-pretty-print both blobs
deterministically (sorted keys, stable indentation) before
diffing, so cosmetic key-order shuffles don't produce noise hunks.

### Decision 6 — Declarative-vs-imperative split (ADR-031)

| Candidate behaviour | Path |
|---|---|
| `ApplicationVersion` schema definition | **Declarative** — JSON in `lib/Settings/openbuilt_register.json`. No PHP class. |
| Snapshot-on-publish action | **Declarative** — `x-openregister-lifecycle.on_transition` action on the Application schema. ADR-031 §Exceptions(1) fallback to a single listener PHP class only if the engine gap is demonstrated. |
| `currentVersion` upkeep | **Declarative** — same action / listener as the snapshot. |
| Rollback (copy snapshot → draft manifest) | **Imperative — frontend** — initiated by the integrator's click; PUT against OR REST. No new server action needed because the manifest field is just an OR object property; the rollback is a normal write. |
| Manifest diff | **Imperative — frontend** — client-side `jsdiff` per Decision 5. |
| Diff endpoint | **Imperative — thin glue** — ~15 LOC PHP method that looks up two `ApplicationVersion` rows (or one + the draft Application) and returns both `manifest` blobs unwrapped. Exists only to save a round-trip; no business logic. |
| Version-history listing | **Frontend over OR REST** — no server-side wrapper. |
| Publish action button | **Frontend** — invokes the existing lifecycle endpoint declared by spec #1. |

**Anti-patterns explicitly avoided:**

- `VersioningService`, `SnapshotService`, `ApplicationVersionService`,
  `ManifestVersionManager` — any class whose name suggests "the
  service that does versioning". The lifecycle action does
  versioning; the diff endpoint is glue, not a service.
- A server-side rollback endpoint. Rollback is a write to the
  manifest field. OR REST already handles writes.
- Server-side diff hunks. The client renders.

### Decision 7 — Mixed-spec rationale (ADR-032)

Per ADR-032 this spec is `kind: mixed` because it adds:

- a declarative schema patch (the `ApplicationVersion` schema +
  the lifecycle action declaration on Application + the
  `currentVersion` field on Application);
- code (the `diffVersions` controller method + route, the
  `VersionHistory.vue` panel, the `ManifestDiff.vue` component, the
  rollback confirmation modal under `src/modals/` per Hydra
  modal-isolation gate, the editor changes for the Publish
  button + status badge, and — only if Decision 2's listener
  fallback is needed — `ApplicationVersionSnapshotListener.php`).

The code surface is bounded:

- **`ApplicationsController::diffVersions`** — ~15 LOC, mirrors
  `getManifest` from spec #1.
- **`appinfo/routes.php`** — ~3 LOC route entry.
- **`VersionHistory.vue`** — ~80 LOC including template. List view
  with no novel state; reads OR REST and renders rows.
- **`ManifestDiff.vue`** — ~120 LOC including template. The bulk is
  pretty-print + diff rendering.
- **`RollbackConfirmModal.vue`** (under `src/modals/` per Hydra
  modal-isolation gate) — ~40 LOC.
- **`ApplicationEditor.vue` modifications** — ~30 LOC added for the
  Publish button, status badge, and version-history mount.
- **Optional `ApplicationVersionSnapshotListener.php`** — ~50 LOC
  if Decision 2's fallback is needed.

Total worst-case code surface is ~340 LOC across 6 files. ADR-032
admits the thin-glue exception when "code is tightly coupled to the
declarative config and there is no clean way to ship the config
alone". That holds here: the version-history panel and Publish
button only make sense paired with the new schema + lifecycle
action; shipping them in a separate chain pays coordination cost
without removing review surface.

If, during apply, the code surface significantly exceeds these
estimates (e.g. the diff component balloons past ~200 LOC because
the JSON pretty-printer turns out gnarly), this spec MUST be split
into `openbuilt-versioning-schemas` (config only) +
`openbuilt-versioning-ui` (code only). At that point this design
document becomes the parent record for the split.

**Alternatives considered**

- *Pre-emptive split into config + code chain*. Rejected. The
  config is tiny (one schema + one lifecycle action + one field)
  and the code without the config has nothing to render. The
  natural unit of review is "versioning end-to-end works".
- *Pure-declarative*. Not possible — UI components for diff /
  history / rollback inherently require code.

## Risks / Trade-offs

- **Risk — declarative `on_transition` action may not support
  sibling-object create.** Same OR-engine-gap question as
  bootstrap-openbuilt's OQ-1. Mitigation: Decision 2 documents the
  ADR-031 §Exceptions(1) listener fallback; integration tests assert
  the observed behaviour in either path.
- **Risk — full-blob snapshots grow storage faster than expected.**
  Mitigation: manifest blobs are kilobyte-scale; if pressure
  materialises, a future spec can introduce delta compression
  behind a `compaction` flag without breaking the public schema
  contract (Decision 1).
- **Risk — rollback marker (`+rollback` semver suffix) collides
  with integrator-authored versions.** Mitigation: include a short
  UUID fragment in the marker (`<version>-rollback-<6hex>`) so
  collisions are astronomically unlikely.
- **Risk — client-side `jsdiff` diff time on very large manifests.**
  Mitigation: manifests are typically <50 KB; if the diff render
  becomes sluggish past a threshold, throw the diff into a Web
  Worker. Out of scope for v1; flagged as a known follow-on.
- **Trade-off — `status` resets to `draft` after publish (Decision 3).**
  Subtle but deliberate. Means the Application's persistent
  `status` no longer carries "is published right now" semantics —
  that's `currentVersion != null` instead. Documented in
  REQ-OBA-007 scenarios and the i18n help string.
- **Trade-off — no retention cap (Decision 4).** Storage grows
  unboundedly. Acceptable for v1 given expected blob sizes and
  publish cadences; opt-in retention is a future spec.

## Migration Plan

This is a strictly additive change layered on top of spec #1's
foundational schemas and runtime. Deployment steps:

1. Land the change on a feature branch from `development`.
2. CI runs PHPUnit + Newman + Playwright. The integration test for
   the full publish→rollback→republish cycle is the canonical
   green-light signal.
3. Merge into `development`. The migration runs on next deploy via
   the existing repair step (which now picks up the
   `ApplicationVersion` schema declaration and the new lifecycle
   action / `currentVersion` field on Application).
4. **Rollback** — disable the OpenBuilt versioning surface by
   reverting this change's deltas. Existing `ApplicationVersion`
   rows are harmless (no other Conduction app reads from them) and
   can be removed via OR's admin UI if a clean uninstall is wanted.
5. **Data migration** — none. Existing Applications carry no
   `currentVersion` and no `ApplicationVersion` rows until they
   next publish. The version-history panel renders an empty state
   for never-published Applications; no backfill is performed.

## Seed Data

Per ADR-001, every schema-introducing change ships seed data. The
seeded `hello-world` Application from spec #1 SHALL be extended with
a single seeded `ApplicationVersion` row representing its current
manifest, so that the version-history panel is non-empty on a fresh
install and the diff view has something to render in the
walkthrough.

**Seeded `ApplicationVersion`** (in the `openbuilt/application-version`
namespace):

- `applicationUuid`: UUID of the seeded `hello-world` Application
- `version`: `1.0.0`
- `manifest`: a deep-copy of the seeded `hello-world` Application's
  manifest at install time (byte-equal)
- `publishedAt`: install timestamp
- `publishedBy`: `system` (or the repair-step actor)
- `notes`: `Seeded by OpenBuilt install — initial published version`

The repair step (`SeedHelloWorld.php` from spec #1) SHALL be
extended to also set the Application's `currentVersion` to the
seeded `ApplicationVersion`'s UUID, and to be idempotent on this
new step (re-running the repair on a seeded install SHALL NOT
create a duplicate row).

## Open Questions

- **OQ-1 — `on_transition.create_relation` (or sibling-object create)
  action support in OR's lifecycle engine.** Same question
  bootstrap-openbuilt's OQ-1 raised. If yes by apply time, ship
  declarative; if no, ship the listener and file the OR-side issue.
  *Provisional decision*: try declarative first, fall back to the
  listener per Decision 2.
- **OQ-2 — Rollback version-marker convention.** `+rollback`
  (semver build metadata) is technically valid SemVer 2 but some
  tooling treats build metadata as ignorable. Alternative:
  `-rollback-<6hex>` (pre-release identifier — strictly ordered
  below the base version). *Provisional decision*: use the
  pre-release form so that a rolled-back-then-republished version
  sorts correctly relative to the original. Settle during apply
  after a 10-minute look at NC's existing semver tooling.
- **OQ-3 — Notes field UX.** The `ApplicationVersion.notes` field
  exists but no UI in v1 lets the integrator fill it in. Should
  the Publish button open a notes prompt before completing the
  transition, or stay silent? *Provisional decision*: silent in
  v1 (Publish stays one-click). A future minor spec can add the
  notes prompt; the field already supports it on the data side.
- **OQ-4 — Diff component placement.** Mount `ManifestDiff.vue` as
  a sibling tab in `ApplicationEditor.vue`, or as a modal opened
  from the version-history rows, or both? *Provisional decision*:
  sibling tab (always reachable, no modal stacking), with a
  one-click "Compare with current draft" affordance on each
  history row that switches to the tab pre-loaded with that pair.
- **OQ-5 — Diff library exact choice.** `jsdiff` is the
  recommendation, but `diff` (npm), `fast-diff`, and the
  shared-deps story (memory rule about `@conduction/nextcloud-vue`)
  all deserve a 15-minute investigation during apply. *Provisional
  decision*: `jsdiff` unless `nextcloud-vue` already ships a diff
  utility we should reuse (the memory rule says it ships
  `apexcharts` but NOT `fortawesome` — a similar audit for diff
  utilities is needed).
