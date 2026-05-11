---
kind: mixed
depends_on: [bootstrap-openbuilt]
chain:
  - bootstrap-openbuilt
  - openbuilt-versioning   # THIS spec
---

## Why

Spec #1 (`bootstrap-openbuilt`) declared the `Application` lifecycle
(`draft → published → archived`) via `x-openregister-lifecycle`, but it
did **not** ship the snapshot mechanism that makes that lifecycle
useful in practice. A citizen developer who publishes a virtual app
today has no way to:

1. iterate safely on a published app (every save mutates the live
   manifest),
2. recover a known-good version when an edit goes wrong, or
3. inspect what changed between two points in time.

Without versioned snapshots and a draft-staging UX, the publish
button is effectively a one-way door — exactly the friction OpenBuilt
must remove for non-developers to trust it as their authoring surface.

This spec ships the missing pieces:

- a new declarative `ApplicationVersion` schema that snapshots an
  Application's manifest at each publish,
- a draft / published indicator and a "Publish" action in the
  OpenBuilt shell,
- a version-history panel and a "Roll back to version N" action,
- a side-by-side diff view between any two manifest snapshots
  (current draft vs published is the default pairing).

Per ADR-031 the snapshot data model and the snapshot-on-publish
action are declarative; the diff and version-history UI are
unavoidably code. The split is the same pattern bootstrap-openbuilt
applied and OQ-1 below tracks the same OR-engine-capability question
(`on_transition` action support) that OQ-1 in the bootstrap design
already filed against OpenRegister.

## What Changes

- **NEW** OpenRegister schema `openbuilt/application-version`
  declared in `lib/Settings/openbuilt_register.json` with properties:
  - `uuid` (string, UUID-format)
  - `applicationUuid` (string, UUID-format, required — points at the
    parent Application)
  - `version` (string, semver pattern, required — copied from the
    Application's `version` at snapshot time)
  - `manifest` (object, required — full manifest blob copy; see
    design.md Decision 1)
  - `publishedAt` (string, ISO 8601 timestamp, required)
  - `publishedBy` (string, NC user id, required)
  - `notes` (string, optional — free-text changelog entry)
- **NEW** declarative `x-openregister-lifecycle.on_transition` action
  on the `Application` schema's `draft → published` edge that creates
  an `ApplicationVersion` record snapshotting the current manifest. If
  OR's engine does not yet expose an action that can create a sibling
  object on a state transition, fall back to a single
  `ApplicationVersionSnapshotListener` PHP class subscribed to OR's
  `ObjectLifecycleTransitionedEvent` — ADR-031 §Exceptions(1), same
  OQ-1 pattern bootstrap-openbuilt already documented.
- **NEW** `currentVersion` field on the `Application` schema (string,
  UUID-format, optional) — points at the most recent
  `ApplicationVersion` row, kept in sync by the same lifecycle action
  / listener.
- **NEW** Draft-vs-published indicator in the OpenBuilt shell's
  Application list and `ApplicationEditor.vue` header.
- **NEW** "Publish" action button in `ApplicationEditor.vue` that
  triggers the Application's `draft → published` lifecycle transition
  via OR REST. After publishing the editor returns to the draft
  manifest (Application status flips back to `draft` for the next
  edit cycle — see design.md Decision 3).
- **NEW** `VersionHistory.vue` panel listing all
  `ApplicationVersion` rows for the current Application (newest
  first) with `version`, `publishedAt`, `publishedBy`, and `notes`
  columns.
- **NEW** "Roll back to this version" action on each row in the
  version-history panel. Rollback copies the chosen
  `ApplicationVersion`'s `manifest` blob back onto the Application's
  draft manifest and creates a new `ApplicationVersion` snapshot
  pointing at the restored state (audit-clean history per
  design.md Decision 3 — never destructive).
- **NEW** `ManifestDiff.vue` component rendering a client-side
  side-by-side diff between two manifest blobs (defaults to current
  draft vs latest published). Diff implementation is client-side
  per design.md Decision 5.
- **NEW** `GET /api/applications/{slug}/versions/diff?from={uuidA}&to={uuidB}`
  endpoint returning both manifest blobs unwrapped, so the client
  diff component does not need two round-trips. Endpoint is thin
  glue (~15 LOC) on top of OR REST.

### Capabilities

#### New Capabilities

- `openbuilt-version-snapshots`: The OR-backed `ApplicationVersion`
  schema, the declarative snapshot-on-publish action (or its
  ADR-031-exception listener), and the version-history /
  rollback / diff UI. Owns the "I can see what changed and undo a
  bad publish" experience.

#### Modified Capabilities

- `openbuilt-application-register` — ADDED Requirements: the
  `Application` schema gains a `currentVersion` field and the
  `draft → published` transition gains a snapshot action.
- `openbuilt-runtime` — ADDED Requirements: the Application editor
  exposes a Publish action and a draft-vs-published badge; the
  shell mounts the new `VersionHistory.vue` panel and
  `ManifestDiff.vue` component.

## Impact

- **New code** — `lib/Settings/openbuilt_register.json` (schema
  patch — declarative), `src/views/VersionHistory.vue`,
  `src/components/ManifestDiff.vue`, modifications to
  `src/views/ApplicationEditor.vue`, a thin diff endpoint method on
  `ApplicationsController.php`, a route entry in `appinfo/routes.php`.
  If OR's `on_transition` action cannot create a sibling object,
  one additional file `lib/Listener/ApplicationVersionSnapshotListener.php`
  (ADR-031 exception, mirrors the BuiltAppRouteSyncListener
  pattern from bootstrap-openbuilt).
- **External dependency** — `jsdiff` (or equivalent client-side
  diff lib) added to `package.json` — chosen per design.md
  Decision 5. No server-side PHP diff lib introduced.
- **OpenRegister** — adds one schema (`application-version`) and
  one lifecycle action declaration to the existing OpenBuilt
  register namespace.
- **No breaking changes** — additive only. Existing Applications
  carry no `ApplicationVersion` rows until they next publish; the
  version-history panel renders an empty state until then. The
  new `currentVersion` field is optional on `Application`.
- **Foundational ADRs honoured** — ADR-022 (consume OR
  abstractions — `ApplicationVersion` lives in OR, the snapshot
  action is declarative), ADR-024 (manifest contract unchanged —
  snapshots store the same blob shape), ADR-031 (declarative-first
  — no `VersioningService` / `SnapshotService` class), ADR-032
  (kind: mixed with thin-glue exception — see `design.md`).
