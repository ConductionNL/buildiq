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
