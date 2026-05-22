## ADDED Requirements

### Requirement: REQ-BRE-001 RuleSet schema declaration with lifecycle

The system SHALL declare a `RuleSet` schema in `lib/Settings/openbuilt-rules_register.json` (OpenAPI 3.0.0) carrying properties: `uuid` (UUID-format, required, auto-generated), `naam` (string, required, slug-format), `beschrijving` (string, optional), `versie` (semver-pattern, required, auto-managed on activation), `status` (enum `draft|test|active|archived`, default `draft`, required), `eigenaarApp` (string, required, openbuilt-app-slug that owns this RuleSet), `geactiveerdOp` (date-time, nullable), `gedeactiveerdOp` (date-time, nullable), `ingangsdatum` (date, nullable), `einddatum` (date, nullable). The schema SHALL declare `x-openregister-lifecycle` with states `draft → test → active → archived` (no backward transitions except draft ↔ test). No custom PHP `RuleSetLifecycleService` class SHALL be created — the lifecycle is handled entirely by OpenRegister's lifecycle engine.

#### Scenario: Valid RuleSet created in draft state

- **GIVEN** an analyst supplies `naam: "loan-eligibility"`, `beschrijving: "..."`, and no `status` field
- **WHEN** the analyst POSTs the RuleSet to the OR REST endpoint
- **THEN** OR creates the object with `status: "draft"`, a fresh `uuid`, `versie: "0.0.0"`, and `geactiveerdOp: null`
- **AND** the OR audit trail records the creation event

#### Scenario: Disallowed lifecycle transition rejected

- **GIVEN** a RuleSet with `status: "active"`
- **WHEN** the system attempts to transition the RuleSet to `draft` (backward transition)
- **THEN** OR returns a 4xx error indicating the transition is not allowed
- **AND** no state change occurs

#### Scenario: Test state transition allowed

- **GIVEN** a RuleSet with `status: "draft"` and at least one TestCase passes
- **WHEN** the analyst transitions the RuleSet to `test`
- **THEN** OR allows the transition and sets `status: "test"`
- **AND** the audit trail records the transition with timestamp

---

### Requirement: REQ-BRE-002 DecisionTable schema for DMN-based multi-condition rules

The system SHALL declare a `DecisionTable` schema in the same register carrying properties: `uuid` (UUID), `ruleSetId` (relation to RuleSet, required), `hitPolicy` (enum `unique|first|priority|any|collect|rule-order`, default `first`, required), `inputColumns` (array of {naam, type [string|number|integer|boolean], expressiePad}, required, min 1), `outputColumns` (array of {naam, type, defaultwaarde}, required, min 1), `regels` (array of rule objects, each containing condities [map of inputColumn→condition-expression] and waardes [map of outputColumn→value], required, min 1).

#### Scenario: Grid editor creates a DecisionTable with three input columns

- **GIVEN** an analyst opens the DecisionTableEditor for a RuleSet in draft status
- **WHEN** the analyst adds three input columns (age, income, creditScore) and one output column (decision)
- **THEN** the editor accepts the definition and displays a grid with three input columns + one output column
- **AND** clicking "Save" POSTs the DecisionTable to OR, which assigns a fresh `uuid`

#### Scenario: FEEL expression validation on cell edit

- **GIVEN** the DecisionTableEditor shows an input cell for the `age` column
- **WHEN** the analyst types an invalid expression (`age = 18` instead of `age == 18`)
- **THEN** the editor shows a red error indicator: "Syntax error: unknown operator `=`, expected `==`"
- **AND** the analyst cannot save the rule until the expression is fixed

#### Scenario: Hit-policy preview shows overlaps

- **GIVEN** a DecisionTable with three rules:
  - Rule 1: `age >= 18 and income >= 2000` → `decision: approve`
  - Rule 2: `age >= 21 and income >= 1500` → `decision: fast-track`
  - Rule 3: default → `decision: review`
- **WHEN** the editor is set to `hitPolicy: "first"` and the analyst enters an applicant aged 25 with income 3000
- **THEN** the preview shows "Rule 1 matches (highest priority)" with output `approve`
- **AND** the editor warns: "Rules 1 and 2 overlap for applicant age 21–80 with income 1500–2000"

---

### Requirement: REQ-BRE-003 ConditionActionRule schema for sequential workflow decisions

The system SHALL declare a `ConditionActionRule` schema carrying properties: `uuid` (UUID), `ruleSetId` (relation, required), `naam` (string, required), `prioriteit` (integer, default 0, required), `salience` (integer, default 0, required), `conditie` (string, FEEL-subset expression, required), `acties` (array of {type [set-veld|start-workflow|send-notification|call-rule-set], parameters}, required, min 1), `actief` (boolean, default true). The engine SHALL evaluate rules in descending order of `prioriteit`, then descending `salience`. It SHALL execute actions in declaration order. If an action fails, the chain stops (unless the action includes a `continueOnError: true` flag).

#### Scenario: Multiple rules with priority ordering

- **GIVEN** a RuleSet with three ConditionActionRules:
  - Rule A: `prioriteit: 200`, condition `invoice.total > 5000`, action `send-notification to finance-director`
  - Rule B: `prioriteit: 100`, condition `invoice.total > 1000`, action `send-notification to manager`
  - Rule C: `prioriteit: 100`, condition `true`, action `log invoice`
- **WHEN** the engine evaluates a payload with `{ invoice: { total: 6000 } }`
- **THEN** the engine fires Rule A (highest priority), executes its action, then evaluates Rule B (next priority, salience determines order), then Rule C
- **AND** all three rules' actions execute because there is no explicit stop condition (unless design.md Decision 4 specifies otherwise)

#### Scenario: Action chain with notification + workflow

- **GIVEN** a ConditionActionRule with condition `complaint.severity == "critical"` and actions:
  - Action 1: `send-notification` to complaints-manager
  - Action 2: `start-workflow` id `escalation-chain`
- **WHEN** the condition matches
- **THEN** both actions execute in order: notification sent first, workflow started second
- **AND** if the notification fails, the workflow is NOT started (stop on first error)

---

### Requirement: REQ-BRE-004 Test-case driven sandbox validation

The system SHALL declare a `TestCase` schema carrying properties: `uuid` (UUID), `ruleSetId` (relation, required), `naam` (string, required), `beschrijving` (string, optional), `inputPayload` (JSON object, required), `verwachtResultaat` (JSON object, required), `laatsteTestResultaat` (enum `niet-uitgevoerd|geslaagd|gefaald`, default `niet-uitgevoerd`), `laatsteTestOutput` (JSON, optional). A "Run all tests" action in the frontend SHALL iterate all TestCases for a RuleSet, execute each against the current active version, compare `actuele output` with `verwachtResultaat`, and display pass/fail per case.

#### Scenario: TestCase passes

- **GIVEN** a TestCase with:
  - `inputPayload: { applicant: { age: 25, income: 3000, creditScore: 650 } }`
  - `verwachtResultaat: { decision: "approve" }`
- **WHEN** the analyst clicks "Run all tests" on the RuleSet
- **THEN** the engine evaluates the DecisionTable/ConditionActionRule against the payload and obtains `{ decision: "approve" }`
- **AND** the UI shows "✓ TestCase 1 passed"
- **AND** `laatsteTestResultaat: "geslaagd"` and `laatsteTestOutput: { decision: "approve" }` are recorded

#### Scenario: TestCase fails and diff is shown

- **GIVEN** the same TestCase but the DecisionTable was modified such that output is now `{ decision: "review" }`
- **WHEN** the analyst runs the tests
- **THEN** the UI shows "✗ TestCase 1 failed" with a red diff:
  ```
  Expected: { decision: "approve" }
  Actual:   { decision: "review" }
  ```

#### Scenario: Promotion to active blocked if tests fail

- **GIVEN** a RuleSet in `test` status with one failing TestCase
- **WHEN** the analyst clicks "Activate version"
- **THEN** the system shows an error: "Cannot activate: 1 test case(s) failing. Fix the test cases first."
- **AND** the RuleSet remains in `test` status

---

### Requirement: REQ-BRE-005 Versioning on activation with semver auto-increment

When a RuleSet transitions from `test` to `active`, the system SHALL automatically increment the `versie` semver: patch version for rule additions/modifications, minor for schema/column changes, major for breaking changes (column removal). The previous active version SHALL be archived, and the new version SHALL have `geactiveerdOp: now()`.

#### Scenario: Patch-version increment on rule change

- **GIVEN** an active RuleSet with `versie: "1.0.0"`
- **WHEN** the analyst modifies a DecisionTable row and transitions from `test` to `active`
- **THEN** the system increments `versie: "1.0.1"` and sets `geactiveerdOp: "2026-05-22T10:15:00Z"`
- **AND** the previous version `1.0.0` is archived (still queryable by consumers, but no longer the default active version)

#### Scenario: Minor-version increment on column addition

- **GIVEN** a DecisionTable with two input columns
- **WHEN** the analyst adds a third input column and promotes to active
- **THEN** the system increments `versie` to minor (e.g., `1.0.0` → `1.1.0`)

---

### Requirement: REQ-BRE-006 Runtime evaluation API with synchronous execution

The system SHALL provide a PHP runtime service `RuleEngineService` and a controller endpoint `POST /api/rules/{ruleSetSlug}/evaluate` accepting a JSON payload and optional `dryRun: true` flag. The service SHALL load the active RuleSet by slug, evaluate DecisionTables and ConditionActionRules against the payload, execute actions (unless `dryRun: true`), log the execution in `RuleExecutionLog`, and return JSON containing `result` (the output), `geraaktRegels` (array of triggered rule IDs), `executieDuur` (milliseconds), and `fouten` (if any).

#### Scenario: Synchronous evaluation with output

- **GIVEN** an active RuleSet `loan-eligibility` v1.0.0
- **WHEN** an integrator POSTs to `/api/rules/loan-eligibility/evaluate` with payload `{ applicant: { age: 25, income: 3000, creditScore: 650 } }`
- **THEN** the service returns HTTP 200 with:
  ```json
  {
    "result": { "decision": "approve", "reason": "All criteria met" },
    "geraaktRegels": ["rule-1"],
    "executieDuur": 12,
    "fouten": []
  }
  ```
- **AND** a RuleExecutionLog record is created with input, output, rule IDs, and duration

#### Scenario: Dry-run mode suppresses actions

- **GIVEN** the same RuleSet with a ConditionActionRule that sends a notification
- **WHEN** the integrator POSTs with `dryRun: true`
- **THEN** the service evaluates the rule and returns the outcome, but does NOT send the notification
- **AND** the log entry includes `dryRun: true`

#### Scenario: Evaluation timeout error

- **GIVEN** a malformed expression that causes infinite recursion in the evaluator
- **WHEN** the evaluation exceeds 500ms (design default)
- **THEN** the service returns HTTP 408 (Request Timeout) with message "Rule evaluation timed out"
- **AND** no action is executed

---

### Requirement: REQ-BRE-007 Per-tenant isolation and multitenancy

RuleSet objects SHALL be automatically scoped to the tenant via OpenRegister's multitenancy layer. A RuleSet created by TenantA is not visible to TenantB, and cross-tenant rule-evaluation API calls are forbidden (return 403). If a rule is marked `isGlobal: true`, it is read-only for all tenants; tenants may create an `OverrideRule` that modifies the outcome.

#### Scenario: Tenant isolation

- **GIVEN** TenantA creates a RuleSet `proprietary-pricing`
- **WHEN** TenantB queries `/api/rules/proprietary-pricing/evaluate` (or tries to read the RuleSet)
- **THEN** the API returns 404 (not found) — not 403 (forbidden) to avoid information leakage
- **AND** TenantB cannot see `proprietary-pricing` in their RuleSet list

#### Scenario: Global rule with tenant override

- **GIVEN** the platform creates a global rule `gdpr-compliance` with `isGlobal: true`
- **WHEN** TenantA tries to edit it
- **THEN** they receive an error "This rule is global (read-only)" but can create an OverrideRule that modifies the outcome

---

### Requirement: REQ-BRE-008 Hot-reload within 30 seconds

When a RuleSet version transitions to `active`, the runtime SHALL evict its cached entry and reload within 30 seconds. In-flight rule evaluations (those that began before the cache refresh) continue to use the old version. New requests after the refresh use the new version.

#### Scenario: New version active after cache refresh

- **GIVEN** an active RuleSet v1.0.0 in the runtime cache
- **WHEN** the analyst activates a new version v1.0.1
- **THEN** within 30 seconds, the runtime cache is invalidated and reloaded
- **AND** a subsequent request for `/api/rules/{slug}/evaluate` uses v1.0.1
- **AND** no application restart or downtime occurs

---

### Requirement: REQ-BRE-009 Audit trail for every rule execution

Every call to the runtime-evaluation API SHALL produce a `RuleExecutionLog` record carrying: `ruleSetId`, `ruleSetVersie`, `tijdstip`, `triggerContext` (e.g., "form-submit:loan-application"), `inputPayload` (with optional PII masking), `outputResultaat`, `geraaktRegels` (array of rule IDs that matched), `executieDuurMs`, `fouten` (error messages if any), `userId` (authenticated user who triggered the evaluation, if applicable).

#### Scenario: Log creation on evaluation

- **GIVEN** an evaluation of `/api/rules/loan-eligibility/evaluate`
- **WHEN** the RuleEngineService completes the evaluation
- **THEN** a RuleExecutionLog is persisted with all required fields
- **AND** the log is queryable via OR REST: `GET /api/rules-execution-logs?ruleSetId=loan-eligibility`

#### Scenario: Audit trail queryable for compliance

- **GIVEN** a regulator requests audit of all loan decisions from March 2026
- **WHEN** the auditor queries `RuleExecutionLog` filtered by date and RuleSet ID
- **THEN** every logged evaluation (input, output, triggered rules) is visible
- **AND** no execution log can be deleted — only archived after retention period

---

### Requirement: REQ-BRE-010 Impact analysis: notify apps when rules change

When a RuleSet version is activated or archived, the system SHALL identify apps that have called this RuleSet via the runtime API in the past 30 days and send a notification to those apps' owners (via openregister-notifications or email) summarizing the change.

#### Scenario: Notification on rule update

- **GIVEN** a RuleSet `invoice-routing` that has been called 50 times by the invoicing app in the past month
- **WHEN** a new version is activated
- **THEN** the invoicing app's owner receives a notification: "Rule set 'invoice-routing' updated from v1.0.0 to v1.0.1. 50 calls in past 30 days."
- **AND** the notification includes a link to view the RuleExecutionLog

---

### Requirement: REQ-BRE-011 FEEL-subset expression parsing and validation

The system SHALL implement a FEEL-subset parser supporting: comparisons (`==`, `!=`, `<`, `>`, `<=`, `>=`), ranges (`5..10`), list membership (`in (1, 2, 3)`), logical operators (`and`, `or`, `not`), null checks (`is null`, `is not null`), and arithmetic (`+`, `-`, `*`, `/`). The parser SHALL raise a descriptive exception if an unsupported operator or syntax is encountered. Re-parsing an already-resolved string SHALL be a no-op.

#### Scenario: Valid FEEL expressions parsed

- **GIVEN** expressions: `age >= 18`, `status in ("active", "pending")`, `amount >= 1000 and amount <= 5000`
- **WHEN** the parser processes these
- **THEN** they parse successfully and produce an evaluable condition object

#### Scenario: Invalid syntax rejected

- **GIVEN** expression `age = 18` (single `=` instead of `==`)
- **WHEN** the parser processes it
- **THEN** it raises an exception: "Syntax error at position 5: unknown operator `=`, expected `==`"

---

### Requirement: REQ-BRE-012 Visual editor feedback: overlap and completeness detection

The DecisionTableEditor SHALL display warnings when: (a) two rules overlap (their condition sets intersect), (b) a rule row is unreachable (shadowed by an earlier rule), or (c) the decision table does not cover all possible input combinations (gaps in coverage). These warnings SHALL be non-blocking (do not prevent save) but highly visible.

#### Scenario: Overlap warning

- **GIVEN** a table with two rules:
  - Rule 1: `age >= 18 and income >= 2000`
  - Rule 2: `age >= 21 and income >= 1500`
- **WHEN** the analyst sets `hitPolicy: "unique"` (which requires no overlaps)
- **THEN** the editor shows a yellow warning: "Rules 1 and 2 overlap for age 21–150 with income 1500–2000"

#### Scenario: Unreachable rule detection

- **GIVEN** three rules with `hitPolicy: "first"`:
  - Rule 1: `true` (matches everything)
  - Rule 2: `age >= 18` (can never be reached, shadowed by Rule 1)
- **WHEN** the analyst views the table
- **THEN** the editor highlights Rule 2 with a gray background and the message "This rule is unreachable (shadowed by Rule 1)"

---

### Requirement: REQ-BRE-013 Cleanup job for aged execution logs

A background job `RuleExecutionLogCleanup` (TimedJob, 7-day interval) SHALL archive and purge RuleExecutionLog records older than the configured retention policy (default 90 days). Archived logs are moved to a separate table or marked for deletion; they are no longer queryable via the normal REST API. Purged logs cannot be recovered.

#### Scenario: Old logs archived

- **GIVEN** RuleExecutionLog records from March 2026, and a 90-day retention policy
- **WHEN** the cleanup job runs in late June 2026
- **THEN** March logs are archived (still auditable if needed for compliance)
- **AND** logs older than 90 days are purged (deleted)

