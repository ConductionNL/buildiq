# unified-app-model Specification

## Purpose

Unifies OpenBuild's two app vocabularies — *virtual* apps (built from scratch by
OpenBuild) and *hybrid* apps (a delta layered over an already-installed Nextcloud
fleet app) — into ONE "Apps" concept distinguished by an `appType` discriminator
(`virtual` | `hybrid`) on the `Application` schema. A hybrid app is just an
`Application` (`appType: hybrid`, `baseRef.id` = the installed app id) plus a
delta-only `ApplicationVersion`, folding the former standalone `AppOverride` entity
into the existing `baseRef` + `manifestDelta` model. Carries the metadata-lock
invariant (a hybrid app's id/slug/name are read-only, mirroring the underlying NC
app; everything else stays editable) and the idempotent `AppOverride` → hybrid
`Application` migration.

**OpenSpec changes**: [unify-apps-with-app-type](../../changes/archive/2026-06-20-unify-apps-with-app-type/) _(archived 2026-06-20)_

**Status**: done

## Requirements

### Requirement: Application carries an appType discriminator

The `Application` schema SHALL declare an `appType` property — an enum with values `virtual` and `hybrid`, defaulting to `virtual`. A `virtual` app is fully delivered by OpenBuild (its `ApplicationVersion` manifest is built from scratch or from a template). A `hybrid` app customizes an already-installed Nextcloud fleet app — its `ApplicationVersion` carries a delta-only manifest layered over that fleet app's bundled manifest. The system SHALL treat an `Application` with no `appType` field as `virtual` (legacy default applied on read).

#### Scenario: Legacy Application reads as virtual

- **WHEN** an `Application` record that predates this change (no `appType` field) is read
- **THEN** the system SHALL treat it as `appType: "virtual"`
- **AND** all existing virtual-app behaviour SHALL be unchanged

#### Scenario: Hybrid Application is rejected without a baseRef

- **WHEN** an `Application` is saved with `appType: "hybrid"` and no `baseRef.id`
- **THEN** the system SHALL reject the save with a 4xx error identifying the missing `baseRef`

### Requirement: Application carries a baseRef linking a hybrid app to its fleet base

The `Application` schema SHALL declare an optional `baseRef` property of shape `{ "kind": <string>, "id": <string>, "manifestVersion"?: <string> }`. For a `hybrid` app, `baseRef.kind` SHALL be `"fleet-app"` and `baseRef.id` SHALL be the kebab-case Nextcloud app id of the installed app being customized (e.g. `opencatalogi`, `pipelinq`). For a `virtual` app, `baseRef` MAY be absent. The `baseRef.id` SHALL be the canonical link used to resolve the hybrid app from its fleet appId for the compatibility shim and the all/virtual/hybrid filter.

#### Scenario: Hybrid app links to an installed fleet app

- **WHEN** a hybrid `Application` is created for the installed app `opencatalogi`
- **THEN** the stored record SHALL carry `appType: "hybrid"` and `baseRef: { "kind": "fleet-app", "id": "opencatalogi" }`

### Requirement: A hybrid app is an Application plus a delta-only ApplicationVersion

The system SHALL represent a hybrid app as one `Application` (`appType: "hybrid"`) plus one `ApplicationVersion` whose `baseRef.kind` is `"fleet-app"` and whose `manifestDelta` carries the keyed structural delta (consumable by `mergeManifestDelta`), with no full `manifest` blob. The Application's `productionVersion` SHALL point at that version. The system SHALL NOT require a new entity type for hybrid apps — they reuse the existing `Application` + `ApplicationVersion` shapes from ADR-002 and the `baseRef` + `manifestDelta` storage from the `app-delta-override` model.

#### Scenario: Hybrid app stores a delta-only version

- **WHEN** a hybrid app is created for `opencatalogi` with a delta that hides one widget
- **THEN** the system SHALL create an `Application(appType:hybrid)` and an `ApplicationVersion` carrying that `manifestDelta` and `baseRef.kind: "fleet-app"`
- **AND** the version SHALL NOT carry a full `manifest` blob
- **AND** the Application's `productionVersion` SHALL reference that version

### Requirement: Hybrid app identity metadata is read-only (metadata-lock)

For an `Application` with `appType: "hybrid"`, the system SHALL treat the identity metadata — `slug`, `name`, `appType`, and the `baseRef` linkage — as read-only after creation, because a hybrid app's identity mirrors the underlying installed Nextcloud app. The backend SHALL REJECT any update that changes `slug` or `name` on a hybrid `Application` with a 4xx error. All other content (pages, widgets, menus, schemas-as-delta on the version) SHALL remain editable. A `virtual` app SHALL retain full edit of `slug` and `name`.

The lock SHALL be enforced as a pre-save guard on OR's object-update event that compares the proposed payload against the stored row and scopes to hybrid apps via the `appType` discriminator (the discriminator is the reliable selector — OR's object event exposes the schema as a numeric id, not the `application` slug, so a slug match is not dependable). When the guard rejects the change it SHALL stop the save and surface a 4xx error with code `openbuild.hybrid_metadata.locked`.

#### Scenario: Renaming a hybrid app slug is rejected

- **WHEN** an update changes the `slug` or `name` of an `Application` whose `appType` is `hybrid`
- **THEN** the backend SHALL reject the update with a 4xx error and SHALL NOT persist the change

#### Scenario: Editing hybrid app content is allowed

- **WHEN** an update changes only the production version's `manifestDelta` (pages/widgets/menus) of a hybrid app, leaving `slug`/`name` unchanged
- **THEN** the backend SHALL accept and persist the change

#### Scenario: Virtual app metadata stays editable

- **WHEN** an update changes the `slug` or `name` of an `Application` whose `appType` is `virtual`
- **THEN** the backend SHALL accept the change

### Requirement: Existing AppOverride records migrate to hybrid Applications idempotently

The system SHALL provide a migration (a repair step or migration class) that converts each existing `AppOverride` record into one `Application(appType:hybrid)` plus one delta-only `ApplicationVersion`, carrying over the override's `manifestDelta` and setting `baseRef.id` to the override's `appId`. The migration SHALL be idempotent: re-running it SHALL NOT create a second hybrid Application for an `appId` that already has one — it SHALL find the existing hybrid Application (by `appType == hybrid` AND `baseRef.id == appId`) and update its version's delta in place instead. Once a record's copy is created and verified, the migration SHALL DELETE the source `AppOverride` row (clean break); a re-run SHALL tolerate an already-deleted row.

The migration runs as the Nextcloud Anonymous system user (repair-step context) and therefore SHALL perform its Application + ApplicationVersion writes in system context (OR RBAC + multitenancy bypassed), because the `Application` schema's `create:[admin]` guard cannot be satisfied by the Anonymous user.

The `AppOverride` schema SHALL be removed from the register after the migration has run — but ONLY when EVERY row migrated successfully. Dropping the schema cascade-deletes any rows still under it, so a partial failure SHALL retain the schema (and its un-migrated rows) for the next run to retry; the schema SHALL NOT be dropped while any override remains un-migrated.

#### Scenario: First migration run creates a hybrid Application per override

- **WHEN** the migration runs and an `AppOverride` exists for `appId` `pipelinq` with no corresponding hybrid Application
- **THEN** the migration SHALL create one `Application(appType:hybrid, slug:"pipelinq", baseRef.id:"pipelinq")` and one delta-only `ApplicationVersion` carrying the override's `manifestDelta`
- **AND** after the copy is verified the migration SHALL delete the source `AppOverride` row

#### Scenario: Re-running the migration is a no-op for already-migrated overrides

- **WHEN** the migration runs a second time and a hybrid Application already exists for `appId` `pipelinq` (its source `AppOverride` row already deleted)
- **THEN** the migration SHALL NOT create a second hybrid Application
- **AND** it SHALL tolerate the already-deleted source row without error
