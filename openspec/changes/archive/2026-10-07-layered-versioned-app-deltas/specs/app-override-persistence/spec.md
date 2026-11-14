## MODIFIED Requirements

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

## ADDED Requirements

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
