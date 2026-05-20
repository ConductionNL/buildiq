## Context

The `openbuilt-application-register` capability has been the subject of four successive chain
specs since the first OpenBuilt bootstrap. Each spec modified the `Application` schema in
`lib/Settings/openbuilt_register.json`:

- `bootstrap-openbuilt` (archived 2026-05-12): introduced `Application`
  (`uuid`, `slug`, `name`, `description`, `manifest`, `version`, `status`) and
  `BuiltAppRoute` (`slug`, `applicationUuid`), plus a declarative
  `x-openregister-lifecycle` block (`draft → published → archived`).
- `openbuilt-versioning` (archived 2026-05-12): extended `Application` with
  `currentVersion` (UUID string pointing at the most recent `ApplicationVersion` snapshot
  row) and an `on_transition` snapshot action (or its `ApplicationVersionSnapshotListener`
  PHP fallback) that wrote back `currentVersion` on every publish.
- `openbuilt-rbac` (archived 2026-05-12): extended `Application` with the `permissions`
  block (`{ owners, editors, viewers }` arrays of Nextcloud group IDs) and defined
  `PopulateApplicationPermissions`, an idempotent migration repair step.
- `openbuilt-versioning-model` (archived 2026-05-17): applied ADR-002 — split `Application`
  into `Application` (logical) + `ApplicationVersion` (deployable); removed `manifest`,
  `version`, `status`, `currentVersion` from Application; added `productionVersion`
  relation; relocated the `x-openregister-lifecycle` state machine and the `BuiltAppRoute`
  upsert action to `ApplicationVersion`.

All four chain specs are archived. The current state is: `Application` carries `slug`,
`name`, `description`, `permissions`, and `productionVersion`; `BuiltAppRoute` carries
`slug` and `applicationUuid`; the lifecycle is on `ApplicationVersion`; the `BuiltAppRoute`
upsert fires from `ApplicationVersion`'s `draft → published` transition.

This spec is the consolidation point: it declares seed data, resolves the numbering collision
in the accumulated spec requirements, ships the `PopulateApplicationPermissions` repair step
(verifying it is present and correct), and provides the complete test and documentation
baseline for the capability.

## Goals / Non-Goals

**Goals**

- Declare the `Application` schema in its ADR-002-aligned shape in
  `lib/Settings/openbuilt_register.json`:
  `uuid`, `slug` (kebab-case, required), `name` (required), `description` (optional),
  `permissions` (optional object per REQ-OBA-006), `productionVersion` (optional relation
  → ApplicationVersion). No `manifest`, `version`, `status`, `currentVersion`.
- Confirm `x-openregister-lifecycle` on `Application` carries **no** `states` /
  `transitions` block (lifecycle is per-ApplicationVersion).
- Confirm `BuiltAppRoute` schema shape: `slug` (kebab-case, required, unique per org),
  `applicationUuid` (UUID-format, required).
- Confirm the `BuiltAppRoute` upsert action is on `ApplicationVersion`'s lifecycle, not
  `Application`'s.
- Ship `lib/Repair/PopulateApplicationPermissions.php` (idempotent migration that patches
  missing `permissions` blocks on existing Applications with
  `{ owners: ["admin"], editors: [], viewers: [] }`).
- Declare 5 seed `Application` objects + 3 seed `BuiltAppRoute` objects with realistic
  Dutch values in `components.objects[]` per ADR-001.
- Formally retire REQ-OBA-006 (currentVersion) and REQ-OBA-007 (snapshot action) in the
  spec file, with references to ADR-002.

**Non-Goals**

- Introducing new schemas (`ApplicationVersion` is owned by the `application-versions`
  capability; this spec only references it as a relation target).
- CRUD endpoints for Application objects — OR REST handles Create/Read/Update/Delete
  directly (ADR-022). No `ApplicationController::index/show/create/update/destroy` wrappers.
- Lifecycle enforcement service — `x-openregister-lifecycle` on `ApplicationVersion` is the
  engine; this spec only declares schema metadata on `Application`.
- Frontend components — the editor, list view, permissions panel, and `useRole` composable
  belong to `openbuilt-runtime` and `openbuilt-rbac`.
- Creation-wizard provisioning — owned by `openbuilt-app-creation-wizard`.
- Version-promotion, version-routing, detail-page overview — separate capability specs.
- The `ApplicationVersionSnapshotListener` — already deleted by `openbuilt-versioning-model`.

## Decisions

### Decision 1 — Application schema shape is ADR-002-aligned and declarative

The `Application` schema in `lib/Settings/openbuilt_register.json` contains exactly the
properties listed in Goals above. The schema change that removed `manifest` / `version` /
`status` / `currentVersion` was performed by `openbuilt-versioning-model`; this spec
confirms it and provides the complete authoritative snapshot.

Per ADR-031, the `permissions` block is declared as **schema metadata** — a JSON Schema
`object` property with `additionalProperties: false`. No `PermissionsService.php`,
`AuthorizationService.php`, or `RbacService.php` is written. The declaration is the
canonical example of "schema-declarative permissions metadata" for the OpenBuilt fleet.

**Alternatives considered**

- *Store `permissions` on a separate `ApplicationPermission` schema linked via OR
  relation.* Rejected. One extra schema per logical entity violates ADR-022's
  "consume OR abstractions" principle when an embedded `object` property suffices.
  `permissions` is metadata about the Application record, not a domain entity in its
  own right.
- *Express the role check as a standalone `x-openregister-authorization` declaration.*
  The preferred future path — if OR's authorization vocabulary supports a
  `groupIn: <json-pointer>` predicate, declare it on the schema (ADR-031 happy path).
  As of this spec's write date, that predicate's availability is an open question; the
  spec declares the `permissions` property shape only and delegates enforcement to the
  thin in-controller check in `openbuilt-runtime`'s `ApplicationsController::getManifest`.

### Decision 2 — Permissions-population migration is imperative (ADR-031 §Exceptions(1))

The `PopulateApplicationPermissions` repair step walks existing `Application` objects and
patches those without a `permissions` field. This is an imperative PHP class because:

1. OR's lifecycle/calculation extensions operate on object saves and reads — not on a
   batch "walk all objects and patch one field" operation.
2. The migration runs once per install, on upgrade, before the RBAC enforcement in
   `ApplicationsController::getManifest` is active. There is no declarative path for a
   "repair step" in OR's schema extension vocabulary.

**Implementation rule**: uses `ObjectService::saveObject($entityOrArray)` where the first
argument is the entity/array (not a type string). One OR REST round-trip per Application.
Idempotent: skips Applications whose `permissions.owners` is already non-empty.

**Alternatives considered**

- *Declare a schema `default` clause on `permissions` and rely on OR to backfill.*
  Rejected: OR's `default` clause applies at object-create time, not retroactively on
  existing rows. A repair step is required for the backfill.
- *Ship the migration as a Nextcloud migration SQL file.* Rejected: Application objects
  live in OpenRegister's object store (JSON), not in a typed SQL table with a
  `permissions` column. SQL migrations cannot safely patch JSON fields in a
  schema-agnostic OR storage layer.

### Decision 3 — BuiltAppRoute upsert lives on ApplicationVersion (confirmed relocation)

Per `openbuilt-versioning-model`, the `on_transition` action that upserts
`BuiltAppRoute(slug, applicationUuid)` when an app version goes `draft → published` resides
on **`ApplicationVersion.x-openregister-lifecycle`**, not on `Application`. This spec confirms
that relocation. Application's lifecycle block carries **no** `on_transition` upsert for
`BuiltAppRoute`.

The `applicationUuid` field on the route record continues to point at the **parent
`Application`** (resolved from the `ApplicationVersion.application` relation), not at the
`ApplicationVersion`. This is intentional: the routing spec (`openbuilt-version-routing`)
adds `?version=<slug>` disambiguation on top of the slug → Application UUID resolution.

### Decision 4 — Seed data strategy: `@self` envelope in `components.objects[]`

Per ADR-001 and the company-wide schema-standards ADR, every schema-introducing change
ships 3–5 realistic seed objects per entity. Objects use the `@self` envelope:

```jsonc
{
  "@self": { "register": "openbuilt", "schema": "application", "slug": "hello-world" },
  "slug": "hello-world",
  "name": "Hello World",
  ...
}
```

Seed `Application` objects DO NOT include `manifest`, `version`, `status`, or
`currentVersion` — those fields no longer exist on the schema. Seeds are loaded via
`ConfigurationService::importFromApp()` at install / repair time. Idempotency is guaranteed
by the `ObjectService::searchObjects` slug-match in the importer.

Five `Application` seed objects and three `BuiltAppRoute` seed objects are declared (see
Seed Data section below). The `hello-world` Application and its `BuiltAppRoute` are the
canonical testability seed; the four Dutch municipal applications demonstrate real-world
variety.

**Alternatives considered**

- *Ship seed data via `lib/Repair/SeedHelloWorld.php`.* That repair step was deleted by
  `openbuilt-versioning-model`. Seed data lives in `components.objects[]` inside
  `lib/Settings/openbuilt_register.json`, loaded by the standard `importFromApp()` pipeline.

### Decision 5 — No declarative-vs-imperative conflict for slug uniqueness

`BuiltAppRoute.slug` uniqueness within an organisation was originally an open question in
`bootstrap-openbuilt` (OQ-1). With `ApplicationVersion.x-openregister-lifecycle` owning the
BuiltAppRoute upsert, OR's idempotent upsert-by-slug semantics (matched by `slug` +
`organisation` composite) is the enforcing mechanism — a second Application attempting to
use the same slug in the same organisation will collide on the upsert and receive a 4xx
from OR without any custom PHP slug-check service.

This is the **declarative** path: slug uniqueness is enforced by the OR schema's index on
`(slug, organisation)` declared via `x-openregister-unique` on `BuiltAppRoute.slug`.

## Declarative-vs-Imperative Decision

Per ADR-031, every behaviour site is classified:

| Behaviour | Declarative attempt | Final decision | Rationale |
|---|---|---|---|
| `permissions` shape on Application | JSON Schema `object` property in register file | **Declarative** (`lib/Settings/openbuilt_register.json` schema) | Metadata on the container record; no service class. ADR-031 canonical example. |
| Read/write enforcement for `permissions` | `x-openregister-authorization` `groupIn-pointer` predicate | **Deferred to OR** — if available, declare; else thin in-controller check in `openbuilt-runtime` | This spec declares the data shape; `openbuilt-runtime` owns the enforcement. |
| Backfill `permissions` on existing Applications | Lifecycle/calculation engine | **Imperative** (`PopulateApplicationPermissions` repair step) | OR's schema extensions don't cover a "walk all and patch" batch-repair operation. ADR-031 §Exceptions(1). |
| Default `permissions` on new Application creation | Schema `default` clause | **Inline** (computed in the Application-create path using `IGroupManager`) | Schema `default` supports static literals; group-membership lookup requires PHP. Computed once at create time; no service class. |
| `BuiltAppRoute` upsert on publish | `x-openregister-lifecycle.on_transition` on ApplicationVersion | **Declarative** (on ApplicationVersion's schema) | Single-row state transition firing a sibling upsert by deterministic key. ADR-031 happy-path. Confirmed relocated from Application. |
| Slug uniqueness per organisation | `x-openregister-unique` on `BuiltAppRoute.slug` | **Declarative** (schema declaration) | OR's index enforces uniqueness at the persistence layer. |
| Application lifecycle (`draft → published → archived`) | `x-openregister-lifecycle.states` | **Declarative** — on `ApplicationVersion`, NOT on `Application` | Per ADR-002, lifecycle is per-version. Application has no `status` field; no state machine on Application. |

## Risks / Trade-offs

- **Risk** — *`PopulateApplicationPermissions` repair step runs on installs that already
  have `permissions` set (e.g. newly created Applications where the create-path already
  populated the block).* → Mitigation: the step is idempotent — it skips any Application
  whose `permissions.owners` is already non-empty. Re-running is safe.
- **Risk** — *Seed data objects conflict with existing data if `importFromApp()` is run
  against a non-empty install.* → Mitigation: the importer matches by slug with
  `ObjectService::searchObjects(_rbac: false, _multitenancy: false)`; existing objects with
  the same slug are not overwritten.
- **Risk** — *Schema declares `permissions` as optional, but some code paths assume it is
  always present after migration.* → Mitigation: REQ-OBA-007 covers existing Applications;
  new Applications get `permissions` at creation time via the default-on-create path. After
  migration, every Application has a populated `permissions` block. Code paths should still
  handle `null` defensively.
- **Trade-off** — *`permissions.owners = ["admin"]` as the migration default is broad.*
  This is intentional: a conservative default that keeps all pre-existing Applications
  accessible to admins after the migration. Operators who want narrower ownership use OR
  REST to update `permissions.owners` to the relevant team group.
- **Trade-off** — *The `Application` schema carries no lifecycle state machine.*
  All status-based queries (e.g. "list all published apps") must target
  `ApplicationVersion.status`, not `Application.status`. This requires a join or a
  relation hop. Per ADR-002, this is the correct shape; any code that expected
  `Application.status` must migrate to `ApplicationVersion.status`.

## Migration Plan

1. Confirm the `Application` schema in `lib/Settings/openbuilt_register.json` matches the
   ADR-002-aligned shape (Goals above). If `manifest` / `version` / `status` /
   `currentVersion` are still present, remove them (this was the `openbuilt-versioning-model`
   task; verify it landed).
2. Confirm `permissions` property is present and correctly shaped on `Application`.
3. Confirm `x-openregister-lifecycle` on `Application` has no `states`/`transitions`.
4. Confirm `BuiltAppRoute` upsert action is on `ApplicationVersion` lifecycle, not
   `Application` lifecycle.
5. Ship `lib/Repair/PopulateApplicationPermissions.php` (or confirm it already exists and
   matches REQ-OBA-007's contract). Register in `appinfo/info.xml` as a `<post-migration>`
   step.
6. Add seed objects to `lib/Settings/openbuilt_register.json` under `components.objects[]`.
7. Run `occ maintenance:repair` on a dev install. Confirm:
   - Schema re-imported cleanly.
   - Five Application seed objects exist.
   - Three BuiltAppRoute seed objects exist.
   - `PopulateApplicationPermissions` is a no-op on fresh seed (seeds already have
     `permissions` set).
8. On an install that has a pre-existing `hello-world` Application without `permissions`:
   confirm the migration step patches it to `{ owners: ["admin"], editors: [], viewers: [] }`.

**Rollback**: the `permissions` property is optional on the schema; removing the
`PopulateApplicationPermissions` step from `info.xml` and reverting the schema restore the
previous state. Pre-migrated Applications retain their `permissions` block, which is
harmless under pre-RBAC posture.

## Seed Data

Per ADR-001, every change that introduces or modifies schemas ships 3–5 realistic seed
objects per entity under `components.objects[]` in `lib/Settings/openbuilt_register.json`,
using the `@self` envelope.

### Application objects (5)

All seeds carry `permissions.owners = ["admin"]` as the conservative default for a fresh
install. Dutch municipality / organisation names, realistic slugs.

**1. hello-world**

```json
{
  "@self": { "register": "openbuilt", "schema": "application", "slug": "hello-world" },
  "slug": "hello-world",
  "name": "Hello World",
  "description": "Demo-applicatie voor het valideren van de OpenBuilt-architectuur.",
  "permissions": { "owners": ["admin"], "editors": [], "viewers": [] }
}
```

**2. vergunning-aanvraag**

```json
{
  "@self": { "register": "openbuilt", "schema": "application", "slug": "vergunning-aanvraag" },
  "slug": "vergunning-aanvraag",
  "name": "Vergunning Aanvraag",
  "description": "Aanvraagformulier voor omgevingsvergunningen van Gemeente Utrecht.",
  "permissions": { "owners": ["team-vergunningen"], "editors": [], "viewers": [] }
}
```

**3. meldingen-openbare-ruimte**

```json
{
  "@self": { "register": "openbuilt", "schema": "application", "slug": "meldingen-openbare-ruimte" },
  "slug": "meldingen-openbare-ruimte",
  "name": "Meldingen Openbare Ruimte",
  "description": "Portaal voor het indienen en afhandelen van meldingen in de openbare ruimte.",
  "permissions": { "owners": ["team-beheer"], "editors": ["team-buurtbeheer"], "viewers": [] }
}
```

**4. wob-verzoek**

```json
{
  "@self": { "register": "openbuilt", "schema": "application", "slug": "wob-verzoek" },
  "slug": "wob-verzoek",
  "name": "Wob-verzoek Afhandeling",
  "description": "Afhandeling van Wet open overheid-verzoeken voor Gemeente Amsterdam.",
  "permissions": { "owners": ["juridische-zaken"], "editors": [], "viewers": ["team-communicatie"] }
}
```

**5. subsidie-aanvraag**

```json
{
  "@self": { "register": "openbuilt", "schema": "application", "slug": "subsidie-aanvraag" },
  "slug": "subsidie-aanvraag",
  "name": "Subsidie Aanvraag",
  "description": "Online aanvraagformulier voor gemeentelijke subsidies.",
  "permissions": { "owners": ["team-financien"], "editors": ["team-subsidies"], "viewers": [] }
}
```

### BuiltAppRoute objects (3)

Only published Applications have a `BuiltAppRoute`. Three seeds correspond to three of the
Application seeds above (slug and applicationUuid). The UUIDs below are the seed slugs
used as stable identifiers; the `importFromApp()` pipeline resolves them to actual OR UUIDs.

**1. hello-world route**

```json
{
  "@self": { "register": "openbuilt", "schema": "builtapproute", "slug": "route-hello-world" },
  "slug": "hello-world",
  "applicationUuid": "@ref:application:hello-world"
}
```

**2. vergunning-aanvraag route**

```json
{
  "@self": { "register": "openbuilt", "schema": "builtapproute", "slug": "route-vergunning-aanvraag" },
  "slug": "vergunning-aanvraag",
  "applicationUuid": "@ref:application:vergunning-aanvraag"
}
```

**3. meldingen-openbare-ruimte route**

```json
{
  "@self": { "register": "openbuilt", "schema": "builtapproute", "slug": "route-meldingen-openbare-ruimte" },
  "slug": "meldingen-openbare-ruimte",
  "applicationUuid": "@ref:application:meldingen-openbare-ruimte"
}
```

Note: `@ref:application:<slug>` is a spec-level shorthand indicating the importer should
resolve the reference to the UUID of the Application with that slug. If the `importFromApp()`
pipeline does not yet support cross-object `@ref` references, the implementer SHALL generate
concrete UUIDs (e.g. `550e8400-e29b-41d4-a716-446655440001`) that match the UUIDs in the
Application seed objects above, maintaining consistency.

## Reuse Analysis

Per the company-wide schema-standards ADR, every spec includes a reuse analysis.

- **`ObjectService`** (OR) — used by `PopulateApplicationPermissions` to `findAll` existing
  Application objects and `saveObject` the patched versions. No custom entity or mapper.
- **`ConfigurationService::importFromApp()`** (OR) — used by the repair step to re-import
  the schema from `lib/Settings/openbuilt_register.json` and load seed data. Existing
  mechanism, no duplication.
- **`x-openregister-lifecycle`** (OR) — the lifecycle state machine on `ApplicationVersion`
  drives the `BuiltAppRoute` upsert. No custom state machine service.
- **`x-openregister-unique`** (OR) — BuiltAppRoute slug uniqueness is declared via schema,
  not a custom uniqueness-check service.
- **No overlap** with existing OpenRegister services: `AuthorizationService`,
  `AuditTrailService`, `RelationService` are inherited via OR for free; no parallel
  implementations.

## Open Questions

- **OQ-1 — `x-openregister-authorization` `groupIn-pointer` support.** Does OR's current
  authorization vocabulary support `{ groupIn: "permissions.owners" }` predicates on the
  Application schema? If yes, declare the read rule and the in-controller check becomes
  defence-in-depth only. *Provisional decision*: declare the data shape in this spec;
  `openbuilt-runtime` owns the enforcement check regardless. File an OR-side issue if the
  predicate is missing.
- **OQ-2 — `@ref` cross-object resolution in `importFromApp()`.** Does the current
  `importFromApp()` / `ImportHandler` pipeline resolve `@ref:schema:slug` pointers in seed
  object properties? If not, the implementer SHALL use static UUIDs (namespaced v4) in the
  seed objects and maintain the slug→UUID mapping in a comment block in the register file.
  *Provisional decision*: use static UUIDs; the `@ref` notation is aspirational and
  documents the intended relationship.
- **OQ-3 — `PopulateApplicationPermissions` status.** Did the `openbuilt-rbac` archived
  spec actually ship this repair step in the implementation? Verify in
  `lib/Repair/PopulateApplicationPermissions.php` during apply. If already present and
  correct, tasks 2.1–2.3 are verification-only; if absent, implement per REQ-OBA-007.
