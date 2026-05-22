---
kind: code
depends_on: []
chain:
  - business-rules-engine
---

## Why

OpenBuilt's page-designer and form-builder enable citizen-developers to create custom applications without coding, but they lack a structured way to model business rules separate from UI logic. Currently, validation rules, workflow routing logic, automatic calculations, and conditional field visibility are scattered across:

- Hardcoded IF-conditions in Vue templates
- Validation logic embedded in form definitions
- Ad-hoc backend checks in multiple services
- Undocumented business assumptions baked into forms

This creates silos: process analysts cannot see or modify rules without involving developers; rules cannot be tested independently; deployment of rule changes requires app redeploy; audit trails for rule execution are absent; and compliance with AVG art. 22 (automated decision-making transparency) is difficult.

The business rules engine ships a declarative, visually-editable rule framework that decouples business logic from implementation, enabling process analysts to design, test, and deploy rules without code. Two dominant paradigms cover the vast majority of real-world use-cases:

1. **Decision tables** (DMN 1.4) for multi-condition mapping: rate calculations, discount tiers, eligibility checks, routing rules based on multiple factors.
2. **Condition-action chains** for sequential workflow decisions: a field value triggers a validation, which triggers a notification, which starts a workflow.

The engine provides: (a) visual editors for both paradigms, (b) per-tenant deployment without app redeploy via OpenRegister, (c) a test sandbox for validation before go-live, (d) full audit trail with versioning, (e) hot-reload within 30 seconds, and (f) synchronous + asynchronous runtime APIs for consumer apps.

## What Changes

- **NEW** Five OpenRegister schemas in `lib/Settings/openbuilt-rules_register.json`:
  - `RuleSet` — container with versioning, status (draft/test/active/archived), ownership, activation dates
  - `DecisionTable` — DMN-based multi-condition mapper with input/output columns, hit policies, cell expressions
  - `ConditionActionRule` — condition-driven action chains with priority/salience and action sequencing
  - `RuleExecutionLog` — audit trail of every rule evaluation with input, output, triggered rules, duration
  - `TestCase` — sample payloads and expected results for sandbox validation

- **NEW** PHP rule-engine runtime `lib/Service/RuleEngineService.php` — the single evaluation surface that loads a RuleSet by slug, evaluates DecisionTables and ConditionActionRules against an input payload, logs the execution, and returns the result synchronously (default 500ms timeout).

- **NEW** PHP decision-table evaluator `lib/Service/DecisionTableEvaluator.php` — implements DMN hit policies (unique, first, priority, any, collect, rule-order), cell expression parsing (FEEL subset: ranges, lists, comparisons), and overlap/completeness detection.

- **NEW** PHP condition-action executor `lib/Service/ConditionActionExecutor.php` — evaluates conditions in priority/salience order, executes actions (set-field, start-workflow, send-notification, call-rule-set), and respects the `continue` flag for multi-rule chains.

- **NEW** PHP background job `lib/BackgroundJob/RuleExecutionLogCleanup.php` (TimedJob, 7-day interval) — archives and purges old execution logs per retention policies.

- **NEW** Frontend visual editor `src/dialogs/DecisionTableEditor.vue` — grid-based UI for input/output columns and rules, with inline FEEL-expression validation, live hit-policy preview, and overlap/completeness warnings.

- **NEW** Frontend visual editor `src/dialogs/ConditionActionRuleEditor.vue` — form-based UI for condition + action sequencing, priority/salience picker, action type selector (set-field / start-workflow / send-notification / call-rule-set).

- **NEW** Frontend test sandbox `src/views/RuleSetTestSandbox.vue` — loads TestCases, runs them against the active RuleSet, displays pass/fail per case, and diffs actual vs. expected output.

- **NEW** Frontend RuleSet dashboard `src/views/RuleSetsPage.vue` — lists all RuleSets, status indicators, version selector, activation/archival actions, test-suite status, and export to JSON.

- **NEW** PHP controller `lib/Controller/RulesController.php` with three endpoints:
  - `POST /api/rules/{ruleSetSlug}/evaluate` — synchronous evaluation with optional dry-run mode
  - `GET /api/rules/{ruleSetSlug}/schema` — fetch the RuleSet schema + current version for UI binding
  - `POST /api/rules/{ruleSetSlug}/test-all` — run all TestCases async and return results

- **NEW** PHP versioning service `lib/Service/RuleSetVersioningService.php` — increments semver on activation, archives previous version, maintains full version history in OpenRegister.

- **NEW** PHP notification service hook via `x-openregister-notifications` — notifies app owners when their RuleSet is updated or when a rule-execution error rate exceeds a threshold.

- **NEW** PHP impact-analysis service `lib/Service/RuleImpactAnalysisService.php` — tracks which apps have called a RuleSet in the past 30 days and notifies owners of rule changes.

### Capabilities

#### New Capabilities

- `openbuilt-rule-engine`: The complete rule evaluation framework. Owns the five schemas (RuleSet, DecisionTable, ConditionActionRule, RuleExecutionLog, TestCase) via `lib/Settings/openbuilt-rules_register.json`, the runtime evaluation engine (RuleEngineService, DecisionTableEvaluator, ConditionActionExecutor), the visual editors (DecisionTableEditor, ConditionActionRuleEditor, RuleSetTestSandbox), the RulesController with the `/evaluate` and `/test-all` endpoints, the versioning service, the impact-analysis service, and the cleanup job. Honours ADR-031 (schemas are declarative; evaluation logic is PHP code, documented as exception per ADR-031 §Exceptions).

#### Modified Capabilities

- `openbuilt-page-designer`: form-builder's field validation and conditional-visibility bindings now resolve through the runtime API to a RuleSet. No API breakage — the designer continues to support inline expressions; RuleSet reference is optional.

- `openbuilt-runtime`: when evaluating form-validation or routing logic at runtime, checks whether a RuleSet is referenced; if present, calls the rule-engine API. Fallback to inline expressions if no RuleSet.

## Impact

- **New code**: ~2,500 LOC across RuleEngineService, DecisionTableEvaluator, ConditionActionExecutor, RuleSetVersioningService, RuleImpactAnalysisService, RulesController, the visual editors (DecisionTableEditor.vue ~300 LOC, ConditionActionRuleEditor.vue ~250 LOC, RuleSetTestSandbox.vue ~350 LOC, RuleSetsPage.vue ~400 LOC), and the cleanup job.

- **Schema patch** — `lib/Settings/openbuilt-rules_register.json` declares the five schemas (RuleSet, DecisionTable, ConditionActionRule, RuleExecutionLog, TestCase) with `x-openregister-lifecycle` on RuleSet for status transitions and `x-openregister-notifications` for rule-change alerts.

- **External dependency** — none. FEEL-expression parsing uses a lightweight subset (range operators `..`, list membership `in`, comparison operators) implemented in PHP.

- **OpenRegister** — uses OR's REST API, lifecycle engine, notifications, and multitenancy scoping; no OR changes required.

- **No breaking changes** — purely additive. Existing forms and workflows continue to work unchanged; rule-set binding is optional.

- **Foundational ADRs honoured** — ADR-031 (RuleSet schemas declarative via register; execution logic is code, documented exception); ADR-001 (all data in OpenRegister, no custom Entity/Mapper); ADR-005 (rule-execution API enforces per-tenant isolation and audit logging).

## Open Questions

- **OQ-1**: Should rule-execution dry-run also support side-effect simulation (e.g., preview a notification without sending)? Defer to v2.
- **OQ-2**: Should the FEEL parser support custom user-defined functions or external library calls? Defer to v2.
