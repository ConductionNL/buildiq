---
kind: code
---

# Proposal: logic-automation-run-log

## Why

**buildiq matrix, row `logic-run-log`**, "See a log of each automation run
with its outcome and errors.", rated `partial`, `built.state` `specified`.

The row pointed at `openregister/rules-engine-operability`, and that change is
done: all 18 tasks are ticked and OpenRegister now keeps a rule run log
(`GET /api/rules/{ruleId}/runs`, rendered by `RuleRunsPanel.vue` on the schema's
rules tab). It does not answer this row, for two reasons.

1. **A buildiq automation is not an OpenRegister rule.** `AutomationCompilerService`
   compiles one automation to up to five artifacts: an
   `x-openregister-notifications` entry, typed `x-openregister-lifecycle`
   actions, a `manifest.schedules[]` entry, an `aut-<uuid8>` RuleSet for the
   `manual` trigger, and an `aut-<slug>` approval chain. None of the four rule
   kinds OpenRegister logs (`calculation`, `stateFieldRule`,
   `lifecycleCondition`, `flow`) is one of these.
2. **Several steps run inside buildiq, not OpenRegister.** The approval trigger
   (`AutomationApprovalTriggerListener`), the approve and reject follow-ups
   (`ApprovalOutcomeListener` through `RuleActionDispatcher`) and document
   generation (`DocumentGenerationListener`) are buildiq listeners. Only the
   `manual` trigger leaves a trace today, as a `rule-execution-log` row that no
   page shows (`RuleEngineService.php:395-413`).

So a maker who asks "did my automation run on complaint C-2026-0412, and why
not" has no answer in buildiq. `GET /api/automations/{uuid}/status` returns
compile drift and the approval state, nothing about runs
(`AutomationsController.php:375`, `AutomationsPage.vue:429-476`).

## What changes

- A new buildiq schema `automation-run`: one record per run of one automation
  on one object, with the trigger, the subject, the outcome, the duration and
  one entry per action with its own outcome and message.
- buildiq writes that record at every point where buildiq itself dispatches an
  automation: the `manual` trigger, the approval trigger, the approval outcome
  and document generation.
- A read endpoint `GET /api/automations/{uuid}/runs` with the same
  authorization as the automation itself, filtered by outcome and period.
- On the Automations page, each row gets a last-run line and a "Runs" button
  that opens a runs dialog: a table of runs, and per run the table of actions
  with their outcome, in the shape the test panel already uses.
- Steps that OpenRegister executes without buildiq (notifications, lifecycle
  actions, schedules) show as "Runs in OpenRegister" with no per-run row, until
  OpenRegister publishes those dispatches (cross-repo, see design D4).
- Runs older than 90 days are pruned by a background job; the last run and
  last error per automation stay.

## Rows covered

- `logic-run-log`

Delivered before this change: `openregister/rules-engine-operability` (the
OpenRegister rule run log, which this change does not duplicate).

## Out of scope

- OpenRegister's rule and flow run logs. A maker reaches flow runs through the
  Flows widget, as today.
- Retrying a failed run from the log. A retry is a write with its own
  authorization question; it is a follow-up.
