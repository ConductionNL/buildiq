# Tasks — harden-rules-authz-and-audit-parity

Acceptance criteria and quality reminders are plain-text bullets (not checkboxes)
so the checkbox count stays within the supervisor cap.

## 1. M1 — rules-engine authorization + honest docs

- [x] 1.1 Resolve rule-sets / decision-tables / test-cases through `searchObjects` (RBAC + org scoped) instead of raw `findAll` in `RuleEngineService` (`loadBundle`/`findMany`) and `RulesController` (`findRuleSet`/`findTestCases`).
- [x] 1.2 Correct the false "isolation enforced / foreign slug → 404 / no IDOR" docblocks in `RuleEngineService::loadBundle`, `RulesController`, and `appinfo/routes.php` to state the real boundary (organisation + schema-RBAC scope).
- [x] 1.3 Tests: an out-of-scope rule-set slug resolves to not-found on `evaluate`/`schema`/`test-all`; an in-scope rule-set still evaluates unchanged.
  - Verifies business-rules-engine spec scenarios.

## 2. L8 — export download authorization field mismatch

- [x] 2.1 In `ExportsController::isAuthorisedForJob`, read `requestedBy ?? @self.owner` (the persisted identity) instead of the never-written `submittedBy`.
- [x] 2.2 Test: the persisted `requestedBy` user may download; a stranger is denied (404-masked).

## 3. L2 — MCP admin-bypass audit-trail parity

- [x] 3.1 Inject `AuditTrailMapper` into `AbstractToolHandler` and record the admin bypass via `recordAdminBypass` in the MCP bypass branch (fail-soft on write failure); apply the same to `CopilotService`.
- [x] 3.2 Test: an MCP admin bypass writes an audit-trail entry.

## 4. L9 — insights role check honours group principals

- [x] 4.1 Make `ApplicationInsightsService::callerInAnyRole` handle `group:` principals by reusing `PermissionResolver::matchesCaller`.
- [x] 4.2 Test: a group-only-authorized caller is granted insights; an unmatched caller is denied.

## 5. L4 — icon endpoint documentation honesty

- [x] 5.1 Correct the `IconController` docblock / `Cache-Control` rationale to state session-only enforcement (or add the claimed per-app viewer check).

## 6. Wrap-up

- [x] 6.1 Register this change in the five capability specs (`business-rules-engine`, `openbuild-rbac`, `openbuild-exporter`, `application-insights`, `app-icon-management`): add to the `OpenSpec changes` list and set status `in-progress`.
- [x] 6.2 Run the Hydra mechanical gates + PHP lint (phpcs/psalm/phpstan) + PHP unit suite; confirm green.
  - No OR schema/seed changes (per design.md); L8 reads the existing `requestedBy` field.
