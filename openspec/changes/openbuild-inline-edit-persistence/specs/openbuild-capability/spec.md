## ADDED Requirements

### Requirement: Buildiq advertises an edit-availability capability

The system SHALL implement an `OCP\Capabilities\ICapability` that contributes `{ "buildiq": { "enabled": true, "canEdit": <bool> } }` to the Nextcloud capabilities document, registered in the app bootstrap, so a fleet app can read a robust availability signal via `@nextcloud/capabilities` rather than inferring it from `OC.appswebroots`. `enabled` SHALL be `true` whenever the capability is contributed (the Buildiq app is enabled). `canEdit` SHALL reflect whether the **calling user** may use Buildiq's in-place edit feature, computed server-side from the real request user context.

#### Scenario: Capability is present when Buildiq is enabled

- **WHEN** a client reads the Nextcloud capabilities document on an instance with Buildiq enabled
- **THEN** the document SHALL contain `buildiq.enabled === true`
- **AND** SHALL contain a boolean `buildiq.canEdit`

### Requirement: canEdit reflects Buildiq access for the calling user

The system SHALL set `canEdit` to `true` when the calling user is within Buildiq's Nextcloud app group-restriction (i.e. the same condition that makes the in-app edit button reachable), and to `false` otherwise. The capability SHALL be a UI hint only — the write and delete endpoints SHALL re-check Buildiq access server-side and SHALL NOT trust the client's reading of `canEdit`.

#### Scenario: In-scope user sees canEdit true

- **WHEN** a logged-in user who can reach the enabled Buildiq app reads the capabilities document
- **THEN** `buildiq.canEdit` SHALL be `true`

#### Scenario: Out-of-scope user sees canEdit false

- **WHEN** a logged-in user outside Buildiq's app group-restriction reads the capabilities document
- **THEN** `buildiq.canEdit` SHALL be `false`

#### Scenario: canEdit is not a security boundary

- **WHEN** a user whose `canEdit` would be `false` nonetheless calls the write endpoint
- **THEN** the write endpoint SHALL independently re-check Buildiq access and reject the request, regardless of the advertised `canEdit` value
