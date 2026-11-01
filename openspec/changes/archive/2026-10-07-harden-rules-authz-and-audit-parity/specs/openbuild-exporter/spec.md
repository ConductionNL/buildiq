## ADDED Requirements

### Requirement: Export download authorization MUST read the persisted requester identity

`ExportsController::isAuthorisedForJob` SHALL authorize a download against the
requester identity actually persisted on the export-job record (`requestedBy`,
falling back to `@self.owner`), not against a `submittedBy` key that is never
written. The check MUST NOT rely on the accidental `@self.owner` fallback as its
only path.

#### Scenario: The persisted requester may download their export
- **WHEN** the user recorded as the job's `requestedBy` requests the download
- **THEN** the download is authorized

#### Scenario: A stranger is denied
- **WHEN** a different, non-admin user requests the same job's download
- **THEN** the request is denied (404-masked), not silently allowed via a fallback
