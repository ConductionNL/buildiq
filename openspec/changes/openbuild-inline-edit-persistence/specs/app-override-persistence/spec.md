## ADDED Requirements

### Requirement: Fleet-app override is stored as a keyed delta keyed by appId

The system SHALL persist a fleet app's manifest customization as an `AppOverride` record in the `buildiq` register, carrying `appId` (the kebab-case Nextcloud app id, the natural unique key), an optional `baseRef` (a structured note of what the delta was diffed against), a `manifestDelta` (the keyed structural delta produced by `diffManifest`), `updatedBy` (the saving user's UID), and `updatedAt` (an ISO-8601 timestamp). The record SHALL be **per-instance shared** — exactly one `AppOverride` per `appId`, shared by all users of the instance — and `manifestDelta` SHALL be a keyed delta consumable by `mergeManifestDelta` (pages keyed by `page.id`, widgets by `widget.id`, the `{ "$op": "remove" }` deletion marker, the optional `__order` reorder key) as defined by the `@conduction/nextcloud-vue` `manifest-delta-merge-and-flex-columns` contract.

#### Scenario: Saving an override persists a single record keyed by appId

- **WHEN** a delta is saved for `appId` `opencatalogi` and no override exists yet
- **THEN** the system SHALL create one `AppOverride` record with `appId: opencatalogi`, the supplied `manifestDelta`, and `updatedBy` set to the caller's UID

#### Scenario: Re-saving the same appId updates the existing record

- **WHEN** a delta is saved for an `appId` that already has an `AppOverride`
- **THEN** the system SHALL update the existing record in place (not create a second one)
- **AND** SHALL refresh `updatedBy` and `updatedAt` to the new saver and time

### Requirement: Write endpoint upserts the delta and records who saved

The system SHALL expose `PUT /index.php/apps/buildiq/api/app-overrides/{appId}` that accepts a `diffManifest` delta in the request body, validates the delta shape, upserts the per-`appId` `AppOverride`, and records `updatedBy` as the calling user's UID. The endpoint SHALL require an authenticated session and SHALL enforce CSRF; it SHALL reject an anonymous caller and SHALL reject a caller without Buildiq access with a forbidden response.

#### Scenario: Authenticated save returns success and stores updatedBy

- **WHEN** an authenticated user with Buildiq access PUTs a valid delta to `/api/app-overrides/pipelinq`
- **THEN** the endpoint SHALL upsert the `AppOverride` and respond `2xx`
- **AND** the stored record's `updatedBy` SHALL equal the caller's UID

#### Scenario: Anonymous write is rejected

- **WHEN** an unauthenticated request PUTs a delta to `/api/app-overrides/pipelinq`
- **THEN** the endpoint SHALL reject the request and SHALL NOT persist any record

### Requirement: Delta-shape validation rejects malformed or app-blanking deltas

The system SHALL validate, before persisting, that the request body is a keyed delta (a plain object whose page/widget entries carry the ids the contract requires, whose `$op` values are the known deletion marker, and whose `__order` value, when present, is an array of ids). The system SHALL reject a delta that would resolve to a manifest with no renderable pages or menu (an app-blanking delta) with a `422` and SHALL NOT persist it. A stored delta SHALL NOT cause the read endpoint to error; orphaned patches against a drifted base are surfaced client-side by the loader's `orphanedDeltaPaths`, not by failing the read.

#### Scenario: Malformed body is rejected with 422

- **WHEN** a PUT body is not a keyed delta (e.g. a whole manifest, or `null`)
- **THEN** the endpoint SHALL respond `422` and SHALL NOT persist an `AppOverride`

#### Scenario: App-blanking delta is rejected

- **WHEN** a PUT body is a delta that removes every page and leaves no menu
- **THEN** the endpoint SHALL respond `422` and SHALL NOT persist it

### Requirement: Read endpoint returns the raw delta for client-side merge

The system SHALL expose `GET /index.php/apps/buildiq/api/app-overrides/{appId}` returning the stored `manifestDelta` for that `appId` unchanged, so the fleet app's `@conduction/nextcloud-vue` loader configured with `mergeStrategy:'delta'` and `options.endpoint` pointed at this URL applies the delta over its own bundled manifest via `mergeManifestDelta(bundledManifest, delta)`. When no override exists for the `appId`, the endpoint SHALL return an empty delta so the merge is a no-op and the bundled manifest passes through unchanged. The endpoint SHALL require an authenticated session. The system SHALL NOT merge a fleet app's manifest server-side, because Buildiq does not hold the fleet app's bundled base manifest.

#### Scenario: Existing override returns the stored delta

- **WHEN** an authenticated user GETs `/api/app-overrides/opencatalogi` and an override exists
- **THEN** the response SHALL be `200 application/json` with the stored `manifestDelta` body
- **AND** the body SHALL be a keyed delta consumable by `mergeManifestDelta`

#### Scenario: No override returns an empty delta

- **WHEN** an authenticated user GETs `/api/app-overrides/somefleetapp` and no override exists
- **THEN** the response SHALL be `200` with an empty delta so the loader's merge is a no-op

### Requirement: Reset endpoint clears an override

The system SHALL expose `DELETE /index.php/apps/buildiq/api/app-overrides/{appId}` that removes the `AppOverride` for that `appId`, reverting the fleet app to its bundled manifest on the next manifest load. The endpoint SHALL require an authenticated session, SHALL enforce CSRF, and SHALL reject an anonymous caller and a caller without Buildiq access.

#### Scenario: Delete removes the override

- **WHEN** an authenticated user with Buildiq access DELETEs `/api/app-overrides/opencatalogi` and an override exists
- **THEN** the system SHALL remove the record and respond `2xx`
- **AND** a subsequent GET for that `appId` SHALL return an empty delta

#### Scenario: Delete of a non-existent override is idempotent

- **WHEN** an authenticated user DELETEs `/api/app-overrides/neverhadone`
- **THEN** the endpoint SHALL respond success without error (no record to remove)

### Requirement: Override writes require Buildiq access, not an admin role

The system SHALL gate the write and delete endpoints on the caller having Buildiq access — i.e. the Buildiq app is enabled and the caller is within its Nextcloud app group-restriction — rather than on a per-object ownership model, because an `AppOverride` is a per-instance shared customization with no per-object owner. The write/delete routes SHALL be declared `#[NoAdminRequired]` (login required, not admin-only) and SHALL carry this Buildiq-access guard in the method body so a logged-in user outside Buildiq's scope is forbidden.

#### Scenario: User with Buildiq access may write

- **WHEN** a logged-in user who can reach the enabled Buildiq app saves a valid delta
- **THEN** the write SHALL be permitted

#### Scenario: Logged-in user outside Buildiq scope is forbidden

- **WHEN** a logged-in user who is NOT within Buildiq's app group-restriction PUTs a delta
- **THEN** the endpoint SHALL respond forbidden and SHALL NOT persist the override
