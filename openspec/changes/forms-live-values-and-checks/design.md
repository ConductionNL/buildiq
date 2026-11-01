# Design: forms-live-values-and-checks

Read at buildiq development `d21e42f`, nextcloud-vue 2.57.1 as installed,
openregister development `ae898b0`.

## What exists

- Form authoring: `src/components/page-editor/fields/FormFieldBuilder.vue`
  authors a field's key, label, type, required and pattern, and with
  `show-logic` a Conditions section (`VisibleWhenBuilder.vue`) and a Validation
  section (`FieldValidationBuilder.vue`). `FormStepsManager.vue` authors steps.
- Rules: `appinfo/routes.php:191` `rules#evaluate`
  (`POST /api/rules/{ruleSetSlug}/evaluate`) reaches
  `lib/Controller/RulesController.php` `evaluate()`: signed-in user,
  `#[UserRateLimit(limit: 60, period: 60)]`, a payload size guard, RBAC through
  OpenRegister (its docblock), then `RuleEngineService`, which returns
  `result`, `triggeredRules`, `executionTime` and `errors`
  (`lib/Service/RuleEngineService.php:261-266`) and writes an execution log for
  every call (`persistLog()`, lines 250-259). The seeded `loan-eligibility`
  decision table (`lib/Settings/register.d/10-business-rules.json`, around line
  599) has an output `reason` with texts such as "Eligibility criteria not met".
- Renderer: nextcloud-vue 2.57.1 `CnFormPage.vue:370` and `:581-596` copy a
  static `initialValue`; the sentinel resolver handles `@me`, `@now`, `@today`
  and relative dates in filters (`$defs.sentinelFilterToken`).
- Server events: openregister `lib/Event/ObjectCreatingEvent.php` and
  `ObjectUpdatingEvent.php`, dispatched before a save.

## D1. Defaults are tokens, not code

A field's `default` is a literal, a sentinel token (`@me`, `@me.displayName`,
`@me.email`, `@today`), or `@object.<field>` when the form opens from a record.
The field builder offers these as a picker plus a free value. Resolution is
nextcloud-vue's existing sentinel resolver, extended by two user tokens.

## D2. A calculated field reads a rule set

A field's `calculate` is `{ruleSet, output, inputs[]}`: the rule set to
evaluate, which output to show, and which answers it reads (so the form knows
when to recompute). The field builder lists the rule sets and their outputs from
`GET /api/rules/{slug}/schema` (routes.php:192). A calculated field renders
read-only. The runtime bridge in buildiq debounces changes to `inputs[]` and
calls evaluate in preview mode (D4).

## D3. An eligibility check is a rule set on the form

The form page's `eligibility` is `{ruleSet, passWhen, explainWith, blockSubmit}`:
the rule set, the output value that means "eligible", the output that carries the
explanation (for `loan-eligibility`, `decision` and `reason`), and whether submit
waits for a pass. The runtime shows the explanation beside the form as the
person types; with `blockSubmit` the submit button stays disabled with that
explanation as its reason.

## D4. A preview mode for evaluate

Live evaluation calls evaluate on every debounced change; one execution log row
per call would flood `rule-execution-log`. `evaluate()` accepts
`mode: preview`, which runs the same RBAC, rate limit and size guard, and skips
`persistLog()`. Preview answers are never stored and never trusted for a save.

## D5. The server recomputes on save

A listener on OpenRegister's `ObjectCreatingEvent` and `ObjectUpdatingEvent`
looks up the form definitions that target the object's schema, re-evaluates each
`calculate` binding with the object's values (not in preview mode, so it is
logged), and overwrites the field. A value changed in the browser is replaced by
the server's; an eligibility check with `blockSubmit` refuses the save when the
server evaluation fails. The listener follows hydra ADR-078 for work placement.

## Risks

- Rule sets used live must be fast. The preview path shares the rate limit
  (60 a minute per user); the bridge debounces at 400 ms.
- A rule set a form depends on can be archived. The form editor marks a
  binding to a rule set that is not active.

## What it does not do

- It adds no expression language and no JavaScript.
- It does not replace `x-openregister-calculations` on the schema.
