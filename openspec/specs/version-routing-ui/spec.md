---
retrofit: true
status: done
---

# version-routing-ui Specification

**OpenSpec changes**: [version-lifecycle-and-switcher](../../changes/archive/2026-06-26-version-lifecycle-and-switcher/) _(archived 2026-06-26)_

## Purpose

The version-routing UI exposes Buildiq's two-object version model to the
maintainer: `VersionHistory` lists OR object-time-travel snapshots with compare
+ rollback, `PromoteVersionDialog` moves a manifest/schema/data set to a
downstream version with a computed default strategy and a destructive-confirm
gate, `RollbackConfirmModal` gates the revert, and the version composables
(`useApplicationVersion`, `useManifestHistory`) resolve the active version and
load history.

This capability is observed behaviour of those components. It is the frontend
half of the `version-routing`, `version-promotion`, and
`buildiq-version-snapshots` backend capabilities.

## Requirements

### Requirement: Version history lists snapshots and gates compare and rollback

`VersionHistory` SHALL load the app's `ApplicationVersion` rows from the working
slug-based endpoint `GET /apps/buildiq/api/applications/{slug}/versions` (NOT the
OR-object endpoint `/apps/openregister/api/objects/openbuild/application-version`, which
returns no rows for this register shape) and SHALL key its rows off the real returned
fields (`name`, `slug`, `semver`, `status`, `application`, `register`, `manifest`). It
SHALL NOT filter on the non-existent field `applicationUuid`; the parent relation field is
`application`. The view SHALL accept the parent app **slug** (resolved by the caller from
the loaded `Application` object) to drive the endpoint call.

`VersionHistory` SHALL continue to expose per-row display accessors and the rollback
confirm flow (`askRollback`, `onRollbackConfirmed`, `onRollbackCancelled`) and SHALL add
the click-to-open and per-row Edit affordances defined in the `version-lifecycle-ui`
capability.

@e2e exclude covered by the version-lifecycle-and-switcher Playwright validation task (list renders, click-to-open, new-draft + release, Open-app split button) on the test23 app; the per-row display accessors and confirm-flow contracts remain Vitest-tested

#### Scenario: List versions from the working endpoint

- **GIVEN** an Application `<slug>` with one or more `ApplicationVersion` rows
- **WHEN** the version-history view loads with that slug
- **THEN** it calls `GET /apps/buildiq/api/applications/<slug>/versions`
- **AND** it renders one row per returned version using the real fields (`name`, `slug`,
  `semver`, `status`)

#### Scenario: Empty endpoint is no longer hit

- **WHEN** the version-history view loads
- **THEN** it does NOT call `/apps/openregister/api/objects/openbuild/application-version`
- **AND** it does NOT filter rows on `applicationUuid`

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

@e2e exclude retrofit component-contract spec — `computeDefaultStrategy`, `summaryText`, `confirmHelperText`, `confirmInputLabel`, `isDestructiveGateMet`, `onConfirm`/`onCancel` are dialog-component contracts verified by Vitest unit tests; destructive-gate flow requires a running dev→staging chain which is covered by the buildiq-version-snapshots Newman tests

#### Scenario: Gate a destructive promotion

- **WHEN** the promotion would overwrite downstream data
- **THEN** the dialog requires the typed confirmation before `onConfirm` fires

### Requirement: Rollback confirm modal gates the revert

`RollbackConfirmModal` SHALL render the target title and formatted publish time
(`title`, `formattedPublishedAt`), track open state (`onUpdateOpen`), and emit
confirm/cancel (`confirm`, `cancel`).

@e2e exclude retrofit component-contract spec — `title`, `formattedPublishedAt`, `onUpdateOpen`, `confirm`/`cancel` emit contracts are modal-component contracts verified by Vitest unit tests; rollback confirmation flow is covered by the buildiq-runtime Playwright tests

#### Scenario: Confirm a rollback

- **WHEN** the user confirms in the rollback modal
- **THEN** the modal emits the confirm event with the target snapshot

### Requirement: Version composables resolve active version and manifest history

`useApplicationVersion(appSlug, versionSlug)` SHALL resolve the active version,
exposing a default editable version helper (`defaultEditableVersion`).
`useManifestHistory` SHALL load the manifest history for a version. These
composables SHALL return reactive state consumed by the builder hosts and the
detail header.

@e2e exclude retrofit composable-contract spec — `useApplicationVersion` reactive-state resolution, `defaultEditableVersion` helper, and `useManifestHistory` load contracts are composable contracts verified by Vitest unit tests; version slug resolution in the builder host is covered by the buildiq-runtime Playwright tests

#### Scenario: Resolve the active version

- **WHEN** a builder host invokes `useApplicationVersion` with a slug pair
- **THEN** the composable resolves the matching version or signals not-found
