## ADDED Requirements

### Requirement: Icon endpoint documentation MUST match enforced behaviour

The `IconController` docblock and `Cache-Control` rationale SHALL describe the
authorization the endpoints actually enforce. Because the icon endpoints enforce
session-only access (no per-app viewer check), the documentation MUST NOT claim
the responses are "personalised to the caller's app access"; either the claimed
per-app viewer check is added, or the rationale is corrected to state session-only
enforcement.

#### Scenario: The documented rationale is accurate
- **WHEN** a developer reads the `IconController` docblock / `Cache-Control` note
- **THEN** it states the actual enforcement (session-only) and does not assert an
  access-scoped personalisation the code does not perform
