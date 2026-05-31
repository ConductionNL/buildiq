---
retrofit: true
---

# version-routing-ui Specification

## Purpose

The version-routing UI exposes OpenBuild's two-object version model to the
maintainer: `VersionHistory` lists OR object-time-travel snapshots with compare
+ rollback, `PromoteVersionDialog` moves a manifest/schema/data set to a
downstream version with a computed default strategy and a destructive-confirm
gate, `RollbackConfirmModal` gates the revert, and the version composables
(`useApplicationVersion`, `useManifestHistory`) resolve the active version and
load history.

This capability is observed behaviour of those components. It is the frontend
half of the `version-routing`, `version-promotion`, and
`openbuild-version-snapshots` backend capabilities.

## ADDED Requirements

### Requirement: Version history lists snapshots and gates compare and rollback

`VersionHistory` SHALL load the time-travel snapshot rows (`refresh`,
`handler`), expose per-row display accessors (`rowKey`, `rowUuid`,
`rowVersion`, `rowNotes`, `rowPublishedAt`, `rowPublishedBy`,
`rowApplicationUuid`, `formatDate`), let the user compare two snapshots
(`compare`), and route rollback through a confirm flow
(`askRollback`, `onRollbackConfirmed`, `onRollbackCancelled`).

#### Scenario: List snapshots

- **WHEN** the version-history view loads
- **THEN** it renders one row per time-travel snapshot with author and timestamp

#### Scenario: Rollback through confirm

- **WHEN** the user requests a rollback
- **THEN** a confirm flow gates the revert until confirmed

### Requirement: Promote dialog computes strategy and gates the destructive confirm

`PromoteVersionDialog` SHALL bind the application and target version
(`application`, `targetVersion`), compute a default promotion strategy
(`computeDefaultStrategy`), render the summary and confirm helper text
(`summaryText`, `confirmHelperText`, `confirmInputLabel`), enforce a
destructive-confirm gate (`isDestructiveGateMet`), and emit confirm/cancel
(`onConfirm`, `onCancel`).

#### Scenario: Gate a destructive promotion

- **WHEN** the promotion would overwrite downstream data
- **THEN** the dialog requires the typed confirmation before `onConfirm` fires

### Requirement: Rollback confirm modal gates the revert

`RollbackConfirmModal` SHALL render the target title and formatted publish time
(`title`, `formattedPublishedAt`), track open state (`onUpdateOpen`), and emit
confirm/cancel (`confirm`, `cancel`).

#### Scenario: Confirm a rollback

- **WHEN** the user confirms in the rollback modal
- **THEN** the modal emits the confirm event with the target snapshot

### Requirement: Version composables resolve active version and manifest history

`useApplicationVersion(appSlug, versionSlug)` SHALL resolve the active version,
exposing a default editable version helper (`defaultEditableVersion`).
`useManifestHistory` SHALL load the manifest history for a version. These
composables SHALL return reactive state consumed by the builder hosts and the
detail header.

#### Scenario: Resolve the active version

- **WHEN** a builder host invokes `useApplicationVersion` with a slug pair
- **THEN** the composable resolves the matching version or signals not-found
