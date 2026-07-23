## ADDED Requirements

### Requirement: Public manifest resolution never uses session/organisation authorization

The runtime's public rendering and submission routes (see `public-form-access`) SHALL resolve authorization solely through a `ShareToken`, never through the
`#[NoAdminRequired]` session-based posture used by
`ApplicationsController::getManifest` and the rest of the authenticated
runtime. The public routes SHALL be registered in `appinfo/routes.php` as a
distinct route group, before the SPA catch-all, and SHALL NOT share a
controller method with the authenticated manifest endpoint.

#### Scenario: Public route is reachable without an NC session

- **WHEN** an anonymous, unauthenticated visitor (no NC session cookie)
  requests a public render or submission route with a valid token
- **THEN** the request SHALL succeed on token validity alone, without any
  session-based authentication check

#### Scenario: Authenticated manifest endpoint is unaffected

- **WHEN** an authenticated user requests
  `/index.php/apps/openbuild/api/applications/{slug}/manifest`
- **THEN** the endpoint SHALL continue to resolve via the existing
  `BuiltAppRoute` + session/organisation posture exactly as before this
  change, with no token-based branch in that controller method
