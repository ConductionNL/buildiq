# Design: rules-evaluation-for-other-apps

## Screens

No board for the resident: portaliq's design shows only the outcome, and the table itself is drawn as buildiq's **BqBeslistabel** board (canvas `5NkFW28vZUUij43xzxHg5a`). This change adds one field to the rule set's settings next to "Eigenaar-app" and "Globaal": "Mag worden aangeroepen door", a multi-select of installed apps (NcSelect with `inputLabel`).

## D1. Resolution for a caller app

The HTTP evaluate path resolves through the user's RBAC scope and stays as it is. The command path resolves the active rule set by slug through OpenRegister with `_rbac: false` and checks, in buildiq, that the calling app is in `callableBy`. This is the one place buildiq reads rule sets without the user's scope, so the check sits next to the read in `RuleEngineService::resolveForApp()` and has its own tests. `_rbac: false` is on a read only; nothing is written that way.

## D2. The command

`RuleEvaluationRequestedEvent(string $app, string $ruleSet, array $inputs)` with a result slot, as ADR-041 typed commands do.

1. Resolve (D1). `unknown-rule-set` when absent or not active, `not-allowed` when the app is not listed.
2. Check inputs against the rule set's declared inputs: a missing input the table tests is passed as null (REQ-BRE-021 decides what that means), an input of the wrong type is `invalid-input`, undeclared inputs are dropped. The payload size guard applies.
3. Evaluate with actions suppressed, as `preview` does, under the existing 500 ms bound; `timeout` past it.
4. Write one `rule-execution-log` with `callerApp`, the rule set version, the input and the output.
5. Answer `{ ok: true, result, triggeredRules, version }`.

## D3. The list

`GET /api/rules?callableBy=portaliq` answers the active rule sets that list portaliq, projected to slug, title, version, inputs and outputs. It is `#[NoAdminRequired]`; the decision table itself is not returned.
