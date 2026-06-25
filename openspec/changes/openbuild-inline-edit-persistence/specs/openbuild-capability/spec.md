## ADDED Requirements

### Requirement: OpenBuild advertises an edit-availability capability

The system SHALL implement an `OCP\Capabilities\ICapability` that contributes `{ "openbuild": { "enabled": true, "canEdit": <bool> } }` to the Nextcloud capabilities document, registered in the app bootstrap, so a fleet app can read a robust availability signal via `@nextcloud/capabilities` rather than inferring it from `OC.appswebroots`. `enabled` SHALL be `true` whenever the capability is contributed (the OpenBuild app is enabled). `canEdit` SHALL reflect whether the **calling user** may use OpenBuild's in-place edit feature, computed server-side from the real request user context.

#### Scenario: Capability is present when OpenBuild is enabled

- **WHEN** a client reads the Nextcloud capabilities document on an instance with OpenBuild enabled
- **THEN** the document SHALL contain `openbuild.enabled === true`
- **AND** SHALL contain a boolean `openbuild.canEdit`

### Requirement: canEdit reflects OpenBuild access for the calling user

The system SHALL set `canEdit` to `true` when the calling user is within OpenBuild's Nextcloud app group-restriction (i.e. the same condition that makes the in-app edit button reachable), and to `false` otherwise. The capability SHALL be a UI hint only — the write and delete endpoints SHALL re-check OpenBuild access server-side and SHALL NOT trust the client's reading of `canEdit`.

#### Scenario: In-scope user sees canEdit true

- **WHEN** a logged-in user who can reach the enabled OpenBuild app reads the capabilities document
- **THEN** `openbuild.canEdit` SHALL be `true`

#### Scenario: Out-of-scope user sees canEdit false

- **WHEN** a logged-in user outside OpenBuild's app group-restriction reads the capabilities document
- **THEN** `openbuild.canEdit` SHALL be `false`

#### Scenario: canEdit is not a security boundary

- **WHEN** a user whose `canEdit` would be `false` nonetheless calls the write endpoint
- **THEN** the write endpoint SHALL independently re-check OpenBuild access and reject the request, regardless of the advertised `canEdit` value
