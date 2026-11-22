## MODIFIED Requirements

### Requirement: Manifest endpoint per virtual-app slug

The system SHALL expose the per-slug manifest endpoint and SHALL resolve a base + delta pair into a complete merged manifest when the active version carries a `baseRef`, while serving a legacy blob unchanged otherwise.

@e2e exclude pure-backend REST endpoint verified by Newman/manifest-endpoint.spec.ts; no separate UI surface

**ID:** REQ-OBR-001

#### Scenario: The endpoint is routed for any signed-in user

- **WHEN** `appinfo/routes.php` is read (ADR-016)
- **THEN** `GET /index.php/apps/buildiq/api/applications/{slug}/manifest` is routed to `ApplicationsController::getManifest`
- **AND** that method carries `#[NoAdminRequired]`, with a route-auth posture that treats the manifest as readable by an authenticated user

#### Scenario: Endpoint returns the stored manifest

- **WHEN** an authenticated user requests
  `/index.php/apps/buildiq/api/applications/hello-world/manifest`
- **AND** a published `Application` with `slug: hello-world` exists
  in their organisation
- **THEN** the response is `200 application/json` and the body is the
  resolved manifest for the active `ApplicationVersion`

#### Scenario: Unknown slug returns 404

- **WHEN** an authenticated user requests the manifest for a slug
  that has no matching `BuiltAppRoute` and no matching `Application`
- **THEN** the response is `404` with a JSON error body

#### Scenario: An app outside the caller's organisation is not found

- **WHEN** an authenticated user requests the manifest for a published `Application` that is not in their organisation scope
- **THEN** the response is `404`, as for an unknown slug

#### Scenario: The permissions gate answers before any manifest is emitted

- **WHEN** an authenticated user whom the Application's `permissions` do not admit requests its manifest
- **THEN** the per-Application `permissions` gate refuses the call before either resolution branch runs, unchanged from prior behaviour
- **AND** the response carries no manifest payload, for a legacy blob app and a delta-mode app alike

#### Scenario: An app without a route entry still resolves

- **GIVEN** an `Application` with `slug: hello-world` and no `BuiltAppRoute`
- **WHEN** one of its owners requests its manifest
- **THEN** the response is `200` with the production manifest
- **AND** the lookup by the `Application`'s own `slug` matches `{slug}` exactly and applies the same role checks as a lookup through a `BuiltAppRoute`

#### Scenario: Manifest name always reflects the Application's authoritative display name

- **WHEN** an authenticated user requests the manifest for a published Application whose
  `name` field is `"Pet Store"` but whose stored manifest blob has a `name` of `"pet-store"`
  (or has no `name` field at all)
- **THEN** the response body's top-level `name` is `"Pet Store"`, not `"pet-store"` and not
  absent
- **AND** the name is injected into the resolved manifest before it is returned, following the same additive-projection pattern as `runtime.user.isOwner` (`injectOwnerSignal`)

#### Scenario: Manifest name matches an already-consistent manifest blob

- **WHEN** an authenticated user requests the manifest for a published Application whose
  `name` field and stored manifest blob `name` are already identical
- **THEN** the response body's top-level `name` is unchanged and equal to both

#### Scenario: Legacy blob app serves unchanged

- **WHEN** an authenticated, authorised user requests the manifest for an
  app whose active `ApplicationVersion` has a `manifest` blob and no `baseRef`
- **THEN** the response is `200 application/json` and the body is the stored
  blob returned byte-for-byte, with no base resolution attempted

#### Scenario: Delta-mode app serves a merged manifest

- **WHEN** an authenticated, authorised user requests the manifest for an
  app whose active `ApplicationVersion` has a `baseRef` and a `manifestDelta`
- **THEN** the endpoint resolves the base, applies the delta server-side, and
  responds `200 application/json` with the merged manifest
- **AND** the response body contains neither the raw `baseRef` nor `manifestDelta`
- **AND** orphaned-delta diagnostics are omitted from the response
