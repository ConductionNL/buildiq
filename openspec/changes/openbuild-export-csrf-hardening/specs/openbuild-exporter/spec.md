## ADDED Requirements

### Requirement: Export submission enforces CSRF protection

The export submission endpoint (`POST /apps/openbuild/api/applications/{slug}/exports`) SHALL enforce Nextcloud's CSRF protection: the controller method SHALL NOT carry `#[NoCSRFRequired]`, so a request without a valid `requesttoken` is rejected by the AppFramework middleware before the controller body runs. The download endpoint (`GET /api/exports/{uuid}/download`) SHALL remain CSRF-exempt (`#[NoCSRFRequired]`) because it must be reachable via plain browser navigation for the ZIP stream; it SHALL remain an idempotent GET guarded by per-job authorization with 404-masking.

#### Scenario: Cross-site POST without a request token is rejected

- **GIVEN** a user with a valid Nextcloud session and export rights on application `hello-world`
- **WHEN** a POST to `/apps/openbuild/api/applications/hello-world/exports` arrives without a valid `requesttoken` header (e.g. a cross-site form/fetch riding the session cookie)
- **THEN** the middleware SHALL reject the request before `ExportsController::submit` executes
- **AND** no ExportJob SHALL be queued and no GitHub push SHALL occur

#### Scenario: SPA submission with the request token succeeds unchanged

- **WHEN** `ExportDialog.vue` submits the same POST through `@nextcloud/axios` (which sends the `requesttoken`)
- **THEN** the request SHALL pass the CSRF check and behave exactly as before this change (202 Accepted with `{ uuid }`)

#### Scenario: Download stays reachable by navigation

- **WHEN** an authorised user opens `/apps/openbuild/api/exports/{uuid}/download` as a plain browser navigation (no `requesttoken`)
- **THEN** the ZIP SHALL stream successfully for an authorised, unexpired job
- **AND** an unauthorised caller SHALL still receive 404 (masked), regardless of CSRF exemption
