---
retrofit_extensions:
  - REQ-OBICON-005
---

# app-icon-management Specification

## Purpose

Lets an operator brand each published OpenBuild virtual app with per-app SVG icons
(light + dark) so the published app surfaces with its own identity in the Nextcloud
top bar and in OpenBuild's own card grid. Adds top-level `icon` / `iconDark` ref
fields to the `Application` schema (sibling to `slug`, `name`, `manifest`,
`permissions`), thin icon-serving endpoints with a clear fallback chain, and the
upload / preview / remove UX on the Application detail page — all routed through
OR's existing files-attached-to-object mechanism (ADR-001) so no new openbuild-side
file storage is introduced.

## Requirements

### Requirement: Icon fields on Application schema (top-level)

The `Application` schema in `lib/Settings/openbuild_register.json` SHALL declare two optional
top-level properties — `icon` and `iconDark` — as siblings to `slug`, `name`, `manifest`,
`version`, and `permissions`. Each SHALL be an object of shape `{ "ref": "<filename>" }` where
`<filename>` is the name of an SVG file attached to the Application record via OpenRegister's
files-attached-to-object mechanism (ADR-001). Both fields SHALL be optional; omitting them
SHALL NOT cause schema validation failure.

@e2e exclude pure-backend OR schema validation contract — verified by Newman REST tests; no Playwright-testable UI surface for schema validation

**ID:** REQ-OBICON-001

Icons live outside the `manifest` object deliberately: they are openbuild-side admin
metadata, not part of the manifest the citizen developer designs and the runtime serves
to `CnAppRoot`. This keeps the change orthogonal to `app-manifest.schema.json` and avoids
any upstream coupling with `@conduction/nextcloud-vue`.

#### Scenario: Application with top-level icon fields validates successfully

- **WHEN** a client saves an Application carrying
  `"icon": { "ref": "app-icon.svg" }` and `"iconDark": { "ref": "app-icon-dark.svg" }` at
  the top level (sibling to `manifest`)
- **THEN** OR accepts the object and persists the record without validation errors

#### Scenario: Application without icon fields validates successfully

- **WHEN** a client saves an Application that omits both `icon` and `iconDark`
- **THEN** OR accepts the object and persists the record without validation errors

#### Scenario: Icon field with missing ref key is rejected

- **WHEN** a client saves an Application carrying `"icon": {}` (empty object, missing the
  `ref` key)
- **THEN** OR returns a 4xx validation error indicating `icon.ref` is required

### Requirement: Icon-serving endpoint (light)

The system SHALL expose `GET /index.php/apps/openbuild/icons/{slug}.svg` backed by
`IconController::iconLight`. The endpoint SHALL:

1. Look up the published Application by slug via OR's ObjectService.
2. Read the `icon.ref` filename from the Application's top-level `icon` field; if present,
   fetch the corresponding attached file from OR and return its bytes with
   `Content-Type: image/svg+xml`.
3. If the icon ref is absent or the attached file cannot be retrieved, fall back to
   OpenBuild's own `/img/app.svg` filesystem asset.
4. Set `Cache-Control: public, max-age=60` on every successful response.
5. Require any valid NC session (`#[NoAdminRequired]`); return `401` when no session exists.

@e2e exclude pure-backend REST endpoint — icon serving, fallback chain, cache headers, and 401 rejection verified by Newman; no separate UI surface beyond the <img> covered by applicationCard.spec.ts

**ID:** REQ-OBICON-002

#### Scenario: Endpoint returns the attached light icon

- **WHEN** an authenticated user requests `/icons/hello-world.svg`
- **AND** the hello-world Application has `icon.ref = "app-icon.svg"` at the top level
- **AND** an OR-attached file named `"app-icon.svg"` exists on the Application record
- **THEN** the response is `200 image/svg+xml` with `Cache-Control: public, max-age=60`
  and the body is the SVG bytes of the attached file

#### Scenario: Endpoint falls back to filesystem icon when ref absent

- **WHEN** an authenticated user requests `/icons/no-icon-app.svg`
- **AND** the no-icon-app Application has no `icon` field
- **THEN** the response is `200 image/svg+xml` and the body is the contents of
  OpenBuild's `/img/app.svg`

#### Scenario: Unauthenticated request is rejected

- **WHEN** a request arrives at `/icons/{slug}.svg` with no NC session cookie or token
- **THEN** the response is `401`

### Requirement: Icon-serving endpoint (dark)

The system SHALL expose `GET /index.php/apps/openbuild/icons/{slug}-dark.svg` backed by
`IconController::iconDark`. The endpoint SHALL apply the following fallback chain in order:

1. `iconDark.ref` (top-level on the Application) → attached file on the Application record.
2. `icon.ref` (top-level on the Application) → attached file on the Application record.
3. OpenBuild's own `/img/app-dark.svg` filesystem asset.
4. OpenBuild's own `/img/app.svg` filesystem asset (final fallback).

Cache and auth posture SHALL be identical to REQ-OBICON-002.

@e2e exclude pure-backend REST endpoint — dark-icon serving, 4-step fallback chain, and cache headers verified by Newman; no separate UI surface

**ID:** REQ-OBICON-003

#### Scenario: Endpoint returns the attached dark icon

- **WHEN** an authenticated user requests `/icons/hello-world-dark.svg`
- **AND** the hello-world Application has `iconDark.ref = "app-icon-dark.svg"` at the top
  level
- **AND** an OR-attached file named `"app-icon-dark.svg"` exists on the Application record
- **THEN** the response is `200 image/svg+xml` containing the dark SVG bytes

#### Scenario: Dark icon falls back to light icon when dark ref absent

- **WHEN** an authenticated user requests `/icons/light-only-app-dark.svg`
- **AND** the Application has `icon.ref = "app-icon.svg"` at the top level but no `iconDark`
- **THEN** the response is `200 image/svg+xml` containing the light icon SVG bytes

#### Scenario: Falls back to filesystem dark icon when no icon refs present

- **WHEN** an authenticated user requests `/icons/no-icon-app-dark.svg`
- **AND** the Application has neither `icon` nor `iconDark`
- **THEN** the response is `200 image/svg+xml` containing the contents of
  OpenBuild's `/img/app-dark.svg`

### Requirement: Icon section on Application detail page

The Application detail page SHALL include an **Icon** section exposing:

- A file picker / uploader for the light icon (`icon`) that calls OR's
  files-attached-to-object endpoint to store the uploaded SVG, then patches the Application's
  top-level `icon.ref` with the filename.
- A separate file picker / uploader for the dark icon (`iconDark`) with the same flow but
  targeting the top-level `iconDark.ref`.
- A live preview area showing the uploaded light icon against a white background and the
  uploaded dark icon against a dark (`#1c1c1e` or `var(--color-main-background)`)
  background.
- A remove button for each slot that detaches the file from OR and clears the corresponding
  top-level ref.

The section SHALL NOT introduce a new openbuild-side file-storage mechanism; all file I/O
goes through OR's existing files-attached-to-object endpoint (ADR-001).

**ID:** REQ-OBICON-004

#### Scenario: User uploads a light icon

- **WHEN** a user with editor or owner role selects an SVG file in the light-icon picker
- **THEN** the frontend POSTs the file to OR's attachment endpoint for the Application record
- **AND** patches the Application so the top-level `icon.ref` equals the uploaded filename
- **AND** the preview area renders the SVG against a light background

#### Scenario: User removes the dark icon

- **WHEN** a user clicks the remove button in the dark-icon slot
- **THEN** the frontend calls OR's delete-attachment endpoint for the `iconDark` file
- **AND** clears the top-level `iconDark.ref` from the Application
- **AND** the preview area falls back to showing the light icon in the dark-background slot

#### Scenario: Non-SVG file is rejected client-side

- **WHEN** a user attempts to upload a file with a non-`.svg` extension in either icon slot
- **THEN** the uploader displays an inline error message and does not submit the file to OR

### Requirement: Application UUID resolution for icon attachment lookup

`IconService` SHALL derive the OR object UUID it uses for icon
attachment lookups (`FileService::getFile`) from a normalised
Application array. The derivation SHALL walk three fallback locations
in this order: `@self.id`, `@self.uuid`, then top-level `uuid`. Each
candidate SHALL be accepted only when it is a non-empty string;
anything else (missing key, null, empty string, non-string scalar)
SHALL be skipped without raising. When no candidate produces a usable
value the helper SHALL return `null`, and the calling
`fetchAttachedFileStream` SHALL surface that as a short-circuit
fallback (no OR call, downstream fallback chain runs) — not as an
exception.

@e2e exclude pure-backend PHP service unit: extractUuid fallback chain is a single-class function verified by PHPUnit; no UI surface

**ID:** REQ-OBICON-005

#### Scenario: UUID lifted from @self.id

- **GIVEN** an Application array of shape
  `{ '@self': { id: 'abc-123' }, ... }`
- **WHEN** `IconService::extractUuid` is called
- **THEN** the returned UUID is `'abc-123'`

#### Scenario: UUID lifted from @self.uuid when @self.id is missing

- **GIVEN** an Application array of shape
  `{ '@self': { uuid: 'def-456' }, ... }` (no `@self.id`)
- **WHEN** `extractUuid` is called
- **THEN** the returned UUID is `'def-456'`

#### Scenario: UUID lifted from top-level uuid when @self is absent

- **GIVEN** an Application array of shape
  `{ uuid: 'ghi-789' }` (no `@self`)
- **WHEN** `extractUuid` is called
- **THEN** the returned UUID is `'ghi-789'`

#### Scenario: Empty string candidates are skipped

- **GIVEN** an Application array of shape
  `{ '@self': { id: '' }, uuid: 'fallback-uuid' }`
- **WHEN** `extractUuid` is called
- **THEN** the empty `@self.id` is skipped and the returned UUID is
  `'fallback-uuid'`

#### Scenario: No usable UUID returns null

- **GIVEN** an Application array of shape `{ name: 'x' }` (no UUID
  anywhere)
- **WHEN** `extractUuid` is called
- **THEN** the helper returns `null` and the downstream
  `fetchAttachedFileStream` SHALL return `null` without invoking
  `FileService::getFile`, so the icon-serving endpoint cascades to
  the next fallback (Decision 2 chain in design.md)
