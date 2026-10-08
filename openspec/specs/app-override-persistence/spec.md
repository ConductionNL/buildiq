# app-override-persistence Specification

## Purpose

Persists and serves a fleet app's shared manifest customization — the keyed
`manifestDelta` produced by `@conduction/nextcloud-vue`'s `diffManifest` — keyed by
the fleet app id, and exposes the `GET`/`PUT`/`DELETE /api/app-overrides/{appId}`
endpoints the fleet app's `mergeStrategy:'delta'` loader consumes client-side
(Buildiq holds no fleet base, so the delta is merged on the client). Originally
backed by a standalone `AppOverride` record; the `unify-apps-with-app-type` change
repoints storage to a hybrid `Application` + delta-only `ApplicationVersion` and keeps
the three endpoints as compatibility shims so live fleet apps stay byte-for-byte
compatible.

**OpenSpec changes**: [layered-versioned-app-deltas](../../changes/layered-versioned-app-deltas/), [buildiq-inline-edit-persistence](../../changes/buildiq-inline-edit-persistence/), [unify-apps-with-app-type](../../changes/archive/2026-06-20-unify-apps-with-app-type/) _(archived 2026-06-20)_

**Status**: in-progress

@e2e exclude backend compatibility-shim — the GET/PUT/DELETE /api/app-overrides/{appId} endpoints (now backed by the hybrid Application's version) are covered by the AppOverrideService + AppOverrideController PHPUnit suites; the client-side merge is the fleet app's own concern, so there is no Playwright flow here.

## Requirements

### Requirement: Fleet-app override is stored as a keyed delta keyed by appId

The system SHALL persist a fleet app's manifest customization as a **hybrid `Application`** (`appType: "hybrid"`, `slug` = the fleet appId, `baseRef.id` = the fleet appId) plus a delta-only `ApplicationVersion` carrying `manifestDelta`, instead of a standalone `AppOverride` record. The customization SHALL remain **per-instance shared** — exactly one hybrid `Application` per fleet `appId`, shared by all users of the instance — and `manifestDelta` SHALL be a keyed delta consumable by `mergeManifestDelta` (pages keyed by `page.id`, widgets by `widget.id`, the `{ "$op": "remove" }` deletion marker, the optional `__order` reorder key) as defined by the `@conduction/nextcloud-vue` `manifest-delta-merge-and-flex-columns` contract. The version SHALL record provenance via OR's audit trail (last-writer-wins). The hybrid `Application` is the single source of truth — once the migration has run there is no legacy `AppOverride` read path.

#### Scenario: Saving an override persists a single hybrid Application keyed by appId

- **WHEN** a delta is saved for `appId` `opencatalogi` and no hybrid Application exists yet
- **THEN** the system SHALL create one `Application(appType:hybrid, slug:"opencatalogi", baseRef.id:"opencatalogi")` and one delta-only `ApplicationVersion` carrying the supplied `manifestDelta`

#### Scenario: Re-saving the same appId updates the existing hybrid Application

- **WHEN** a delta is saved for an `appId` that already has a hybrid Application
- **THEN** the system SHALL update that Application's production version delta in place (not create a second Application)

### Requirement: Read endpoint returns the raw delta for client-side merge

The system SHALL keep `GET /index.php/apps/buildiq/api/app-overrides/{appId}` as
a **compatibility shim** returning the stored `manifestDelta` for that `appId`,
now extended to be **scope-aware**: when the resolved hybrid `Application` has
`allowUserOverrides == true` AND the authenticated caller owns a `scope: user`
`ApplicationVersion` chained (via `baseRef`) to that app's admin delta, the
endpoint SHALL return the layered delta chain so the fleet app's loader resolves
`base ⊕ admin-delta ⊕ user-delta` client-side. When `allowUserOverrides` is
`false`, or no user delta exists for the caller, the endpoint SHALL return exactly
the admin delta as today (the user layer is never applied). The endpoint SHALL
NOT merge the fleet app's bundled base server-side (Buildiq does not hold it).
When no hybrid Application exists for the `appId`, the endpoint SHALL return an
empty delta so the merge is a no-op. The endpoint SHALL require an authenticated
session. A caller SHALL never receive another user's delta.

#### Scenario: Existing override returns the stored delta from the hybrid Application

- **WHEN** an authenticated user GETs `/api/app-overrides/opencatalogi` and a
  hybrid Application exists for it with `allowUserOverrides: false`
- **THEN** the response SHALL be `200 application/json` with the hybrid
  Application's production-version (admin) `manifestDelta` body
- **AND** the body SHALL be a keyed delta consumable by `mergeManifestDelta`

#### Scenario: Caller's user delta is layered when overrides are enabled

- **WHEN** an authenticated user who owns a `scope: user` delta GETs
  `/api/app-overrides/{appId}` and the app has `allowUserOverrides: true`
- **THEN** the response SHALL carry the layered admin + caller's-user delta chain
- **AND** the body SHALL remain consumable by `mergeManifestDelta`

#### Scenario: No override returns an empty delta

- **WHEN** an authenticated user GETs `/api/app-overrides/somefleetapp` and no
  hybrid Application exists
- **THEN** the response SHALL be `200` with an empty delta so the loader's merge
  is a no-op

#### Scenario: Another user's delta is never returned

- **WHEN** user B GETs `/api/app-overrides/{appId}` for an app where user A owns a
  user delta and B owns none
- **THEN** the response SHALL carry only the admin delta (and B's own delta if
  any) — never user A's delta

### Requirement: Write endpoint upserts the delta and records who saved

The system SHALL keep `PUT /index.php/apps/buildiq/api/app-overrides/{appId}` as a **compatibility shim** that accepts a `diffManifest` delta in the request body, validates the delta shape, and upserts the per-`appId` hybrid `Application` + delta-only `ApplicationVersion` (creating both on first write, exactly as the wizard's hybrid branch would), recording the calling user's UID as provenance. The endpoint SHALL require an authenticated session and SHALL enforce CSRF; it SHALL reject an anonymous caller and SHALL reject a caller without Buildiq access with a forbidden response.

#### Scenario: Authenticated save creates or updates the hybrid Application

- **WHEN** an authenticated user with Buildiq access PUTs a valid delta to `/api/app-overrides/pipelinq`
- **THEN** the endpoint SHALL upsert the hybrid Application for `pipelinq` and respond `2xx`
- **AND** the stored version delta SHALL equal the supplied delta

#### Scenario: Anonymous write is rejected

- **WHEN** an unauthenticated request PUTs a delta to `/api/app-overrides/pipelinq`
- **THEN** the endpoint SHALL reject the request and SHALL NOT persist any record

### Requirement: Reset endpoint clears an override

The system SHALL keep `DELETE /index.php/apps/buildiq/api/app-overrides/{appId}` as a **compatibility shim** that clears the hybrid `Application`'s override for that `appId` (archiving/removing the override so the fleet app reverts to its bundled manifest on the next manifest load). The endpoint SHALL require an authenticated session, SHALL enforce CSRF, and SHALL reject an anonymous caller and a caller without Buildiq access.

#### Scenario: Delete clears the hybrid Application override

- **WHEN** an authenticated user with Buildiq access DELETEs `/api/app-overrides/opencatalogi` and a hybrid Application exists
- **THEN** the system SHALL clear the override and respond `2xx`
- **AND** a subsequent GET for that `appId` SHALL return an empty delta

#### Scenario: Delete of a non-existent override is idempotent

- **WHEN** an authenticated user DELETEs `/api/app-overrides/neverhadone`
- **THEN** the endpoint SHALL respond success without error (no record to clear)

### Requirement: User-delta write is owner-scoped and flag-gated

The system SHALL allow an authenticated user to create or update their OWN
`scope: user` manifest delta for an `appId` ONLY when the hybrid
`Application.allowUserOverrides` is `true`. The write SHALL set `owner` to the
calling UID, set `scope: user`, set the user delta's `baseRef` to point at the
admin delta version, and validate the delta shape and non-blank guard exactly as
the admin write path does (reusing `AppOverrideService` delta validation). A write
that targets `allowUserOverrides: false`, or that supplies an `owner` other than
the caller, SHALL be rejected fail-closed. CSRF SHALL be enforced and anonymous
callers SHALL be rejected. A user SHALL never write another user's delta.

@e2e exclude backend write contract — the owner-scoped, flag-gated user-delta write reuses the AppOverrideService validation path and is verified by PHPUnit + a no-admin-idor cross-user test; the in-app create flow is covered by the application-delta-layers-ui spec

**ID:** REQ-AOP-008

#### Scenario: Owner writes their own user delta when enabled

- **WHEN** an authenticated user PUTs a valid user delta for an `appId` with
  `allowUserOverrides: true`
- **THEN** the system upserts a `scope: user` `ApplicationVersion` owned by the
  caller and responds `2xx`

#### Scenario: User-delta write rejected when overrides disabled

- **WHEN** an authenticated user PUTs a user delta for an `appId` with
  `allowUserOverrides: false`
- **THEN** the request is rejected and no `scope: user` row is persisted

#### Scenario: Cannot write a delta owned by another user

- **WHEN** an authenticated user PUTs a user delta whose `owner` is a different
  UID
- **THEN** the request is rejected fail-closed
