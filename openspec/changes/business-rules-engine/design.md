## Context

Process automation in citizen-dev stacks requires rules that evolve separately from code. Traditional approaches embed rules in templates (brittle, version-blind) or push all logic to the backend (silos analysts from developers). The business rules engine adopts the OMG DMN 1.4 standard and proven condition-action patterns, both field-tested in BPMN workflows and low-code platforms.

The two paradigms serve complementary use-cases:

- **Decision tables** excel at multi-factor mapping (e.g., "If applicant age ≥ 18 AND monthly income ≥ €2,000 AND credit score ≥ 600, grant loan"). A table with three input columns and one output column captures this decision logic in one place, auto-documents the business rules, and scales to dozens of conditions without nested IF-trees.
- **Condition-action chains** excel at sequential triggers (e.g., "If invoice total > €5,000, send notification to manager, then start approval workflow"). Order matters; conditions cascade; a rule may have multiple actions.

OpenBuilt already ships the openregister-integration backbone (multitenancy, versioning, audit trail, webhooks). The rules engine plugs into that, treating RuleSets as first-class OpenRegister objects, versioned and deployed per-tenant without app redeploy.

## Goals / Non-Goals

**Goals:**

- Enable process analysts to design and deploy decision tables and condition-action chains via visual UI.
- Decouple business rules from code — rules change without code review or app restart.
- Provide a test sandbox where analysts validate rules with sample data before activation.
- Maintain full audit trail — every rule execution logged with input, output, triggered rules, duration.
- Support hot-reload — new rule versions active within 30 seconds, no downtime.
- Enforce per-tenant scoping — each tenant's rules isolated and independent.
- Honour AVG art. 22 — every automated decision is logged with full input/output trace for explainability.

**Non-Goals:**

- **Visual BPMN editor** — workflows are n8n's job; this engine feeds data *into* workflows, not orchestrates them.
- **Machine-learning rule discovery** — all rules are human-authored.
- **Complex nested conditions** — DMN's orthogonal tables handle cross-factor logic; if a rule requires more than ~15 conditions, refactor into a sub-rule-set.
- **Real-time streaming rule evaluation** — the engine is request-response; event-driven workflows are n8n's domain.

## Decisions

### Decision 1 — Schema-declarative: RuleSet lifecycle via x-openregister-lifecycle

The `RuleSet` schema SHALL declare its status lifecycle (`draft → test → active → archived`) as `x-openregister-lifecycle` in the register file, NOT as a PHP service class.

**Rationale**: ADR-031 §Schema-declarative. The lifecycle engine auto-provides audit trail, state-transition guards, and CloudEvents. Removing a custom `RuleSetLifecycleService` class saves code and inherits the OR engine's maturity.

**Alternatives considered**:
- *Custom PHP RuleSetService.transitionStatus()* — rejected: duplicates OR's lifecycle engine, splits the source of truth.
- *No lifecycle enforcement, free-form status* — rejected: rules in test should be immutable; without guards, a status can be accidentally downgraded.

### Decision 2 — FEEL subset: ranges, lists, and comparisons only

The condition and decision-table cell-expression parser SHALL support:

- Comparisons: `==`, `!=`, `<`, `>`, `<=`, `>=`
- Ranges: `5..10` (inclusive)
- Lists: `in (1, 2, 3)`
- Logical: `and`, `or`, `not`
- Null checks: `is null`, `is not null`
- Arithmetic: `+`, `-`, `*`, `/` (for numeric calcs in expressions)

It SHALL NOT support:

- String interpolation (`${foo}`)
- Function calls (`now()`, `length(foo)`)
- Custom user-defined functions
- External library calls

**Rationale**: Simplicity and auditability. A 20-line FEEL parser is reviewable and testable. Complex logic belongs in n8n workflows or backend services. Expressions should be declarable by analysts without JavaScript knowledge.

**Alternatives considered**:
- *Full FEEL 1.3* — rejected: requires a full expression parser (500+ LOC), and scope creep (custom functions blur the boundary between rule-as-data and rule-as-code).
- *No expressions, only enum/lookup* — rejected: many legitimate rules reference thresholds (age > 18, amount < 1000) which require comparisons.

### Decision 3 — Hit policies: default `first`, with warnings for overlaps

DecisionTable's `hitPolicy` field defaults to `first` (top-to-bottom rule evaluation, stop at first match). The visual editor SHALL warn when rules overlap or rows are unreachable.

**Rationale**: `first` is the safest default (deterministic, no surprises). Alternatives (`unique`, `priority`, `collect`) require explicit opt-in and careful design. Warnings help analysts spot unintended overlaps early.

**Alternatives considered**:
- *Always use `unique` (no overlaps allowed)* — rejected: complex policies (tiered discounts) legitimately have overlaps; the engine should support them with explicit rules.
- *No hit-policy concept, just evaluate all and merge* — rejected: rule order matters, and the spec may require "first match wins" semantics.

### Decision 4 — Action execution: synchronous only, with optional `continue` flag

A matched ConditionActionRule executes its actions synchronously in declaration order. If an action fails, the rule stops (unless `continue: true`). There is no background-job queue for rule actions.

**Rationale**: Synchronous execution keeps the request-response cycle simple and predictable. If an action is long-running (e.g., calling an external API), wrap it in an n8n workflow and invoke via the `start-workflow` action type. The `continue` flag lets analysts chain multiple actions (validate → notify → route) without branching.

**Alternatives considered**:
- *Async action queue* — rejected: adds complexity (eventual consistency, retry logic, dead-letter handling) without clear benefit; users who need async should use n8n.
- *No continue flag, one rule one action* — rejected: legitimate chains like "validate, then notify, then route" require multiple actions.

### Decision 5 — Versioning: semver on activation

When a RuleSet transitions from `test` to `active`, the system increments the semver automatically:
- Patch bump: rule added or condition/action changed within a DecisionTable/ConditionActionRule
- Minor bump: new input/output column in DecisionTable
- Major bump: breaking change (column removed, schema incompatibility)

**Rationale**: Decouples version numbering from deployment frequency. Rules in `draft` and `test` do not increment version (ephemeral). Once activated, the version is immutable — consumer apps pinning to `RuleSet v1.2.3` get predictable behavior even if v1.2.4 is deployed later.

**Alternatives considered**:
- *Manual version bumping* — rejected: puts burden on analyst; easy to forget or get wrong.
- *Hash-based versioning* — rejected: semver is familiar to Nextcloud apps and external integrations.

### Decision 6 — Hot-reload: refresh cached RuleSet within 30 seconds

When a new RuleSet version activates, the runtime SHALL evict the cached version within 30 seconds. In-flight rule evaluations continue to use the old version (they hold a reference). New requests use the new version.

**Rationale**: Bounded latency (no indefinite staleness) without forcing a restart. 30 seconds is aggressive enough to be perceived as "live", conservative enough to avoid thundering-herd cache-invalidation storms.

**Alternatives considered**:
- *Synchronous cache invalidation* — rejected: would require broadcast across multiple app instances; complex in distributed Nextcloud setups.
- *On-demand refresh (lazy load)* — rejected: first request after deployment pays a heavy cost; better to refresh proactively.

### Decision 7 — Audit trail: RuleExecutionLog captures input, output, duration, and error

Every rule evaluation produces a `RuleExecutionLog` record capturing:
- Input payload (with PII masking options)
- Output result
- List of triggered rule IDs
- Execution duration (milliseconds)
- Any errors
- User context (if rule eval was triggered from a form, the user ID of the form submitter)

**Rationale**: AVG art. 22 (automated decisions) requires explainability. Regulators and auditors must be able to trace "why was the applicant rejected?" → "because rule-set 'loan-eligibility' v1.2.0 triggered rule #5 which returned deny". The log is the source of truth.

**Alternatives considered**:
- *No detailed log, summary only* — rejected: would not satisfy audit requirements.
- *Log only failures* — rejected: success cases also need to be auditable (especially high-stakes decisions like approvals).

### Decision 8 — Per-tenant deployment: RuleSets are tenant-scoped by default

A RuleSet created by TenantA is visible only to TenantA. If an admin needs a shared rule (e.g., a compliance rule that applies across all tenants), mark it `isGlobal: true`, which makes it read-only for tenants but creates a per-tenant `override-rule` that can modify the outcome.

**Rationale**: Multi-tenant isolation. Tenants should not be able to see each other's rules, let alone execute them. Global rules are for platform-level compliance; tenants can layer tenant-specific logic on top via overrides.

**Alternatives considered**:
- *All rules are global by default* — rejected: tenants want confidentiality (competitors, privacy).
- *Shared rules but immutable* — rejected: still allows one tenant to infer another's policies.

### Decision 9 — Test sandbox: TestCases are versioned with the RuleSet

A TestCase is tied to a specific RuleSet version. When a RuleSet is promoted from `test` to `active` and version increments, the TestCases are copied forward (not automatically re-run on the new version — the analyst chooses when to test the new version).

**Rationale**: Prevents "test suite drift". If tests are tied to the RuleSet object (not the version), deploying a new version would silently break old tests. Copying them forward makes the lifecycle explicit.

**Alternatives considered**:
- *One TestCase suite shared across all versions* — rejected: test expectations change with rules; a test that passes on v1 may fail on v2 (intentionally).
- *No explicit TestCases, ad-hoc testing via the UI* — rejected: analysts need a repeatable, auditable way to validate before go-live.

### Decision 10 — Runtime API: two modes, synchronous + dry-run

The `POST /api/rules/{ruleSetSlug}/evaluate` endpoint accepts:
- `payload` (required): the input data
- `dryRun` (optional, default false): if true, evaluates the rules but does NOT execute side-effect actions (notifications, workflows)
- `version` (optional, default latest active): pin to a specific RuleSet version (for testing)

**Rationale**: Dry-run lets integrators preview rule outcomes without side-effects. Version pinning lets integrators test a new version before adopting it.

**Alternatives considered**:
- *Single mode, effects always executed* — rejected: breaks testing workflows; you cannot safely test before deploying.
- *Separate `dry-run` endpoint* — rejected: doubles the API surface; one endpoint with a flag is cleaner.

## Declarative-vs-imperative decision

Per ADR-031, this spec ships most of the business-rules engine as **declarative** (schemas in the register, lifecycle via `x-openregister-lifecycle`, notifications via `x-openregister-notifications`). The rule **evaluation logic** (RuleEngineService, DecisionTableEvaluator, ConditionActionExecutor) is imperative PHP code — this is the documented exception per ADR-031 §Exceptions, because:

1. FEEL-expression parsing and DMN hit-policy semantics are domain-specific algorithms that the schema engine cannot yet express.
2. File generation, placeholder resolution, and external API calls (the classic ADR-031 exceptions) are not applicable here, but the principle is the same: when the schema engine cannot express a behaviour, a service is justified.

The register file uses `x-openregister-lifecycle` (RuleSet status transitions) and `x-openregister-notifications` (rule-change alerts), avoiding the need for custom `RuleSetLifecycleService` or `RuleNotificationService` classes.

## Reuse Analysis

This spec leverages existing OpenRegister capabilities:

- **ObjectService** (`openregister/lib/Service/ObjectService.php`) — create, read, update, delete RuleSet, DecisionTable, ConditionActionRule, RuleExecutionLog, TestCase objects.
- **SchemaService** (`openregister/lib/Service/SchemaService.php`) — resolve schema definitions for RuleSet and dependent schemas.
- **Lifecycle engine** (`x-openregister-lifecycle` via ConfigurationService) — manage RuleSet status transitions.
- **Notifications engine** (`x-openregister-notifications` via NotificationService) — dispatch rule-change alerts.
- **Multitenancy** — RuleSet objects are automatically tenant-scoped via OR's multitenancy layer.
- **Audit trail** — OR's AuditTrailService logs all RuleSet mutations automatically.

No overlap with existing services. The spec does NOT reimplement OR's CRUD, REST, lifecycle, or notifications — it extends them.

## Seed Data

Three RuleSet examples with realistic Dutch business scenarios:

### RuleSet 1: Loan Eligibility (DecisionTable)

```json
{
  "@self": {
    "register": "openbuilt-rules",
    "schema": "RuleSet",
    "slug": "loan-eligibility"
  },
  "naam": "Loan Eligibility Decision",
  "beschrijving": "Multi-factor lending decision based on age, income, and credit score",
  "versie": "1.0.0",
  "status": "active",
  "eigenaarApp": "openbuilt-lending",
  "geactiveerdOp": "2026-05-22T10:00:00Z",
  "ingangsdatum": "2026-05-22",
  "einddatum": null
}
```

Companion DecisionTable:

```json
{
  "@self": {
    "register": "openbuilt-rules",
    "schema": "DecisionTable",
    "slug": "loan-eligibility-table"
  },
  "ruleSetId": "loan-eligibility",
  "hitPolicy": "first",
  "inputColumns": [
    { "naam": "applicantAge", "type": "integer", "expressiePad": "applicant.age" },
    { "naam": "monthlyIncome", "type": "number", "expressiePad": "applicant.monthlyIncome" },
    { "naam": "creditScore", "type": "integer", "expressiePad": "applicant.creditScore" }
  ],
  "outputColumns": [
    { "naam": "decision", "type": "string", "defaultwaarde": "deny" },
    { "naam": "reason", "type": "string", "defaultwaarde": "Eligibility criteria not met" }
  ],
  "regels": [
    {
      "condities": {
        "applicantAge": ">=18",
        "monthlyIncome": ">=2000",
        "creditScore": ">=600"
      },
      "waardes": {
        "decision": "approve",
        "reason": "All criteria met"
      },
      "label": "Standard approval"
    },
    {
      "condities": {
        "applicantAge": ">=18",
        "monthlyIncome": ">=1500",
        "creditScore": ">=500"
      },
      "waardes": {
        "decision": "review",
        "reason": "Manual review required"
      },
      "label": "Marginal case"
    },
    {
      "condities": {},
      "waardes": {
        "decision": "deny",
        "reason": "Eligibility criteria not met"
      },
      "label": "Default deny"
    }
  ]
}
```

### RuleSet 2: Invoice Routing (ConditionActionRule)

```json
{
  "@self": {
    "register": "openbuilt-rules",
    "schema": "RuleSet",
    "slug": "invoice-routing"
  },
  "naam": "Invoice Routing Rules",
  "beschrijving": "Route invoices to approvers based on amount and department",
  "versie": "1.0.0",
  "status": "active",
  "eigenaarApp": "openbuilt-invoicing",
  "geactiveerdOp": "2026-05-22T11:00:00Z",
  "ingangsdatum": "2026-05-22",
  "einddatum": null
}
```

Companion ConditionActionRule:

```json
{
  "@self": {
    "register": "openbuilt-rules",
    "schema": "ConditionActionRule",
    "slug": "invoice-routing-rule-1"
  },
  "ruleSetId": "invoice-routing",
  "naam": "High-value invoice escalation",
  "prioriteit": 100,
  "salience": 10,
  "conditie": "invoice.total > 5000 and invoice.department == 'procurement'",
  "acties": [
    {
      "type": "send-notification",
      "parameters": {
        "recipient": "finance-director",
        "template": "high-value-invoice",
        "variables": { "amount": "@invoice.total", "vendor": "@invoice.vendor" }
      }
    },
    {
      "type": "start-workflow",
      "parameters": {
        "workflowId": "invoice-approval-chain",
        "payload": "@invoice"
      }
    }
  ],
  "actief": true
}
```

### RuleSet 3: Complaint Escalation (ConditionActionRule)

```json
{
  "@self": {
    "register": "openbuilt-rules",
    "schema": "RuleSet",
    "slug": "complaint-escalation"
  },
  "naam": "Complaint Escalation Rules",
  "beschrijving": "Auto-escalate complaints based on severity and age",
  "versie": "2.1.0",
  "status": "active",
  "eigenaarApp": "openbuilt-complaints",
  "geactiveerdOp": "2026-05-15T14:30:00Z",
  "ingangsdatum": "2026-05-15",
  "einddatum": null
}
```

Companion ConditionActionRule:

```json
{
  "@self": {
    "register": "openbuilt-rules",
    "schema": "ConditionActionRule",
    "slug": "complaint-escalation-rule-1"
  },
  "ruleSetId": "complaint-escalation",
  "naam": "Critical complaint auto-escalate",
  "prioriteit": 200,
  "salience": 0,
  "conditie": "complaint.severity == 'critical' or (complaint.createdAt < now() - 7 days and complaint.status == 'open')",
  "acties": [
    {
      "type": "send-notification",
      "parameters": {
        "recipient": "complaints-manager",
        "template": "escalated-complaint",
        "variables": { "id": "@complaint.id", "severity": "@complaint.severity" }
      }
    },
    {
      "type": "set-veld",
      "parameters": {
        "veld": "escalatedAt",
        "waarde": "@now"
      }
    }
  ],
  "actief": true
}
```

### Seed TestCases

```json
{
  "@self": {
    "register": "openbuilt-rules",
    "schema": "TestCase",
    "slug": "loan-eligibility-test-1"
  },
  "ruleSetId": "loan-eligibility",
  "naam": "Eligible applicant",
  "beschrijving": "A 25-year-old with €3,000 monthly income and 650 credit score should be approved",
  "inputPayload": {
    "applicant": {
      "age": 25,
      "monthlyIncome": 3000,
      "creditScore": 650
    }
  },
  "verwachtResultaat": {
    "decision": "approve",
    "reason": "All criteria met"
  },
  "laatsteTestResultaat": "geslaagd",
  "laatsteTestOutput": {
    "decision": "approve",
    "reason": "All criteria met"
  }
}
```
