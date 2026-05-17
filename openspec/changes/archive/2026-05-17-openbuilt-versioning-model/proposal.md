---
kind: code
depends_on: []
---

## Why

OpenBuilt today conflates "the app" and "the version" on a single `Application` row: the
manifest lives on Application, a writeback listener maintains a denormalised
`currentVersion` UUID, and there is no safe playground for admins to try changes without
exposing them to end users. ADR-002 resolves this by splitting the runtime model into
`Application` (logical) + `ApplicationVersion` (deployable runtime) joined by real OR
relations, with each version owning its own per-version register so production data is
structurally isolated from test data. This spec is the **foundation** of the chain — it
defines the schema, retires the writeback listener, and ships the green-field migration
step. Three sibling specs (`openbuilt-version-promotion`, `openbuilt-version-routing`,
`openbuilt-app-creation-wizard`) and the detail-page overview spec all build on this
model and cannot start until it lands.

## What Changes

- **BREAKING** Split the `Application` schema into two related OR objects:
  - `Application` keeps `slug`, `name`, `description`, `permissions`, plus a new
    `productionVersion` relation pointer to an `ApplicationVersion`. Loses `manifest`,
    `version`, `status`, and `currentVersion` (those move to or disappear with the new
    model).
  - **NEW** `ApplicationVersion` carries `name` (admin display string), `slug`
    (kebab-case), `manifest` (the JSON blob — moves here from Application), `register`
    (per-version OR register name, convention `openbuilt-{appSlug}-{versionSlug}`),
    `semver`, `status` (`draft | published | archived` — moved from Application;
    per-version lifecycle now), `application` (relation → Application), and `promotesTo`
    (optional relation → next ApplicationVersion in the linear chain).
- **BREAKING** Remove `Application.currentVersion`. Delete
  `lib/Listener/ApplicationVersionSnapshotListener.php` and its `register()` registration
  in `lib/AppInfo/Application.php`. Drop the `on_transition` snapshot-writeback action
  from the schema's `x-openregister-lifecycle` (the action that performed
  `self.currentVersion = @result.uuid`).
- **NEW** `ApplicationVersionService` — owns semver auto-bump on manifest hash-diff
  (initial `0.1.0`; patch-bump on manifest content change only; metadata-only edits do
  not bump); owns the cross-row `promotesTo` cycle-prevention guard; owns the
  version-deletion strategy logic (`delete-now | orphan-grace | keep-register`).
- **NEW** `ApplicationVersionsController` — CRUD endpoints over `ApplicationVersion` plus
  the delete-with-strategy endpoint (`DELETE …?strategy=delete-now|orphan-grace|
  keep-register`). Registered in `appinfo/routes.php`.
- **NEW** `MigrateToVersionedModel` repair step — destructive **green-field migration**:
  for every pre-migration `Application` row, drops its per-app register
  (`openbuilt-{slug}`) and deletes the Application row, logging one line per deleted app
  (`Migrated-to-versioned-model: dropped Application '<slug>' and register
  'openbuilt-<slug>'`). Idempotent: short-circuits when the schema is already in
  versioned shape (detected by absence of `currentVersion` on existing rows OR presence
  of the `applicationVersion` schema in the register). Safe to re-run on every install
  and upgrade. Registered in `appinfo/info.xml`.
- **MOVED** The existing `on_transition` lifecycle action that upserts the
  `BuiltAppRoute` index on `published` (from chain spec `openbuilt-application-register`)
  relocates from `Application.x-openregister-lifecycle` to
  `ApplicationVersion.x-openregister-lifecycle` — published-ness is now a per-version
  concept.
- **NEW** Cycle-prevention guard on `promotesTo`: an `ApplicationVersion` save that would
  introduce a cycle in the `promotesTo` chain is rejected with a 4xx response. Declarative
  validation (`x-openregister-validation`) is tried first; the cross-row traversal makes
  this an ADR-031 §Exceptions fallback to an imperative pre-save guard inside
  `ApplicationVersionService`.
- **NEW** `productionVersion` integrity guard on `Application` save: the relation MUST
  point at an `ApplicationVersion` whose `application` relation points back at this
  Application. A mismatched pointer is rejected with a 4xx response.
- **MAYBE-REMOVED** `lib/Repair/SeedHelloWorld.php` — verified for residual
  `currentVersion` writes; under the new model the hello-world seed becomes the
  responsibility of the creation wizard (`openbuilt-app-creation-wizard`) at install
  time, so this repair step is reduced to a no-op or removed entirely (see tasks.md task
  #11 for the decision).

## Capabilities

### New Capabilities

- `application-versions`: CRUD over the new `ApplicationVersion` OR object — semver
  auto-bump on manifest change, manifest-hash storage, cycle-prevention on `promotesTo`,
  version-deletion with three strategy options. Owns `ApplicationVersionService`,
  `ApplicationVersionsController`, and the `ApplicationVersion` schema declaration in
  `lib/Settings/openbuilt_register.json`.
- `green-field-migration`: One-shot destructive migration that wipes every pre-migration
  virtual app + its per-app register so the new versioned model starts from a clean
  slate. Owns `lib/Repair/MigrateToVersionedModel.php` and its registration in
  `appinfo/info.xml`. Safe-idempotent (short-circuits on versioned-shape schema).

### Modified Capabilities

- `openbuilt-application-register`: Schema patch — drop `manifest`, `version`, `status`,
  `currentVersion` from Application; add `productionVersion` relation; relocate the
  `BuiltAppRoute` upsert lifecycle action to ApplicationVersion. Add the
  `productionVersion` integrity guard.
- `openbuilt-version-snapshots`: Snapshot/writeback semantics are **retired**. Remove
  REQ-OBV-006 (currentVersion reference — the field disappears) and the listener fallback
  language from REQ-OBV-002 (the listener is deleted). The audit-history use case is now
  served by OR's object time-travel (per ADR-002 Decision §Audit trail), not by spawning
  ApplicationVersion rows on each publish. Rollback semantics (REQ-OBV-003) become OR
  time-travel on the ApplicationVersion row, not append-only snapshots — re-document
  accordingly.

## Impact

- **New PHP**:
  - `lib/Service/ApplicationVersionService.php` (semver auto-bump, manifest-hash diff,
    cycle guard, deletion-strategy logic)
  - `lib/Controller/ApplicationVersionsController.php` (CRUD + delete-with-strategy)
  - `lib/Repair/MigrateToVersionedModel.php` (destructive green-field migration)
- **Deleted PHP**:
  - `lib/Listener/ApplicationVersionSnapshotListener.php`
  - Possibly `lib/Repair/SeedHelloWorld.php` (decided in tasks.md task #11)
- **Modified PHP**:
  - `lib/AppInfo/Application.php` — remove the snapshot listener registration from
    `register()`
  - `appinfo/routes.php` — register the new endpoints
  - `appinfo/info.xml` — register `MigrateToVersionedModel` repair step
- **Modified JSON**:
  - `lib/Settings/openbuilt_register.json` — split `Application` schema, add new
    `ApplicationVersion` schema, drop `currentVersion`, drop the snapshot `on_transition`
    action, relocate the `BuiltAppRoute` upsert action
- **OpenRegister dependency** — uses the existing `^v0.2.10` floor declared in
  `lib/Settings/openbuilt_register.json` (relations, lifecycle, register-delete API).
- **Out of scope** (covered by sibling specs in the chain):
  - Promotion dialog + backend — `openbuilt-version-promotion`
  - `?version=` URL routing + admin gate — `openbuilt-version-routing`
  - Creation wizard provisioning Application + N ApplicationVersions + per-version
    registers + Hello World seed — `openbuilt-app-creation-wizard`
  - Detail-page version switcher UI — `openbuilt-app-detail-overview`
  - Distinct-actor audit-trail aggregation — a separate openregister-side change
- **No backward compatibility** for existing virtual apps — the green-field migration
  deletes them. ADR-002 records the explicit decision (existing installs hold only test
  data; the new wizard re-seeds Hello World at install time).
