## MODIFIED Requirements

### Requirement: Fleet-app override is stored as a keyed delta keyed by appId

The system SHALL persist a fleet app's manifest customization as a **hybrid `Application`** (`appType: "hybrid"`, `slug` = the fleet appId, `baseRef.id` = the fleet appId) plus a delta-only `ApplicationVersion` carrying `manifestDelta`, instead of a standalone `AppOverride` record. The customization SHALL remain **per-instance shared** — exactly one hybrid `Application` per fleet `appId`, shared by all users of the instance — and `manifestDelta` SHALL be a keyed delta consumable by `mergeManifestDelta` (pages keyed by `page.id`, widgets by `widget.id`, the `{ "$op": "remove" }` deletion marker, the optional `__order` reorder key) as defined by the `@conduction/nextcloud-vue` `manifest-delta-merge-and-flex-columns` contract. The version SHALL record provenance via OR's audit trail (last-writer-wins). The hybrid `Application` is the single source of truth — once the migration has run there is no legacy `AppOverride` read path.

#### Scenario: Saving an override persists a single hybrid Application keyed by appId

- **WHEN** a delta is saved for `appId` `opencatalogi` and no hybrid Application exists yet
- **THEN** the system SHALL create one `Application(appType:hybrid, slug:"opencatalogi", baseRef.id:"opencatalogi")` and one delta-only `ApplicationVersion` carrying the supplied `manifestDelta`

#### Scenario: Re-saving the same appId updates the existing hybrid Application

- **WHEN** a delta is saved for an `appId` that already has a hybrid Application
- **THEN** the system SHALL update that Application's production version delta in place (not create a second Application)

### Requirement: Read endpoint returns the raw delta for client-side merge

The system SHALL keep `GET /index.php/apps/openbuild/api/app-overrides/{appId}` as a **compatibility shim** returning the stored `manifestDelta` for that `appId` unchanged, now sourced from the hybrid `Application`'s production `ApplicationVersion` (resolved by `baseRef.id == {appId}` or `slug == {appId}`). The fleet app's `@conduction/nextcloud-vue` loader configured with `mergeStrategy:'delta'` and `options.endpoint` pointed at this URL SHALL apply the delta over its own bundled manifest via `mergeManifestDelta(bundledManifest, delta)`. When no hybrid Application exists for the `appId`, the endpoint SHALL return an empty delta so the merge is a no-op and the bundled manifest passes through unchanged. The endpoint SHALL require an authenticated session. The system SHALL NOT merge a fleet app's manifest server-side, because OpenBuild does not hold the fleet app's bundled base manifest.

#### Scenario: Existing override returns the stored delta from the hybrid Application

- **WHEN** an authenticated user GETs `/api/app-overrides/opencatalogi` and a hybrid Application exists for it
- **THEN** the response SHALL be `200 application/json` with the hybrid Application's production-version `manifestDelta` body
- **AND** the body SHALL be a keyed delta consumable by `mergeManifestDelta`

#### Scenario: No override returns an empty delta

- **WHEN** an authenticated user GETs `/api/app-overrides/somefleetapp` and no hybrid Application exists
- **THEN** the response SHALL be `200` with an empty delta so the loader's merge is a no-op

### Requirement: Write endpoint upserts the delta and records who saved

The system SHALL keep `PUT /index.php/apps/openbuild/api/app-overrides/{appId}` as a **compatibility shim** that accepts a `diffManifest` delta in the request body, validates the delta shape, and upserts the per-`appId` hybrid `Application` + delta-only `ApplicationVersion` (creating both on first write, exactly as the wizard's hybrid branch would), recording the calling user's UID as provenance. The endpoint SHALL require an authenticated session and SHALL enforce CSRF; it SHALL reject an anonymous caller and SHALL reject a caller without OpenBuild access with a forbidden response.

#### Scenario: Authenticated save creates or updates the hybrid Application

- **WHEN** an authenticated user with OpenBuild access PUTs a valid delta to `/api/app-overrides/pipelinq`
- **THEN** the endpoint SHALL upsert the hybrid Application for `pipelinq` and respond `2xx`
- **AND** the stored version delta SHALL equal the supplied delta

#### Scenario: Anonymous write is rejected

- **WHEN** an unauthenticated request PUTs a delta to `/api/app-overrides/pipelinq`
- **THEN** the endpoint SHALL reject the request and SHALL NOT persist any record

### Requirement: Reset endpoint clears an override

The system SHALL keep `DELETE /index.php/apps/openbuild/api/app-overrides/{appId}` as a **compatibility shim** that clears the hybrid `Application`'s override for that `appId` (archiving/removing the override so the fleet app reverts to its bundled manifest on the next manifest load). The endpoint SHALL require an authenticated session, SHALL enforce CSRF, and SHALL reject an anonymous caller and a caller without OpenBuild access.

#### Scenario: Delete clears the hybrid Application override

- **WHEN** an authenticated user with OpenBuild access DELETEs `/api/app-overrides/opencatalogi` and a hybrid Application exists
- **THEN** the system SHALL clear the override and respond `2xx`
- **AND** a subsequent GET for that `appId` SHALL return an empty delta

#### Scenario: Delete of a non-existent override is idempotent

- **WHEN** an authenticated user DELETEs `/api/app-overrides/neverhadone`
- **THEN** the endpoint SHALL respond success without error (no record to clear)
