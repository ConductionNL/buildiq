---
kind: code
depends_on: [logic-automation-actions-that-run]
---

# Proposal: logic-email-and-webhook-triggers

## Why

**buildiq matrix, row `logic-trigger-email`**, "Start an automation when an email
arrives in a mailbox.", rated `no`, `built.state` `none`. Three competitors rate
it `yes`:

- Budibase: "packages/shared-core/src/automations/triggers/email.ts:11 'Email
  received' trigger over IMAP (host, port, mailbox, OAuth2 option :33-69)"
  (source read at v3.46.0).
- Mendix: "the Email Connector can subscribe to incoming emails (IMAP only) so
  new mail triggers processing in a microflow"
  (https://docs.mendix.com/appstore/modules/email-connector/).
- Microsoft Power Apps: "email flows start on email triggers such as when a new
  email arrives in Outlook"
  (https://learn.microsoft.com/en-us/power-automate/create-email-flows).

Today, from `built.evidence`: "Automation triggers are object created, updated,
deleted, lifecycle transition, cron schedule and manual only
(src/dialogs/AutomationEditDialog.vue:484-495); no mail-received trigger".

**buildiq matrix, row `logic-trigger-webhook`**, "Start an automation when
another system calls a webhook.", rated `no`, `built.state` `none`. Two
competitors rate it `yes`:

- Budibase: "packages/shared-core/src/automations/triggers/webhook.ts:11
  'Webhook' trigger, created from
  packages/builder/src/components/automation/Shared/CreateWebhookModal.svelte"
  (source read at v3.46.0).
- Mendix: "a published REST service exposes an endpoint whose operations run a
  microflow when another system calls it"
  (https://docs.mendix.com/refguide/published-rest-service/).

Today, from `built.evidence`: "webhook exists only as an outgoing action (:233);
other systems can write records through the REST API (int-inbound-api), which
then fires an object trigger".

Both triggers sit at the edge of the fleet. Reading a mailbox and accepting a
call from another system are integriq's (ADR-091: "An HTTP surface that
authenticates its caller by any scheme other than a Nextcloud session belongs to
OpenConnector"). Buildiq's half is the trigger in the composer, the flow it
starts, and the declaration that asks integriq for the mailbox subscription or
the endpoint.

## What changes

- Two new triggers in the automation composer: "Email received" and "Webhook
  called".
- "Email received" picks an integriq mailbox source and optional filters on
  sender and subject. The mail's fields become the flow's input.
- "Webhook called" asks integriq for an endpoint for this automation. The
  composer shows the address and how the caller signs in. The request body
  becomes the flow's input.
- Both compile, through the flow backend, to a flow with a manual trigger node,
  plus a declaration integriq owns: an event subscription for mail, an endpoint
  with a flow rule for the webhook.
- Buildiq adds no public route.

## Rows this closes

| matrix | row id | row name | own rating | what is missing |
|-|-|-|-|-|
| buildiq | logic-trigger-email | Start an automation when an email arrives in a mailbox. | no | a mail trigger in the composer that starts the automation's flow |
| buildiq | logic-trigger-webhook | Start an automation when another system calls a webhook. | no | a webhook trigger in the composer that starts the automation's flow |

## Existing work it builds on

- Change `logic-automation-actions-that-run` (this pass): the flow backend. The
  new triggers compile only through it.
- Spec `openspec/specs/automation-designer/spec.md`: the composer, the matrix and
  provenance.
- Spec `openspec/specs/openconnector-api-sources/spec.md` (archived
  `2026-06-14-openconnector-api-sources`): buildiq already reads integriq sources
  for connector pages.
- Open change `adopt-connection-registry`: buildiq reports the connections it
  uses; a mailbox and an endpoint are two more.

## Sibling halves

- integriq: owns the mailbox and the endpoint, and the credential check on the
  endpoint (ADR-091). What exists on integriq development: mailbox sources and
  the `message` object (`mail-intake-creates-cases`, open, its implement tasks
  checked), `MessageReceivedEvent` (`lib/Event/MessageReceivedEvent.php`), an
  `event_subscription` whose `action.kind` may be `flow` with a `flowId`
  (`nc-events-start-or-flows`, open; dispatch in
  `lib/Service/EventService.php:1302-1360`), and endpoint rules of `type: flow`
  (spec `flow-orchestration` REQ-007). What integriq owes: that a received
  mailbox message can match a subscription of kind `flow`, and that both the
  subscription and the endpoint rule start an OpenRegister flow by uuid rather
  than integriq's retiring local flow schema (`retire-integriq-flow-schema`,
  open). The identity a webhook run executes as is the one bound to the
  authenticated consumer (ADR-099, trigger table).
- openregister: nothing new. A flow with `openregister.trigger-manual` can be
  started by another app with an input payload.

## Out of scope

- Reading a mailbox or serving an endpoint in buildiq.
- Replying to the sender. Integriq's intake reply service owns that.
- Attachments as flow input beyond their names; files go through integriq and
  filinq.
- Anonymous webhooks. Every endpoint integriq serves for this checks a
  credential.
