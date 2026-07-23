## ADDED Requirements

### Requirement: ShareToken schema scopes one token to one Application and page

The system SHALL declare a `ShareToken` schema in the `openbuild` register
namespace with properties `applicationId`, `pageId`, `token` (server-generated,
opaque, cryptographically random), `mode` (`submit`|`read`|`edit`),
`boundObjectId` (optional), `expiresAt` (optional for `submit`/`read`,
mandatory for `edit`), `passwordHash` (optional), `revoked` (boolean, default
false), `allowedPrefillFields` (string array), `honeypotField` (server-generated
per-token field name), and `requireEmailVerification` (boolean, default false).
A `ShareToken` SHALL reference exactly one `applicationId` and one `pageId` —
never a whole Application.

#### Scenario: Token creation generates an opaque random token and honeypot field name

- **WHEN** an authorised editor creates a share token for a page
- **THEN** the system SHALL generate a cryptographically random `token` value
  and a randomised `honeypotField` name
- **AND** SHALL persist the `ShareToken` scoped to that Application and page

#### Scenario: Edit-mode token requires an expiry

- **WHEN** an editor creates a token with `mode: edit` and no `expiresAt`
- **THEN** the system SHALL reject the creation with a validation error

### Requirement: Public page can only be issued a token when its config declares public.enabled

`ShareTokenService::issue()` SHALL reject creating a token for a page whose `config.public.enabled` is not `true`. A page's manifest `config` MAY carry a `public` block
(`enabled`, `mode`, `allowedPrefillFields`, `honeypotField`,
`requireEmailVerification`) that opts the page into public token issuance.

#### Scenario: Token creation blocked for a non-public page

- **WHEN** an editor attempts to create a share token for a page whose
  `config.public.enabled` is absent or `false`
- **THEN** the system SHALL reject the request with a validation error
  explaining the page must be marked public first

### Requirement: Token management UI in the page designer and app settings

The system SHALL provide a `ShareTokenDialog` (under `src/dialogs/`) reachable
from the page designer toolbar (scoped to the open page) and from
`AppSettingsModal` (scoped to the whole Application), offering create, revoke,
copy-link, and expiry-editing actions. Revoking a token SHALL take effect
immediately for subsequent requests.

#### Scenario: Revoked token stops resolving

- **WHEN** an editor revokes a `ShareToken` via `ShareTokenDialog`
- **THEN** a subsequent public request using that token SHALL respond with an
  error indicating the link is no longer valid, not the page content

### Requirement: Public render endpoint resolves a token to exactly its bound page

The system SHALL expose a `#[PublicPage]` endpoint that resolves `{token}` to
its `ShareToken`, validates it is not revoked/expired and, if a
`passwordHash` is set, requires the matching password, then returns a
manifest fragment containing **only** the bound page, its schema, and its
widgets — no other page, schema, or Application data.

#### Scenario: Valid token renders its bound page only

- **WHEN** an anonymous visitor requests the public render endpoint with a
  valid, unexpired, non-revoked token
- **THEN** the response SHALL contain the bound page's manifest fragment
- **AND** SHALL NOT contain any other page or schema belonging to the
  Application

#### Scenario: Expired or revoked token is rejected

- **WHEN** an anonymous visitor requests the public render endpoint with an
  expired or revoked token
- **THEN** the response SHALL be an error response and SHALL NOT include any
  page content

#### Scenario: Password-protected token requires the password

- **WHEN** an anonymous visitor requests a token whose `ShareToken` has a
  `passwordHash` set, without supplying a matching password
- **THEN** the response SHALL prompt for a password and SHALL NOT include the
  page content

### Requirement: Anonymous submission writes via owner-context service, never a visitor identity

The system SHALL expose a `#[PublicPage]` `#[NoCSRFRequired]` `#[AnonRateLimit]`
submission endpoint that, given a valid `mode: submit` or `mode: edit` token
and form data, validates the data against the target schema, rejects (silently,
without a write) any submission where the token's `honeypotField` is
non-empty, and then writes the object through a server-side service acting
with the Application owner's authorization context — never through the OR
client-facing objects API and never as a visitor identity, because no visitor
identity exists.

#### Scenario: Valid anonymous submission creates an object

- **WHEN** an anonymous visitor submits valid data against a `mode: submit`
  token, leaving the honeypot field empty
- **THEN** the system SHALL create the object in the target schema, attributed
  to the Application owner's context

#### Scenario: Honeypot-filled submission is silently dropped

- **WHEN** an anonymous submission has a non-empty value in the token's
  `honeypotField`
- **THEN** the system SHALL respond `200` without performing any write

#### Scenario: Rate limit throttles repeated submissions

- **WHEN** an anonymous caller exceeds the configured `AnonRateLimit` on the
  submission endpoint from the same source
- **THEN** subsequent requests within the window SHALL be rejected before
  reaching submission validation

### Requirement: Prefill-from-URL maps allow-listed query params to form fields

For a `mode: submit` token, the public render endpoint SHALL accept query
parameters matching the token's `allowedPrefillFields` and return them as
initial field values for the rendered form. Query parameters not present in
`allowedPrefillFields` SHALL be ignored and SHALL NOT be reflected into the
form or the eventual submission.

#### Scenario: Allow-listed query param prefills a field

- **WHEN** an anonymous visitor opens a public form link with a query
  parameter matching an entry in `allowedPrefillFields`
- **THEN** the corresponding form field SHALL render pre-filled with that
  value

#### Scenario: Non-allow-listed query param is ignored

- **WHEN** an anonymous visitor opens a public form link with a query
  parameter not present in `allowedPrefillFields`
- **THEN** the system SHALL NOT map that parameter onto any form field

### Requirement: Per-record edit links bind a token to one object and update on submit

A `mode: edit` token with a `boundObjectId` SHALL, on render, pre-fill the form
with that object's current field values, and on submit SHALL update that same
object rather than creating a new one. Submission SHALL be rejected if the
bound object no longer exists.

#### Scenario: Edit-link submission updates the bound record

- **WHEN** an anonymous visitor submits a valid `mode: edit` token bound to an
  existing object
- **THEN** the system SHALL update that object's fields with the submitted
  data
- **AND** SHALL NOT create a new object

#### Scenario: Edit-link for a deleted record is rejected

- **WHEN** an anonymous visitor submits a `mode: edit` token whose
  `boundObjectId` no longer resolves to an existing object
- **THEN** the system SHALL reject the submission with an error and SHALL NOT
  create a replacement object
