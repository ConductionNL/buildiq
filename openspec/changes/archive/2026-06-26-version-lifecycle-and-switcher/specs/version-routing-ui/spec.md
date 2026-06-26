## MODIFIED Requirements

### Requirement: Version history lists snapshots and gates compare and rollback

`VersionHistory` SHALL load the app's `ApplicationVersion` rows from the working
slug-based endpoint `GET /apps/openbuild/api/applications/{slug}/versions` (NOT the
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
- **THEN** it calls `GET /apps/openbuild/api/applications/<slug>/versions`
- **AND** it renders one row per returned version using the real fields (`name`, `slug`,
  `semver`, `status`)

#### Scenario: Empty endpoint is no longer hit

- **WHEN** the version-history view loads
- **THEN** it does NOT call `/apps/openregister/api/objects/openbuild/application-version`
- **AND** it does NOT filter rows on `applicationUuid`

#### Scenario: Rollback through confirm

- **WHEN** the user requests a rollback
- **THEN** a confirm flow gates the revert until confirmed
