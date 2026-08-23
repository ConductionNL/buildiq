<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->

# Business rules engine

The business-rules engine lets process analysts model decision logic separately
from UI and code. Two paradigms are supported:

- **Decision tables** (DMN 1.4) — multi-factor mapping (e.g. loan eligibility).
- **Condition-action rules** — sequential triggers (e.g. invoice routing).

RuleSets are first-class OpenRegister objects in the shared `buildiq`
register, versioned and deployed per tenant without an app redeploy.

## Lifecycle

A RuleSet moves through `draft → test → active → archived` via the declarative
OpenRegister lifecycle (`x-openregister-lifecycle`):

| Transition | From → To          | Notes                                            |
| ---------- | ------------------ | ------------------------------------------------ |
| `submit`   | draft → test       | Freeze the draft for sandbox validation.         |
| `reopen`   | test → draft       | Return to draft for editing.                     |
| `activate` | test → active      | Bumps the semver; all TestCases must pass first. |
| `archive`  | active → archived  | Retire a live RuleSet.                           |

Activation auto-increments the semver (patch for rule changes, minor for new
columns, major for breaking changes) and notifies owners via
`x-openregister-notifications`.

## FEEL subset

Conditions use a small, auditable FEEL subset:

| Feature      | Syntax                          | Example                       |
| ------------ | ------------------------------- | ----------------------------- |
| Comparison   | `== != < > <= >=`               | `age >= 18`                   |
| Range        | `low..high` (inclusive)         | `age in (18..65)`             |
| List         | `in (a, b, c)`                  | `status in ('open', 'new')`   |
| Logical      | `and`, `or`, `not`              | `age >= 18 and income >= 2000`|
| Null check   | `is null`, `is not null`        | `email is null`               |
| Arithmetic   | `+ - * /`                       | `total - discount > 100`      |
| Field path   | dot-notation                    | `applicant.age`               |

**Not supported:** string interpolation, function calls (`now()`,
`length()`), custom user-defined functions, external library calls. Move that
logic into an n8n workflow (via the `start-workflow` action) or a backend
service.

### Decision-table cell conditions

Each rule cell is one of: a don't-care token (`-`, `*`, empty), a comparison
(`>=18`), an inclusive range (`18..65`), a list (`in (1, 2, 3)`), or a bare
literal (equality). The editor shows a red badge on an invalid cell.

## Hit policies

`hitPolicy` (default `first`):

- `first` / `rule-order` — top-to-bottom, stop at the first match.
- `unique` — exactly one rule may match (errors otherwise).
- `priority` — the highest-`prioriteit` matching rule wins.
- `any` / `collect` — gather every matching rule's output.

The editor warns about overlapping and unreachable rules.

## Condition-action rules

Rules fire in `prioriteit` DESC, then `salience` DESC, then declaration order.
A matching rule runs its actions in order:

- `set-veld` — set a field on the working payload.
- `send-notification` — dispatch a Nextcloud notification.
- `start-workflow` — start an n8n workflow.
- `call-rule-set` — evaluate another RuleSet.

A failing action aborts the rule unless `continueOnError` is set. In dry-run
mode side-effecting actions are recorded but not dispatched.

## Runtime API

| Method | Endpoint                                | Purpose                              |
| ------ | --------------------------------------- | ------------------------------------ |
| POST   | `/api/rules/{ruleSetSlug}/evaluate`     | Synchronous evaluation (`dryRun`, `version`). |
| GET    | `/api/rules/{ruleSetSlug}/schema`       | RuleSet metadata + active version.   |
| POST   | `/api/rules/{ruleSetSlug}/test-all`     | Run every TestCase.                  |

`evaluate` accepts `{ payload, dryRun?, version? }` and returns
`{ result, geraaktRegels, executieDuur, fouten }`. A 404 means the RuleSet is
not found or not owned by the caller's tenant; a 408 means the 500 ms soft
timeout was exceeded.

All endpoints are `#[NoAdminRequired]` (any authenticated user may evaluate),
never public; multi-tenant isolation is enforced server-side.

## Audit trail (AVG art. 22)

Every evaluation writes a `RuleExecutionLog` capturing the input (PII fields
such as BSN/email masked by default), the output, the triggered rules, the
duration and any errors, plus the triggering user. This is the system of record
for explaining an automated decision. The `RuleExecutionLogCleanup` background
job (7-day interval) purges logs past the 90-day retention window.

To query the audit trail for compliance, list `rule-execution-log` objects in
the `buildiq` register filtered by `ruleSetId` and time window.

## Automation designer

A `manual`-trigger automation composed on the [Automations page](./automation-designer.md)
compiles to exactly this engine: a namespaced RuleSet (`aut-<uuid8>`) plus one
`condition-action` rule, evaluated and dry-run through the same
`RuleEngineService` — inheriting the audit trail, PII masking and 500&nbsp;ms
soft timeout documented above unchanged. The Automations page is the
citizen-developer composer for the common "when X happens, do Y" case; this
page's DecisionTable / ConditionActionRule editors remain the power-user
surface for hand-authoring rule sets directly.
