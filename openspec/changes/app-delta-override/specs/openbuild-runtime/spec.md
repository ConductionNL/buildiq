## MODIFIED Requirements

### Requirement: Manifest endpoint per virtual-app slug

The system SHALL expose the per-slug manifest endpoint and SHALL resolve a base + delta pair into a complete merged manifest when the active version carries a `baseRef`, while serving a legacy blob unchanged otherwise.

@e2e exclude pure-backend REST endpoint — manifest fetch, 404 for unknown slug, base+delta resolution, legacy-blob fallback, and auth posture verified by Newman/manifest-endpoint.spec.ts; no separate UI surface

The endpoint at
`GET /index.php/apps/openbuild/api/applications/{slug}/manifest`
is backed by `ApplicationsController::getManifest`. The endpoint SHALL
resolve `{slug}` to an `Application` via the `BuiltAppRoute` index and
return a complete, already-merged `manifest` JSON blob with
`Content-Type: application/json`, responding `200` on success or `404`
when no matching published Application exists in the caller's
organisation scope. The endpoint SHALL be registered via
`appinfo/routes.php` (ADR-016) with `#[NoAdminRequired]` and a
route-auth posture that treats it as authenticated-user-readable.

Resolution of the returned manifest SHALL branch on whether the active
`ApplicationVersion` carries a non-null `baseRef`:

- When `baseRef` is **null or absent**, the endpoint SHALL return the
  stored `manifest` blob unchanged (legacy behaviour — the blob IS the
  manifest; no base resolution, no delta merge).
- When `baseRef` is **set**, the endpoint SHALL resolve the base
  manifest named by `baseRef`, apply the version's `manifestDelta` over
  it via a server-side keyed merge equivalent to the canonical JS
  `mergeManifestDelta`, and return the merged result.

The merge SHALL be performed server-side so consumers receive a complete
manifest and never perform their own merge. The raw `baseRef` and
`manifestDelta` SHALL NOT appear in the response body, and orphaned-delta
diagnostics SHALL be omitted from the public response. RBAC enforcement
(the per-Application `permissions` gate) SHALL run before any branch that
emits a manifest payload, unchanged from prior behaviour.

**ID:** REQ-OBR-001

#### Scenario: Endpoint returns the stored manifest

- **WHEN** an authenticated user requests
  `/index.php/apps/openbuild/api/applications/hello-world/manifest`
- **AND** a published `Application` with `slug: hello-world` exists
  in their organisation
- **THEN** the response is `200 application/json` and the body is the
  resolved manifest for the active `ApplicationVersion`

#### Scenario: Unknown slug returns 404

- **WHEN** an authenticated user requests the manifest for a slug
  that has no matching `BuiltAppRoute`
- **THEN** the response is `404` with a JSON error body

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
