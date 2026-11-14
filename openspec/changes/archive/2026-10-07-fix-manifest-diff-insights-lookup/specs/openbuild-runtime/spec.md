## MODIFIED Requirements

### Requirement: Manifest endpoint per virtual-app slug

The system SHALL expose
`GET /index.php/apps/buildiq/api/applications/{slug}/manifest`
backed by `ApplicationsController::getManifest`. The endpoint SHALL
resolve `{slug}` to an `Application` via the `BuiltAppRoute` index. When no
`BuiltAppRoute` exists for the slug, the endpoint SHALL resolve the
`Application` whose own `slug` equals `{slug}` exactly. It SHALL return the
stored `manifest` JSON blob with `Content-Type: application/json`, and respond
`200` on success or `404` when no matching Application exists in the caller's
organisation scope. Role checks apply the same way on both lookups. The endpoint
SHALL be registered via `appinfo/routes.php` (ADR-016) with `#[NoAdminRequired]`
and a route-auth posture that treats it as authenticated-user-readable.

Before the manifest is returned, the endpoint SHALL inject the
Application's current, authoritative `name` field as the manifest's
top-level `name`, overwriting (or supplying, when absent) whatever
`name` value the stored manifest blob itself carries. This injection
SHALL follow the same additive-projection pattern already used for
`runtime.user.isOwner` (`injectOwnerSignal`): the Application entity's
`name` is the single source of truth for display casing, so the
runtime's top-bar rebrand never falls back to the raw slug because of
manifest-blob drift (e.g. after the Application was renamed but its
stored manifest `name` was not updated to match).

@e2e exclude pure-backend REST endpoint; manifest fetch, 404 for unknown slug and auth posture are verified by Newman, in tests/integration/buildiq.postman_collection.json (Manifest endpoint folder) and tests/integration/buildiq-api-contract.postman_collection.json (2. Manifest). No separate UI surface

**ID:** REQ-OBR-001

#### Scenario: Endpoint returns the stored manifest

- **WHEN** an authenticated user requests
  `/index.php/apps/buildiq/api/applications/hello-world/manifest`
- **AND** a published `Application` with `slug: hello-world` exists
  in their organisation
- **THEN** the response is `200 application/json` and the body is the
  exact `manifest` blob persisted on the Application

#### Scenario: An app without a route entry still resolves

- **GIVEN** an `Application` with `slug: hello-world` and no `BuiltAppRoute`
- **WHEN** one of its owners requests its manifest
- **THEN** the response is `200` with the production manifest

#### Scenario: Unknown slug returns 404

- **WHEN** an authenticated user requests the manifest for a slug
  that has no matching `BuiltAppRoute` and no matching `Application`
- **THEN** the response is `404` with a JSON error body

#### Scenario: Manifest name always reflects the Application's authoritative display name

- **WHEN** an authenticated user requests the manifest for a published Application whose
  `name` field is `"Pet Store"` but whose stored manifest blob has a `name` of `"pet-store"`
  (or has no `name` field at all)
- **THEN** the response body's top-level `name` is `"Pet Store"`, not `"pet-store"` and not
  absent

#### Scenario: Manifest name matches an already-consistent manifest blob

- **WHEN** an authenticated user requests the manifest for a published Application whose
  `name` field and stored manifest blob `name` are already identical
- **THEN** the response body's top-level `name` is unchanged and equal to both
