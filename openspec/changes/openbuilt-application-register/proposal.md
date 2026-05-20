---
kind: mixed
depends_on: []
---

## Why

The `openbuilt-application-register` capability — the two foundational OpenRegister schemas
(`Application` and `BuiltAppRoute`) that underpin every virtual app in the OpenBuilt stack —
has been modified across four successive chain specs: `bootstrap-openbuilt` (spec #1)
introduced the original schemas and declarative lifecycle; `openbuilt-versioning` added a
`currentVersion` field and a snapshot-on-publish writeback action; `openbuilt-rbac` added the
`permissions` block and a permissions-population migration; and `openbuilt-versioning-model`
retired `currentVersion`, `manifest`, `version`, and `status` from `Application` in favour of
the two-object versioned model (ADR-002). All four specs are now archived.

This spec is the **authoritative, current-state specification** of the
`openbuilt-application-register` capability. It consolidates every prior modification into a
single coherent register declaration, resolves a requirement-numbering collision (two competing
`REQ-OBA-006` / `REQ-OBA-007` entries that accumulated in the context-brief — the
`currentVersion` and snapshot entries are formally retired per ADR-002), and ships the
permissions-population migration that the `openbuilt-rbac` chain spec defined but whose
implementation status requires verification. It also provides the seed data, declarative
decisions, and test coverage that this capability's spec has been missing since the chain began.

## What Changes

- **`Application` schema** (`lib/Settings/openbuilt_register.json`) reaches its
  ADR-002-aligned current shape: `uuid`, `slug` (kebab-case, required), `name`
  (required), `description` (optional), `permissions` (optional object with
  `owners`, `editors`, `viewers` arrays of Nextcloud group IDs), and `productionVersion`
  (optional relation → `ApplicationVersion`). The properties `manifest`, `version`,
  `status`, and `currentVersion` are **absent** — they moved to `ApplicationVersion` per
  ADR-002. The Application schema's `x-openregister-lifecycle` block carries **no**
  `states` or `transitions` (lifecycle is per-version now); it may retain cross-row
  integrity guards only.

- **`BuiltAppRoute` schema** (`lib/Settings/openbuilt_register.json`) retains its original
  shape: `slug` (kebab-case, required, unique per organisation) and `applicationUuid`
  (UUID-format, required). Its maintenance — the upsert on publish and removal on archive
  — is driven by `ApplicationVersion.x-openregister-lifecycle`, not `Application`'s, per
  the relocation confirmed in `openbuilt-versioning-model`.

- **`permissions` property** on the `Application` schema is formally declared with
  `additionalProperties: false` and three optional `string[]` arrays (`owners`, `editors`,
  `viewers`). Default on creation: caller's primary Nextcloud group → `owners`; `editors`
  and `viewers` empty. The declaration is schema-declarative per ADR-031 — no
  `AuthorizationService` or `PermissionsService` PHP class.

- **`PopulateApplicationPermissions` repair step**
  (`lib/Repair/PopulateApplicationPermissions.php`) — idempotent `post-migration` step
  that walks every `Application` object whose `permissions` is null or absent and patches
  `{ owners: ["admin"], editors: [], viewers: [] }`. Skips any row that already has a
  non-empty `permissions.owners`. Registered in `appinfo/info.xml`.

- **Retirement notices** — `REQ-OBA-006` (Application carries `currentVersion` reference)
  and `REQ-OBA-007` (draft-to-published snapshot action) are formally marked REMOVED in
  the spec file. The `currentVersion` field no longer appears in the schema; the
  `ApplicationVersionSnapshotListener` was deleted by `openbuilt-versioning-model`.

## Capabilities

### Modified Capabilities

- `openbuilt-application-register`: Application schema reaches its ADR-002-aligned shape
  with the `permissions` block present and `x-openregister-lifecycle` on Application
  carrying no state machine. `BuiltAppRoute` schema and maintenance action confirmed on
  `ApplicationVersion` lifecycle. `PopulateApplicationPermissions` repair step ships.
  Seed data (Dutch, 5 Application + 3 BuiltAppRoute objects) is declared and generated.
  No new service class; schema-declarative per ADR-031.

## Impact

- `lib/Settings/openbuilt_register.json` — `Application` schema: confirm `manifest`,
  `version`, `status`, `currentVersion` are absent; confirm `permissions` property is
  present with correct shape; confirm `productionVersion` relation is present; confirm
  `x-openregister-lifecycle` carries no `states`/`transitions`. `BuiltAppRoute` schema:
  confirm unchanged. Add 5 `Application` seed objects + 3 `BuiltAppRoute` seed objects
  in `components.objects[]` with Dutch realistic values.
- `lib/Repair/PopulateApplicationPermissions.php` — new repair step if not yet shipped by
  the `openbuilt-rbac` archived spec; verify and update if already present.
- `appinfo/info.xml` — confirm `PopulateApplicationPermissions` is registered as a
  `<post-migration>` step after `InitializeSettings`.
- No new controllers, services, or frontend components — schema + repair step only.
- **Foundational ADRs honoured** — ADR-001 (seed data per schema-introducing change),
  ADR-022 (consume OR abstractions; no app-local RBAC), ADR-031 (schema-declarative
  business logic; `permissions` is metadata, not a service), ADR-002 (versioned model;
  Application is the logical container, ApplicationVersion is the deployable runtime).
