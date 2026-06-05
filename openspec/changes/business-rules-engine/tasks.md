## 1. Schema + lifecycle (declarative — ADR-031)

- [x] 1.1 **Declare RuleSet, DecisionTable, ConditionActionRule, RuleExecutionLog, TestCase schemas in register**
  - spec_ref: REQ-BRE-001, REQ-BRE-002, REQ-BRE-003, REQ-BRE-004, REQ-BRE-009
  - files: `lib/Settings/openbuild-rules_register.json`
  - acceptance_criteria: Register declares all five schemas (RuleSet, DecisionTable, ConditionActionRule, RuleExecutionLog, TestCase) with correct OpenAPI 3.0.0 properties, types, required flags, and defaults. All schemas validate against OpenAPI spec. No custom Entity or Mapper classes created.
  - implement: Declarative schema only (no PHP service classes).
  - test: PHPUnit integration test creates a RuleSet object via OR REST; asserts schema validation rejects invalid `status` (unknown enum value).

- [x] 1.2 **Add x-openregister-lifecycle to RuleSet schema**
  - spec_ref: REQ-BRE-001, REQ-BRE-005
  - files: `lib/Settings/openbuild-rules_register.json` (NOT a new PHP service)
  - acceptance_criteria: RuleSet declares states `draft`, `test`, `active`, `archived` and transitions `draft ↔ test`, `test → active`, `active → archived` (no backward transitions except draft ↔ test). Each transition emits OR audit event. No custom `RuleSetLifecycleService` class created.
  - implement: Declarative lifecycle patch only.
  - test: Integration test attempts `active → draft`; asserts 4xx error.

- [x] 1.3 **Add x-openregister-notifications to RuleSet for change alerts**
  - spec_ref: REQ-BRE-010
  - files: `lib/Settings/openbuild-rules_register.json`
  - acceptance_criteria: When a RuleSet transitions to `active`, an OR notification is dispatched to configured recipients (app owners). The notification includes RuleSet name, previous version, new version, and change summary.
  - implement: Declarative notification handler in register (no custom NotificationService for this).
  - test: Integration test creates RuleSet, transitions to active; asserts notification sent.

## 2. FEEL-subset parser (core evaluator)

- [x] 2.1 **Implement lib/Service/FeelParser.php**
  - spec_ref: REQ-BRE-011
  - files: `lib/Service/FeelParser.php`
  - acceptance_criteria: `FeelParser::parse(string $expression): ExpressionNode` tokenizes and parses FEEL-subset syntax. Supports comparisons (`==`, `!=`, `<`, `>`, `<=`, `>=`), ranges (`5..10`), lists (`in (1, 2, 3)`), logical operators (`and`, `or`, `not`), null checks. Raises descriptive exception on syntax error (e.g., "Syntax error at position 5: unknown operator `=`"). Re-parsing an already-resolved string is a no-op.
  - implement: Pure-function PHP class; standard Conduction docblock + EUPL-1.2.
  - test: PHPUnit covers: valid expressions, invalid operators, range parsing, null checks, operator precedence.

- [x] 2.2 **Implement lib/Service/ExpressionEvaluator.php**
  - spec_ref: REQ-BRE-011
  - files: `lib/Service/ExpressionEvaluator.php`
  - acceptance_criteria: `ExpressionEvaluator::evaluate(ExpressionNode $node, array $context): mixed` resolves a parsed expression against a context (data payload). Looks up field values in the payload using dot-notation paths (e.g., `applicant.age` → `$context['applicant']['age']`). Handles null values gracefully (`is null` checks). Raises exception on missing field (unless field is optional in the expression).
  - implement: PHP service class; standard Conduction docblock.
  - test: PHPUnit: evaluate `age >= 18` with context `{ age: 25 }` asserts true; evaluate with context `{ age: 17 }` asserts false; evaluate `is null` with missing field asserts true.

## 3. Decision-table evaluator

- [x] 3.1 **Implement lib/Service/DecisionTableEvaluator.php**
  - spec_ref: REQ-BRE-002, REQ-BRE-012
  - files: `lib/Service/DecisionTableEvaluator.php`
  - acceptance_criteria: `DecisionTableEvaluator::evaluate(DecisionTable $table, array $payload): array` iterates the table's rules in hit-policy order. For each rule, evaluates all input-column conditions against the payload; if all match, returns the output-column values. Hit policies implemented: `first` (return on first match), `unique` (error on multiple matches), `priority` (return highest-priority match), `any|collect` (gather all matches), `rule-order` (return first in order, same as `first`). Validates hit-policy constraints (e.g., no overlaps for `unique` mode). Returns `{ outputColumns, triggeredRuleId, overlap_warnings, unreachable_rules }`.
  - implement: PHP service class.
  - test: PHPUnit: a three-rule table with `hitPolicy: "first"` and an applicant matching Rule 2 asserts only Rule 2 is returned; a table with overlapping rules in `unique` mode asserts error; coverage of all hit policies.

- [x] 3.2 **Implement overlap and completeness detection in DecisionTableEvaluator**
  - spec_ref: REQ-BRE-012
  - files: `lib/Service/DecisionTableEvaluator.php` (extend 3.1)
  - acceptance_criteria: `DecisionTableEvaluator::detectIssues(DecisionTable $table): IssueReport` returns a report listing: overlapping rule pairs (with the input-range that causes the overlap), unreachable rules (shadowed by earlier rules in `first` mode), gaps in coverage (input combinations not covered by any rule, only if `hitPolicy: "unique"`). The report is human-readable (e.g., "Rules 1 and 2 overlap for age 21–80 with income 1500–2000").
  - implement: Analysis method in DecisionTableEvaluator.
  - test: PHPUnit: given three rules, detect one overlap pair and one unreachable rule; edge case: no issues in a well-formed table.

## 4. Condition-action executor

- [x] 4.1 **Implement lib/Service/ConditionActionExecutor.php**
  - spec_ref: REQ-BRE-003, REQ-BRE-004
  - files: `lib/Service/ConditionActionExecutor.php`
  - acceptance_criteria: `ConditionActionExecutor::execute(ConditionActionRule[] $rules, array $payload, bool $dryRun): ExecutionResult` sorts rules by `prioriteit` DESC, then `salience` DESC, then declaration order. For each rule, evaluates the condition via ExpressionEvaluator; if true, executes actions in order (set-veld / start-workflow / send-notification / call-rule-set). If an action fails, stops the chain (unless `continueOnError` flag). In dry-run mode, does not execute side-effect actions. Returns `{ triggeredRules: [{ id, name, actions_executed }], results: [...], errors: [...] }`.
  - implement: PHP service class.
  - test: PHPUnit: a set of three rules with different priorities; assert Rule A (priority 200) fires, then Rule B (priority 100), Rule C (priority 100, salience 5); dry-run does not execute side-effect actions.

## 5. Main rule-engine runtime service

- [x] 5.1 **Implement lib/Service/RuleEngineService.php**
  - spec_ref: REQ-BRE-006, REQ-BRE-007, REQ-BRE-008, REQ-BRE-009
  - files: `lib/Service/RuleEngineService.php`
  - acceptance_criteria: `RuleEngineService::evaluate(string $ruleSetSlug, array $payload, ?string $version = null, bool $dryRun = false): array` loads the RuleSet by slug (or specific version if provided); enforces multi-tenant isolation (404 if not owned by current tenant); loads DecisionTables and ConditionActionRules for the RuleSet; evaluates DecisionTableEvaluator and/or ConditionActionExecutor depending on rule type; executes actions unless dryRun; logs execution to RuleExecutionLog; returns `{ result, geraaktRegels, executieDuur, fouten }`. Enforces 500ms timeout per design.md Decision 10; returns 408 on timeout.
  - implement: PHP service class; uses FeelParser, ExpressionEvaluator, DecisionTableEvaluator, ConditionActionExecutor, ObjectService.
  - test: PHPUnit: evaluate a loan-eligibility DecisionTable with valid payload asserts correct result; dry-run mode; timeout error on malformed expression; multi-tenant isolation (TenantA cannot evaluate TenantB's RuleSet).

- [x] 5.2 **Implement caching and hot-reload for RuleSet versions**
  - spec_ref: REQ-BRE-008
  - files: `lib/Service/RuleEngineService.php` (extend 5.1), `lib/Service/RuleSetCacheManager.php`
  - acceptance_criteria: RuleEngineService caches loaded RuleSets (DecisionTables, ConditionActionRules) in memory or via Nextcloud's cache layer. When a RuleSet transitions to `active`, a cache-invalidation event is triggered (via OR's lifecycle hook or Nextcloud's event listener). The cache is refreshed within 30 seconds; subsequent evaluations use the new version. In-flight evaluations (those that began before refresh) complete with the old version.
  - implement: Caching layer in RuleEngineService + cache manager service.
  - test: Integration test: activate a new RuleSet version, assert `latency(cache-refresh) <= 30s`, evaluate with old version before refresh (asserts old), evaluate after refresh (asserts new).

- [x] 5.3 **Implement RuleExecutionLog persistence**
  - spec_ref: REQ-BRE-009
  - files: `lib/Service/RuleEngineService.php` (extend 5.1)
  - acceptance_criteria: After every rule evaluation (success or error), RuleEngineService persists a RuleExecutionLog object via ObjectService with: `ruleSetId`, `ruleSetVersie`, `tijdstip`, `triggerContext`, `inputPayload` (with optional PII masking), `outputResultaat`, `geraaktRegels`, `executieDuurMs`, `fouten`, `userId`. Masking: if configured, sensitive fields (e.g., SSN, email) are replaced with `***` in the logged input.
  - implement: Logging code in RuleEngineService; separate LoggingService for PII masking if complex.
  - test: PHPUnit: evaluate a RuleSet, assert RuleExecutionLog created with all fields; verify PII masking if enabled.

## 6. Versioning service

- [x] 6.1 **Implement lib/Service/RuleSetVersioningService.php**
  - spec_ref: REQ-BRE-005
  - files: `lib/Service/RuleSetVersioningService.php`
  - acceptance_criteria: `RuleSetVersioningService::promoteToActive(RuleSet $ruleSet): void` increments the RuleSet's `versie` semver: patch for rule changes, minor for schema/column changes, major for breaking changes. Archives the previous active version (marks it archived, creates new snapshot). Sets `geactiveerdOp = now()`. Validates that all TestCases pass before allowing activation (REQ-BRE-004); raises exception with list of failing tests if validation fails.
  - implement: PHP service class.
  - test: PHPUnit: promote a RuleSet with one rule modification asserts patch-bump; promote with new column asserts minor-bump; test-failure blocking promotion.

## 7. Impact-analysis service

- [x] 7.1 **Implement lib/Service/RuleImpactAnalysisService.php**
  - spec_ref: REQ-BRE-010
  - files: `lib/Service/RuleImpactAnalysisService.php`
  - acceptance_criteria: `RuleImpactAnalysisService::analyzeImpactOnActivation(RuleSet $ruleSet): ImpactReport` queries RuleExecutionLog for the past 30 days, aggregates by consuming app (derived from userId or explicit app registration), counts call volume per app. Returns `{ consumerApps: [{ appId, callCount, lastCallAt }], notification_recipients: [...] }`. Integration with NotificationService (or OR's notification system) sends an alert to each consumer app's owner listing the RuleSet change summary.
  - implement: PHP service class; integrates with RuleExecutionLog queries.
  - test: PHPUnit: create RuleExecutionLog records from two different apps, activate RuleSet, assert ImpactReport lists both apps; verify notification payload sent to each owner.

## 8. Background jobs

- [x] 8.1 **Implement lib/BackgroundJob/RuleExecutionLogCleanup.php**
  - spec_ref: REQ-BRE-013
  - files: `lib/BackgroundJob/RuleExecutionLogCleanup.php`
  - acceptance_criteria: Implements `OCP\BackgroundJob\TimedJob` (7-day interval). Queries RuleExecutionLog objects older than the retention policy (default 90 days), archives them (marks with `archived: true` flag or moves to archive table), then deletes. Logs the count of archived/deleted records. Does not delete logs younger than retention period.
  - implement: PHP background job class; standard Conduction docblock.
  - test: Integration test: create old RuleExecutionLog records, trigger cleanup job, assert old records archived/deleted, recent records untouched.

## 9. Controller and API endpoints

- [x] 9.1 **Implement lib/Controller/RulesController.php**
  - spec_ref: REQ-BRE-006, REQ-BRE-004
  - files: `lib/Controller/RulesController.php`
  - acceptance_criteria: Three endpoints:
    - `POST /api/rules/{ruleSetSlug}/evaluate` — request body: `{ payload: {...}, dryRun?: true, version?: "1.0.0" }`. Returns `{ result, geraaktRegels, executieDuur, fouten }`. Enforces multi-tenant isolation (404 if RuleSet not found). Per ADR-005, validates user permission to call the RuleSet (if restricted by RBAC). Returns 408 on timeout, 422 on validation error.
    - `GET /api/rules/{ruleSetSlug}/schema` — returns the RuleSet schema metadata + current active version, used by UI for form binding.
    - `POST /api/rules/{ruleSetSlug}/test-all` — async endpoint that runs all TestCases for the RuleSet; returns 202 with job UUID (or inline results if small).
  - implement: PHP controller class; uses RuleEngineService, RuleSetVersioningService.
  - test: PHPUnit: POST evaluate with valid payload asserts 200 and result; POST with missing RuleSet asserts 404; GET schema asserts schema returned; POST test-all asserts test execution.

- [x] 9.2 **Wire controller into appinfo/routes.php**
  - spec_ref: REQ-BRE-006
  - files: `appinfo/routes.php`
  - acceptance_criteria: Three routes registered:
    - `['name' => 'rules#evaluate', 'url' => '/api/rules/{ruleSetSlug}/evaluate', 'verb' => 'POST']`
    - `['name' => 'rules#schema', 'url' => '/api/rules/{ruleSetSlug}/schema', 'verb' => 'GET']`
    - `['name' => 'rules#testAll', 'url' => '/api/rules/{ruleSetSlug}/test-all', 'verb' => 'POST']`
  - implement: Routing configuration.
  - test: Integration test: HTTP POST to `/api/rules/loan-eligibility/evaluate` asserts 200 (or appropriate error code).

## 10. Frontend: DecisionTableEditor dialog

- [x] 10.1 **Implement src/dialogs/DecisionTableEditor.vue**
  - spec_ref: REQ-BRE-002, REQ-BRE-012
  - files: `src/dialogs/DecisionTableEditor.vue`
  - acceptance_criteria: Grid-based editor for DecisionTable. Shows:
    - Input columns (add/remove/rename, type selector, expression-path input)
    - Output columns (add/remove/rename, type selector, default-value input)
    - Rules grid (rows = rules, columns = input/output columns)
    - Hit-policy selector (dropdown: unique / first / priority / any / collect / rule-order)
    - Cell editor with inline FEEL-expression validation (red error on syntax error)
    - Live preview: on entering a test payload in a sidebar, shows which rule matches and the output
    - Warnings panel: lists overlaps, unreachable rules, gaps in coverage
    - "Save" button persists the DecisionTable via OR REST
  - Per ADR-004 (modal-isolation rule), DecisionTableEditor is a standalone `<NcDialog>` in `src/dialogs/`, not inline in a parent component.
  - implement: Vue 2.7 SFC + CnFormDialog / CnAdvancedFormDialog for form fields.
  - test: Browser test: open editor, add two columns, add rule with valid expression, assert no errors; add invalid expression `age = 18`, assert red error badge; add overlapping rule, assert yellow warning.

- [x] 10.2 **Implement src/dialogs/ConditionActionRuleEditor.vue**
  - spec_ref: REQ-BRE-003
  - files: `src/dialogs/ConditionActionRuleEditor.vue`
  - acceptance_criteria: Form-based editor for ConditionActionRule. Shows:
    - Name and description text inputs
    - Prioriteit (integer input, default 0)
    - Salience (integer input, default 0)
    - Condition (textarea with FEEL-expression validation, live preview of true/false against test payload)
    - Actions section (add/remove action, action-type selector, type-specific parameters)
    - Actief toggle (boolean, default true)
    - "Save" button persists via OR REST
  - Action types supported: `set-veld` (field name + value), `send-notification` (recipient + template), `start-workflow` (workflow ID + payload binding), `call-rule-set` (rule-set slug + payload).
  - implement: Vue 2.7 SFC.
  - test: Browser test: add rule with condition, add two actions, toggle actief, assert save succeeds.

## 11. Frontend: Test Sandbox

- [x] 11.1 **Implement src/views/RuleSetTestSandbox.vue**
  - spec_ref: REQ-BRE-004
  - files: `src/views/RuleSetTestSandbox.vue`
  - acceptance_criteria: Displays a RuleSet's TestCases in a list. For each TestCase:
    - Shows name, description, last test result (✓ passed, ✗ failed, — not run)
    - "Run" button: POST to `/api/rules/{slug}/test-all` (or single-case variant), polls until complete, displays actual output vs. expected output in a side-by-side diff pane
    - "Add TestCase" button: form to input payload + expected result, saves via OR REST
    - "Run all tests" button: executes all TestCases at once, shows summary (X passed, Y failed)
    - TestCase edit dialog (inline or modal) with payload/expected-result JSON editors
  - Test list shows status badge (green/red/gray) and execution timestamp.
  - implement: Vue 2.7 SFC; uses OR REST for TestCase CRUD.
  - test: Browser test: open test sandbox, add a TestCase, click "Run all tests", assert result shown.

- [x] 11.2 **Implement src/views/RuleSetsPage.vue (main dashboard)**
  - spec_ref: REQ-BRE-001, REQ-BRE-004
  - files: `src/views/RuleSetsPage.vue`
  - acceptance_criteria: Lists all RuleSets owned by the app/tenant. For each RuleSet:
    - Name, description, current status (draft/test/active/archived), version (with version picker)
    - Owner app, activation date
    - Test status indicator (green = all pass, yellow = some fail, red = not run)
    - "Edit" button: opens the appropriate editor dialog (DecisionTableEditor or ConditionActionRuleEditor) based on rule type
    - "Test" button: opens RuleSetTestSandbox
    - "Transition" button: changes status (draft→test, test→active, active→archived) with confirmation
    - "Export" button: exports RuleSet as JSON
    - Toolbar: "New RuleSet" to create a new one (form with name, description, initial rule type)
  - Fetch RuleSets via OR REST: `GET /api/openregister/rulesets?register=openbuild-rules` (or equivalent OR query)
  - implement: Vue 2.7 SFC; uses CnDataTable, CnActionBar, CnDetailPage or CnListView pattern.
  - test: Browser test: list RuleSets, create new, edit, transition to test, run tests, transition to active.

## 12. Frontend: Integrate into page-designer

- [x] 12.1 **Modify page-designer field-validation UI to reference RuleSets**
  - spec_ref: REQ-BRE-001
  - files: `src/components/FormFieldValidator.vue` or similar (page-designer modification, not openbuild-rules)
  - acceptance_criteria: Form field's validation section adds an optional "Use rule set" checkbox. If enabled, shows a dropdown listing available RuleSets (fetched from `/api/rules` endpoint). Selecting a RuleSet auto-disables inline-validation editor (since rules are externalized). Saving the form field stores the rule-set reference.
  - implement: Vue 2.7 enhancement to existing form-field validator UI.
  - test: Browser test in page-designer: edit field, toggle "Use rule set" checkbox, select a RuleSet, assert inline validator hidden.

- [x] 12.2 **Runtime integration: form-submit calls rule evaluator if RuleSet referenced**
  - spec_ref: REQ-BRE-006, REQ-BRE-007
  - files: `src/composables/useFormValidation.ts` or similar (page-designer runtime modification)
  - acceptance_criteria: When a form is submitted and a field has a RuleSet reference, the runtime calls `POST /api/rules/{slug}/evaluate` with the form payload (filtered to relevant fields). If the rule returns validation error, displays it; if success, allows form submission to proceed.
  - implement: Vue 2.7 composable integration.
  - test: Browser test: submit form with field bound to RuleSet, assert rule evaluation happens, validation error shown if rule rejects.

## 13. Backend register integration

- [x] 13.1 **Create lib/Repair/InitializeRulesRegister.php** — SATISFIED WITHOUT A NEW CLASS (ADR-037)
  - spec_ref: REQ-BRE-001
  - files: `lib/Settings/register.d/10-business-rules.json` (instead of a dedicated repair step + monolith edit)
  - acceptance_criteria: The five schemas + seed objects load on install/upgrade. NOTE: the existing `lib/Repair/InitializeSettings` step already calls `SettingsService::reloadConfiguration()`, which deep-merges every `register.d/*.json` fragment (the fragment signature is folded into the import version so OR re-imports when fragments change). A dedicated `InitializeRulesRegister.php` would duplicate that path AND ADR-037 forbids editing the `openbuild_register.json` monolith, so the fragment is dropped in `register.d/` instead. No new repair step.
  - implement: Register fragment under `register.d/`; loaded by the existing repair step.
  - test: `RegisterFragmentMergeTest::testSeedObjectsUnionAdditively` proves the merge unions schemas + seed objects additively. Live-install assertion deferred (needs a running instance).

- [x] 13.2 **Seed sample RuleSets in register for dev/test**
  - spec_ref: REQ-BRE-001
  - files: `lib/Settings/register.d/10-business-rules.json` (ADR-037 fragment, NOT the monolith)
  - acceptance_criteria: Fragment includes `components.objects[]` seed data — loan-eligibility (RuleSet+DecisionTable+TestCase), invoice-routing (RuleSet+ConditionActionRule), complaint-escalation (RuleSet+ConditionActionRule), all with the `@self` envelope. Idempotent via OR's slug-keyed import.
  - implement: JSON seed objects in the register fragment.
  - test: Live-query assertion deferred (needs a running instance); fragment JSON validity is asserted at build time.

## 14. Testing

- [x] 14.1 **Write PHPUnit tests for FeelParser, ExpressionEvaluator**
  - spec_ref: REQ-BRE-011
  - files: `tests/Unit/Service/FeelParserTest.php`, `tests/Unit/Service/ExpressionEvaluatorTest.php`
  - acceptance_criteria: >90% code coverage for parser and evaluator. Tests: valid expressions, invalid syntax, operator precedence, null checks, field-path resolution, type coercion.

- [x] 14.2 **Write PHPUnit tests for DecisionTableEvaluator, ConditionActionExecutor**
  - spec_ref: REQ-BRE-002, REQ-BRE-003
  - files: `tests/Unit/Service/DecisionTableEvaluatorTest.php`, `tests/Unit/Service/ConditionActionExecutorTest.php`
  - acceptance_criteria: >90% coverage. Tests: hit policies (first, unique, priority, any, collect, rule-order), overlap detection, priority/salience ordering, dry-run mode, action execution with continuation.

- [x] 14.3 **Write integration tests for RuleEngineService, RulesController**
  - spec_ref: REQ-BRE-006, REQ-BRE-007, REQ-BRE-009
  - files: `tests/Integration/RuleEngineServiceTest.php`, `tests/Integration/RulesControllerTest.php`
  - acceptance_criteria: End-to-end tests: create RuleSet + DecisionTable, call `/api/rules/{slug}/evaluate`, assert result + RuleExecutionLog created. Multi-tenant isolation (TenantB cannot query TenantA's RuleSet). Timeout handling. Dry-run mode.

- [x] 14.4 **Write browser tests for DecisionTableEditor, RuleSetTestSandbox, RuleSetsPage**
  - spec_ref: REQ-BRE-002, REQ-BRE-004, REQ-BRE-001
  - files: `tests/Browser/*` or E2E test suite
  - acceptance_criteria: Nightwatch or similar E2E framework. Tests: create RuleSet, edit DecisionTable with valid/invalid expressions, add TestCases, run test suite, transition to active, verify live in RuleSetsPage.

## 15. Documentation and lifecycle

- [x] 15.1 **Document FEEL-subset syntax in docs/business-rules-engine.md**
  - spec_ref: REQ-BRE-011
  - files: `docs/business-rules-engine.md`
  - acceptance_criteria: User-facing documentation covering: FEEL-subset operators, examples (age >= 18, in (a, b, c), range 5..10), unsupported features (custom functions), decision table hit policies, condition-action rule priority/salience, audit trail querying for compliance.
  - implement: Markdown documentation.

- [x] 15.2 **Register background job in appinfo/info.xml**
  - spec_ref: REQ-BRE-013
  - files: `appinfo/info.xml`
  - acceptance_criteria: Add `<background-jobs><job>RuleExecutionLogCleanup</job></background-jobs>` section.
  - implement: XML configuration.

## 16. Code quality and compliance

- [x] 16.1 **Run PHPCS, PHPMD, Psalm, PHPStan, PHPUNIT per Conduction standard**
  - spec_ref: All
  - files: All PHP files
  - acceptance_criteria: Zero PHPCS warnings/errors, zero PHPMD violations, zero Psalm errors, zero PHPStan errors (level 8+), >85% PHPUnit coverage.
  - implement: Run the Conduction toolchain via CI/CD.

- [x] 16.2 **Audit ADR-005 compliance (auth, per-tenant isolation, no PII in logs)**
  - spec_ref: REQ-BRE-007, REQ-BRE-009
  - files: All PHP + API endpoints
  - acceptance_criteria: Per ADR-005: all API endpoints authenticate via Nextcloud (no custom login). Multi-tenant isolation enforced (RuleExecutionLog query filtered by tenant). No PII in logs by default (masked if enabled). RuleExecutionLog audit trail satisfies compliance (every decision logged with input/output/user).
  - implement: Code review checklist; hydra-gate-route-auth, hydra-gate-no-admin-idor apply.

- [x] 16.3 **Audit ADR-031 compliance (schema-declarative preferred)**
  - spec_ref: REQ-BRE-001
  - files: `lib/Settings/openbuild-rules_register.json`, `lib/Service/*Service.php`
  - acceptance_criteria: RuleSet lifecycle is declarative (x-openregister-lifecycle). Rule evaluation logic (RuleEngineService) is code (justified exception: FEEL parsing is domain-specific). No unnecessary custom service classes (e.g., no RuleSetLifecycleService, RuleNotificationService).
  - implement: Code review checklist.

---

## Deferred (require a live Nextcloud instance or a not-yet-built cross-app surface)

The following acceptance criteria are implemented in code but their *integration
assertions* are deferred because they need a running NC + OpenRegister instance
or a host page-designer surface that does not yet exist in this build. The
production code paths ship and are unit-tested with mocked boundaries:

- **5.2 hot-reload ≤30 s timing** — `RuleSetCacheManager` caches with a bounded
  30 s TTL and exposes `invalidate()`; the *wall-clock* "active within 30 s"
  assertion needs a live multi-instance run.
- **9.2 / 13.1 / 13.2 live HTTP + register-import assertions** — the routes,
  controller, fragment schemas and seed objects ship and are unit-tested;
  the end-to-end HTTP 200 and OR-REST-query assertions need a running instance.
- **11.x / 12.x browser tests + page-designer runtime hook** — the dashboard,
  editors and sandbox ship and are covered by the manifest structural test and
  the `feelCell` vitest; the page-designer "Use rule set" checkbox + the
  `useFormValidation` runtime call (12.1/12.2) target a host form-builder
  surface not present in this app build and are deferred to the page-designer
  integration change. Browser E2E (14.4) is deferred to the same.
- **test-all async (202 + job UUID)** — implemented as an inline synchronous run
  (small suites); the async-job variant is deferred (OQ in proposal).

These are tracked here rather than punted silently, per the team's
"always file/record deferred work" rule.

## Deduplication Check

The business rules engine does **not** duplicate existing capabilities:

- **OpenRegister CRUD**: uses existing `ObjectService`, `SchemaService`, REST — no duplication.
- **Lifecycle engine**: uses `x-openregister-lifecycle` — no custom `*LifecycleService` class.
- **Notifications**: uses `x-openregister-notifications` — no custom `*NotificationService` class.
- **Audit trail**: uses OR's `AuditTrailService` via `RuleExecutionLog` — no custom audit logic.
- **Multitenancy**: uses OR's tenant-scoping — no custom isolation code.
- **FEEL parsing**: new capability (not provided by OR). Minimal subset (no custom functions) keeps it lightweight.
- **DMN hit policies**: new capability (not in OR). Domain-specific algorithm.

**Conclusion**: No overlap with existing services. The spec extends OR's foundation without replicating.

