## ADDED Requirements

### Requirement: Instantiating an app from a template MUST match the creation wizard's throttle and gate

`ApplicationsController::createFromTemplate` SHALL carry an equivalent
`#[UserRateLimit]` and MUST apply the same authorization gate as
`ApplicationCreationController::wizard`. It provisions a per-app OpenRegister
register and clones companion schemas — the same fan-out the wizard performs —
which is admin-gated and rate-limited there; the two paths MUST NOT diverge into
an unthrottled register/schema-sprawl amplification vector.

#### Scenario: Repeated template instantiation is throttled
- **WHEN** a caller invokes `createFromTemplate` more times than the rate limit
  permits within the window
- **THEN** the excess requests are rejected by the rate-limit middleware

#### Scenario: An unauthorized caller cannot instantiate a template
- **WHEN** a caller who does not meet the wizard's authorization gate invokes
  `createFromTemplate`
- **THEN** the request is rejected with 403, consistent with the wizard