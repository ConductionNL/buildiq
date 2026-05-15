## Context

OpenBuilt's current model (chain spec `openbuilt-application-register` + `openbuilt-version-snapshots`)
stores manifest, version, status, and `currentVersion` on a single `Application` OR row, and
relies on a PHP listener (`ApplicationVersionSnapshotListener`) to writeback `currentVersion`
after each publish. ADR-002 retires that model in favour of two related objects: `Application`
(logical, slug/name/permissions/`productionVersion`) and `ApplicationVersion` (deployable,
manifest/register/semver/status/`promotesTo`). This change is the foundation — schema split,
listener removal, green-field migration. Promotion, routing, creation-wizard and detail-page
specs depend on it.

## Goals / Non-Goals

**Goals:**

- Land the two-object schema in `lib/Settings/openbuilt_register.json` exactly as ADR-002
  specifies (no field renames, no shape divergence).
- Retire the snapshot writeback listener and the `currentVersion` field — one less
  denormalised cache, one less imperative event handler.
- Ship a destructive, idempotent green-field migration that wipes pre-versioned data and
  lets the creation wizard re-seed via the new model. Existing installs hold only test
  data; ADR-002 accepts the data loss.
- Auto-bump `ApplicationVersion.semver` on **manifest content changes only**, via a stable
  SHA-256 hash diff. Metadata-only edits (name, description, register, permissions) do not
  bump.
- Prevent cycles on the `promotesTo` linear chain.
- Provide a backend deletion endpoint accepting a `strategy` query param
  (`delete-now | orphan-grace | keep-register`) — the UI dialog is delivered by a sibling
  spec.

**Non-Goals (covered by sibling specs):**

- Promotion flow (data-copy / migrate / empty-start) — `openbuilt-version-promotion`
- `?version=<slug>` URL routing and admin-only gate — `openbuilt-version-routing`
- App-creation wizard (provisioning Application + N ApplicationVersions + N registers +
  Hello World seed at install time) — `openbuilt-app-creation-wizard`
- Detail-page version switcher UI — `openbuilt-app-detail-overview`
- Distinct-actor audit-trail aggregation — separate openregister-side change
- DAG / branching `promotesTo` arrays — ADR-002 roadmap item, not in v1
- CI/CD auto-promotion (cron, event triggers) — ADR-002 roadmap item, not in v1

## Decisions

### Decision 1 — Two-object schema split (Application + ApplicationVersion)

Implemented as two top-level entries under `components.schemas` in
`lib/Settings/openbuilt_register.json`:

- `Application` (modified): `slug`, `name`, `description`, `permissions`,
  `productionVersion` (relation → ApplicationVersion). Removed: `manifest`, `version`,
  `status`, `currentVersion`.
- `ApplicationVersion` (new): `name`, `slug`, `manifest`, `register` (string —
  per-version OR register name; convention `openbuilt-{appSlug}-{versionSlug}`), `semver`,
  `status` (`draft | published | archived`), `application` (relation → Application),
  `promotesTo` (optional relation → ApplicationVersion).

Relations are real first-class OR relations (per ADR-002 §Decision), not raw UUID strings.

**Alternatives considered:** Keep manifest on Application and add a sibling `versions[]`
array — rejected because ADR-002 already settled on full object separation for data
isolation (per-version registers) and admin-defined chain. Single register with a
`version` tag — rejected in ADR-002.

### Decision 2 — Retire `currentVersion` + delete the writeback listener

`Application.currentVersion` is removed from the schema. The
`lib/Listener/ApplicationVersionSnapshotListener.php` file is deleted, along with its
`register()` call in `lib/AppInfo/Application.php`. The `on_transition` action in
`x-openregister-lifecycle` that performs `self.currentVersion = @result.uuid` is removed
from the schema.

"Which version is live" is now answered by `Application.productionVersion` (an explicit
relation set by the admin), not by a denormalised cache updated by a listener. Per
ADR-002, this is the canonical fix.

### Decision 3 — Semver auto-bump via manifest hash-diff (imperative, ADR-031 §Exceptions)

Initial semver for a freshly-created ApplicationVersion is `0.1.0` (plain semver, no
prerelease tag). On every save, `ApplicationVersionService` canonicalises the
`manifest` object (stable JSON encode with sorted keys), SHA-256-hashes it, and compares
to the stored hash. If different, patch component is incremented and the new hash is
persisted. The hash lives in a private mapper-internal field `manifestHash` — not exposed
in the public schema — because it is implementation detail of the diff, not a
user-visible derived value.

**Why imperative (ADR-031 §Exceptions(2) — stateful diff outside calc vocab):** OR's
calculation vocabulary handles deterministic field-derivation (e.g. `sum`, `concat`,
`format`), not stateful diffing that needs the previous-saved hash. Trying to express
"compare canonicalised new state to last-stored hash, then conditionally increment a
semver component" in declarative metadata would require a new calc primitive in OR. Doing
this in `ApplicationVersionService::onSave()` is the right escape hatch.

**Alternatives considered:** Always bump on save (rejected — noisy; metadata edits trigger
phantom version bumps). Hash the entire row (rejected — metadata edits would bump).
Bump on lifecycle transition only (rejected — drafts get no semver history; admins lose
the patch trail of in-flight changes).

### Decision 4 — Manifest hash storage (imperative private field)

`manifestHash` is stored on the ApplicationVersion row in a mapper-internal column (or as
a `_self`-namespaced metadata key, depending on what the OR floor exposes). It is **not**
declared in the public schema; clients never read or write it directly. The service
maintains it on the save path.

**Why imperative:** the hash is invisible bookkeeping for the bump logic, not a derived
value end-users would consume. Promoting it to a public schema field would invite
clients to depend on it.

### Decision 5 — `promotesTo` cycle prevention (imperative, ADR-031 §Exceptions(1) cross-row)

`promotesTo` is a single optional relation forming a linear chain. Setting
`A.promotesTo = B` when `B`'s transitive `promotesTo` chain reaches back to `A` must be
rejected.

**Why imperative:** `x-openregister-validation` on the single-row save context cannot
traverse other ApplicationVersion rows. Cycle detection requires walking the chain
forward from the proposed target. This is a textbook ADR-031 §Exceptions(1)
cross-row-validation case. Implementation: `ApplicationVersionService::guardNoCycle()` is
invoked from the controller's pre-save path; walks `promotesTo` forward from the new
target, fails fast if the current row's UUID is encountered, hard cap of 100 hops to
prevent runaway traversal on data corruption.

**Declarative attempt first (for the record):** the implementer SHOULD try
`x-openregister-validation` with a same-row check (`promotesTo !== self.uuid` — catches
self-loops); the imperative cross-row guard is the broader safety net.

**Alternatives considered:** Trust admins to not create cycles (rejected — silent infinite
loops on chain walks would brick promotion UX). Encode a `chainDepth` field
(rejected — second denormalised cache, exactly the anti-pattern ADR-002 removes).

### Decision 6 — `BuiltAppRoute` upsert relocates to ApplicationVersion (declarative)

The existing `on_transition` action that upserts `BuiltAppRoute(slug, applicationUuid)`
when an Application goes `draft → published` (from chain spec
`openbuilt-application-register` REQ-OBA-004) moves from `Application`'s lifecycle to
`ApplicationVersion`'s lifecycle. Published-ness is per-version now. The route record's
`applicationUuid` continues to point at the parent Application (the routing spec layers
`?version=<slug>` on top of the route resolution).

**Why declarative:** same shape as before — a single-row state transition firing an upsert
of a sibling record by deterministic key. Pure ADR-031 happy-path. Just relocated.

### Decision 7 — `productionVersion` integrity guard (imperative, ADR-031 §Exceptions(1) cross-row)

`Application.productionVersion` MUST point at an ApplicationVersion whose `application`
relation points back at this Application. Implementation: `ApplicationVersionService::
guardProductionVersionOwnership()` (or a sibling method on an `ApplicationService` if it
exists; this spec adds one only if needed). Invoked from the Application pre-save path.
Rejects the save with 422 on mismatch.

**Why imperative:** same cross-row constraint as cycle detection. OR's per-row validation
can't reach the target ApplicationVersion to verify back-reference.

### Decision 8 — Green-field migration via `MigrateToVersionedModel` repair step

`lib/Repair/MigrateToVersionedModel.php` runs as a Nextcloud `Repair\IRepairStep` on
every app install / upgrade. Logic:

1. Detect versioned shape — short-circuit `return` if the `applicationVersion` schema
   already exists in the `openbuilt` register OR if no pre-migration `Application` row
   has a `currentVersion` field. Idempotency requirement: re-running on a clean install
   is a no-op.
2. Enumerate every `Application` row in the `openbuilt` register via
   `ObjectService::findAll('openbuilt/application')`.
3. For each row, derive the per-app register name (current convention is
   `openbuilt-{slug}`).
4. Call OR's register-delete API to drop the per-app register entirely (this also drops
   every object inside it).
5. Delete the Application row itself.
6. Log one line per deletion via the `$output->info()` channel:
   `Migrated-to-versioned-model: dropped Application '<slug>' and register
   'openbuilt-<slug>'`.

The step is registered in `appinfo/info.xml` under `<repair-steps><post-migration>`.
ADR-002 accepts the data loss (existing installs hold only test data; the new wizard
re-seeds Hello World).

**Alternatives considered:** Migrate-in-place (copy the manifest to a fresh
ApplicationVersion(name="production", slug="production"), rename the register from
`openbuilt-{slug}` to `openbuilt-{slug}-production`, set `Application.productionVersion`)
— **rejected** per the locked decision: ADR-002 explicitly chose green-field because the
implementation cost and edge-case surface (register-rename idempotency, partial-failure
recovery, missing `BuiltAppRoute` patches, schema-drift across versions) is not worth
preserving test data. The greenfield migration is two safe DB operations per row.

### Decision 9 — Version-deletion endpoint with strategy param

`DELETE /apps/openbuilt/api/applications/{slug}/versions/{versionSlug}?strategy=…`
accepts one of three strategy values:

- `delete-now` — drop the per-version register (and all rows inside it) immediately, then
  delete the `ApplicationVersion` row.
- `orphan-grace` — mark the per-version register as orphaned (write a metadata flag on
  the register row, e.g. `_self.orphanedAt = <timestamp>` — exact mechanism depends on
  what OR exposes), delete the `ApplicationVersion` row, leave the register data intact.
  A background job (not in this spec) prunes registers orphaned for more than 30 days.
- `keep-register` — delete the `ApplicationVersion` row only; leave the register
  untouched for manual recovery.

The endpoint refuses to delete the version pointed at by `Application.productionVersion`
(422). The strategy-execution branching lives in
`ApplicationVersionService::deleteVersion($versionUuid, $strategy)`; the controller is a
thin pass-through.

**Why imperative:** three branching side-effect chains (cascade-delete vs flag vs
no-op-data) with cross-row reads. Out of scope for declarative lifecycle metadata.

**The dialog UI itself is out of scope** — owned by the version-promotion or
creation-wizard sibling spec. The backend ships the contract; the UI lands separately.

### Seed Data section

Per ADR-001 (org-wide), every register-shipping change documents what seed data the
repair step writes.

**This spec writes no seed data.** The previous seed (`lib/Repair/SeedHelloWorld.php`,
which created the canonical `hello-world` virtual app) is wiped by the green-field
migration along with every other pre-migration Application row. The new wizard
(`openbuilt-app-creation-wizard`) owns Hello World seeding under the new model —
specifically, it provisions an `Application(slug=hello-world)` + one or more
`ApplicationVersion` rows + their per-version registers at install time, then runs the
hello-world manifest into the production version's register.

This spec's `MigrateToVersionedModel` repair step is destructive only; the
`SeedHelloWorld` repair step is either reduced to a no-op or deleted entirely (decided
in tasks.md task #11, recommendation: **delete the file** — the wizard takes over).

### Declarative-vs-imperative decision section

Per ADR-031, every business-logic site is classified.

| Concern | Declarative attempt | Final decision | Rationale |
| --- | --- | --- | --- |
| Maintain ApplicationVersion semver on manifest changes | Calc field on `semver` | **Imperative** (`ApplicationVersionService::onSave()`) | ADR-031 §Exceptions(2): stateful diff (current vs last-stored hash) is outside OR's calc vocabulary, which handles deterministic per-row derivation only. |
| Manifest hash storage | Calc field on `manifestHash` | **Imperative** (mapper-internal field) | Hash is invisible bookkeeping for the bump logic, not a user-visible derived value. Not part of the public schema. |
| Prevent cycles in the `promotesTo` chain | `x-openregister-validation` self-check | **Imperative** (`ApplicationVersionService::guardNoCycle()`) | ADR-031 §Exceptions(1): cross-row traversal required (walk forward through `promotesTo` chain). OR's row-scoped validation cannot reach other rows. Implementer SHOULD still ship the same-row self-loop validation declaratively as a cheap first filter. |
| `productionVersion` back-reference integrity | `x-openregister-validation` | **Imperative** (`ApplicationVersionService::guardProductionVersionOwnership()`) | ADR-031 §Exceptions(1): same cross-row constraint — must read the target ApplicationVersion row to verify it points back. |
| Lifecycle: `on_transition` upserts `BuiltAppRoute` on publish | `x-openregister-lifecycle.on_transition` | **Declarative** (relocated from Application to ApplicationVersion) | Identical shape to existing happy-path declarative action; just lives on the new schema. |
| Version-deletion strategy branching | Single declarative `on_delete` action | **Imperative** (`ApplicationVersionService::deleteVersion($strategy)`) | Three branching effect chains conditional on a query param — outside the `on_delete` vocabulary, which assumes a single deterministic effect. |

## Risks / Trade-offs

- **Risk: Green-field migration runs on a non-test install and destroys real data.** →
  Mitigation: ADR-002 records the decision that current installs hold only test data; we
  document the destructive behaviour at the top of `MigrateToVersionedModel.php`'s
  docblock; the repair step is idempotent so re-runs on already-migrated installs are no-ops
  (the short-circuit guard fires); we ship a single log line per deletion so the migration
  is observable in `occ` upgrade output. **If a deployment is found that has real data
  before this change ships**, the deploy team must export it before applying — this is
  flagged in the release notes for this change.
- **Risk: Cycle-prevention guard misses an edge case (e.g. concurrent writes creating a
  cycle).** → Mitigation: hard cap of 100 hops on the chain walk to prevent runaway
  traversal; service-level guard runs on every save; if a cycle slips through during a
  race window, chain-walking consumers (promotion UI) cap their own traversal to the same
  100 hops and surface a "chain corrupted" error rather than infinite-loop.
- **Risk: Manifest canonicalisation differs between PHP and JS clients, causing
  spurious hash mismatches and version bumps.** → Mitigation: canonicalisation lives
  entirely server-side — JS clients send raw JSON, server canonicalises with sorted keys
  and recursive normalisation before hashing. Server is the only producer of
  `manifestHash`.
- **Risk: Deleting `lib/Repair/SeedHelloWorld.php` removes a seed before the wizard
  spec lands.** → Mitigation: this spec lands inside the same chain delivery wave as the
  wizard spec; the chain is pre-launch (the green-field migration's existence proves
  there's no production traffic to lose). If the wizard ships later, the `MigrateToVersionedModel`
  repair step still leaves a clean shell — the admin can manually create the first app
  via the existing detail-page once the wizard arrives.
- **Trade-off: `productionVersion` is set explicitly, not derived from chain terminality.**
  → Per ADR-002 §Decision, this is intentional: chain-terminality is mutable (admins can
  insert versions later), but production-ness must survive chain reshaping. The integrity
  guard catches divergence.
- **Trade-off: No automatic backfill of an Application + ApplicationVersion pair from
  the old model.** → Per ADR-002, we accept the data loss; the migration is destructive
  by design.

## Migration Plan

1. Apply `proposal.md` schema deltas to `lib/Settings/openbuilt_register.json` (split
   Application, add ApplicationVersion).
2. Add `lib/Repair/MigrateToVersionedModel.php`. Register in `appinfo/info.xml` as a
   post-migration step. Step is **destructive** but idempotent (short-circuits when
   versioned schema is present).
3. Delete `lib/Listener/ApplicationVersionSnapshotListener.php` and its `register()` call
   in `lib/AppInfo/Application.php`.
4. Add `lib/Service/ApplicationVersionService.php` and
   `lib/Controller/ApplicationVersionsController.php`. Register endpoints in
   `appinfo/routes.php`.
5. Delete `lib/Repair/SeedHelloWorld.php` (default decision per task #11). Adjust its
   `<repair-steps>` registration in `appinfo/info.xml`.
6. Run repair on a dev install. Confirm the log line `Migrated-to-versioned-model:
   dropped Application '<slug>' and register 'openbuilt-<slug>'` fires for every test
   row. Confirm the second run is a no-op (short-circuit).

**Rollback:** the green-field migration is destructive and one-way. Rollback means
reverting the schema patch and re-importing the previous schema; data already wiped by
the migration is **not** recoverable. ADR-002 records that we accept this; the chain
delivery wave is pre-launch.

## Open Questions

None — the locked decisions in the prompt cover every architectural axis. Implementation
details (exact OR API for register-delete, exact mechanism for the orphan-mark flag)
will surface during apply and are tracked in tasks.md. If OR's register-delete API or
relation-validation surface area shifts before this change merges, the relevant tasks
note the contingency.
