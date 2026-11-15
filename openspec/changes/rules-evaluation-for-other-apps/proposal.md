---
kind: code
depends_on: []
---

# Proposal: rules-evaluation-for-other-apps

## Why

Portaliq's `form-flow-repeating-groups-calculations-and-decisions` (merged in portaliq #1387) lets a form step name a business rule: "The server asks the rule engine at the step change and writes the outcome into a read-only field, or opens the step the outcome names." Its design: "`PortalFormDecision` (new) calls the rule through the rule engine's server API with the named inputs, at the step change and again on submit. The outcome is stored on the submission. The browser never receives the table." It names buildiq's business rules engine as the owner (`buildiq/openspec/specs/business-rules-engine`).

Portaliq row `int-decision-table-logic` (decision 104). Open Formulieren 4.0.1 evaluates DMN from a logic rule (`src/openforms/dmn/contrib/camunda/plugin.py:56`).

What buildiq has:

- `RuleEngineService::evaluate()` and `POST /api/rules/{ruleSetSlug}/evaluate` (REQ-BRE-006), with a `preview` mode that writes no log and runs no action (`forms-live-values-and-checks`, REQ-BQLV-004).
- Rule-set resolution through an RBAC-scoped OpenRegister query, so a caller outside the rule set's scope gets not-found ("Rule-set resolution MUST be authorization-scoped").

A portal visitor has no Nextcloud user: an anonymous or DigiD resident cannot pass that RBAC scope, and portaliq should not make an HTTP call to its own instance. There is no way for another app's server to evaluate a rule set it is allowed to use.

## What changes

- **A rule set says which apps may call it.** `RuleSet.callableBy`: a list of app ids. Empty by default; nothing changes for existing rule sets.
- **A typed command `RuleEvaluationRequestedEvent`.** The calling app, the rule set slug and the inputs in; the outputs and the triggered rule ids, or a refusal (`unknown-rule-set`, `not-allowed`, `invalid-input`, `timeout`, `evaluation-failed`) out. It never throws to the caller.
- **Pure evaluation.** A call through the command always runs as `preview` for actions (no condition-action side effect fires) but writes one execution log line with the calling app, so an auditor can see which portal decision used which rule set version.
- **Inputs are checked** against the rule set's declared inputs (`GET /api/rules/{slug}/schema`), undeclared inputs are dropped, and the payload size guard applies.
- **The designer can list callable rule sets.** `GET /api/rules?callableBy={app}` for a signed-in user lists slug, title, inputs and outputs, for portaliq's form designer to pick from.
- **The rule set editor** gets a "Mag worden aangeroepen door" field.

## Rows this closes

| matrix | row id | row name | what is missing |
|-|-|-|-|
| portaliq | int-decision-table-logic | Let a decision table decide a value or the next step in a form. | a server-side call into the rules engine that portaliq can make |

## Out of scope

- Writing the outcome to a field or picking the next step: portaliq.
- Moving evaluation to OpenRegister's shared DMN evaluator: done by the archived `buildiq-consumes-shared-dmn`; this change calls `RuleEngineService`, which uses it.

## Cross-app

- portaliq `form-flow-repeating-groups-calculations-and-decisions` dispatches the command from `POST /api/intake/{route}/steps/{step}/decide` and on submit.

## Impact

- Specs: new capability `rules-evaluation-for-other-apps`.
- New: `lib/Event/RuleEvaluationRequestedEvent.php`, `lib/Listener/RuleEvaluationListener.php`.
- Changed: `lib/Settings/register.d/10-business-rules.json` (`callableBy` on `rule-set`, `callerApp` on `rule-execution-log`), `lib/Service/RuleEngineService.php` (a resolution path for a caller app), `lib/Controller/RulesController.php` (the list filter), `lib/AppInfo/Application.php`, the rule set editor in `src/views/RuleSetsPage.vue`, `l10n/`.
