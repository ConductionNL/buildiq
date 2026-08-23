## ADDED Requirements

### Requirement: The insights role check MUST honour group principals

`ApplicationInsightsService::callerInAnyRole` SHALL authorize a caller who matches
via a `group:` principal, not only `user:`/bare-uid — reusing
`PermissionResolver::matchesCaller` so insights authorization is consistent with
every other Buildiq guard. A caller authorized solely through group membership
MUST NOT be wrongly denied.

#### Scenario: A group-only-authorized caller gets insights
- **WHEN** a caller is authorized for a version only via a `group:` principal
- **THEN** the insights endpoint grants access

#### Scenario: An unauthorized caller is still denied
- **WHEN** a caller matches neither a user nor a group principal (and is not an
  admin)
- **THEN** the insights endpoint denies access (fail-closed)
