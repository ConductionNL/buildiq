## ADDED Requirements

### Requirement: MCP admin-bypass MUST be recorded to the OpenRegister audit trail

An MCP tool handler that grants an admin bypass SHALL write the bypass to the
OpenRegister per-object audit trail (via `recordAdminBypass` / `AuditTrailMapper`),
at parity with the HTTP path (REQ-OBRBAC-007), rather than logging only to PSR.
`AbstractToolHandler` MUST receive `AuditTrailMapper` and record the bypass in its
admin-bypass branch; a write failure MUST fail soft (logged, non-aborting), never
suppressing the operation.

#### Scenario: An MCP admin bypass appears in the audit trail
- **WHEN** an administrator invokes an MCP write tool that takes the admin-bypass
  branch
- **THEN** an audit-trail entry is created for the bypass, visible in the
  permission-history panel — not only a PSR log line

#### Scenario: An audit-write failure does not abort the operation
- **WHEN** the audit-trail write fails
- **THEN** the operation still completes and the failure is logged at critical
