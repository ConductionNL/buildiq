# Design: logic-email-and-webhook-triggers

Read at buildiq development `d21e42f`, integriq development (read through the
GitHub API on 2026-09-27).

## Where it sits

- Composer triggers: `triggerOptions()` in `src/dialogs/AutomationEditDialog.vue`
  (lines 484-495): object created, updated, deleted, lifecycle transition, cron
  schedule, manual.
- Compiler: `lib/Service/AutomationCompilerService.php`, `MATRIX` (lines
  149-156), and the flow backend of `logic-automation-actions-that-run` (D1 to
  D3), which saves flows through OpenRegister's `FlowService::save()` and binds
  them in `Application.flows`.
- Integriq sources in buildiq: `src/services/connectorSynchronizations.js` and
  `src/composables/useConnectorDataSource.js` read integriq objects through
  OpenRegister's REST surface. `src/dialogs/AutomationEditDialog.vue` already
  lists synchronizations the same way.
- Integriq objects this change writes, in the `integriq` register of
  `lib/Settings/integriq_register.json` (integriq development):
  `event_subscription` (`source`, `types`, `filters`, `action`, `userId`),
  `endpoint` (`endpoint`, `method`, `rules`, `conditions`), `consumer`
  (`authorizationType`, `authorizationConfiguration`, `rateLimit`) and the read
  side `mail_message` (`from`, `to`, `subject`, `receivedAt`, `bodyText`,
  `attachments`).

## D1. Two triggers, both flow-only

`triggerOptions()` gains `email-received` and `webhook-called`. `MATRIX` allows
every flow action on both, and nothing that is not a flow action. The compiler
routes both through the flow backend, which maps them to
`openregister.trigger-manual`: the run is started by integriq, not by an
OpenRegister trigger.

## D2. Email received

Config: `source` (an integriq source of the mailbox type, picked from the sources
the maker can read), and optional `from` and `subjectContains` filters. On apply,
buildiq writes one `event_subscription` in the `integriq` register with the
mailbox as `source`, the message received type as `types`, the filters as
`filters`, and `action` `{kind: "flow", flowId}` naming the compiled flow. The
automation's provenance records the subscription uuid; disable and remove act on
it together with the flow. The flow's input item carries the `mail_message`
fields `from`, `to`, `subject`, `receivedAt`, `bodyText` and attachment names.

## D3. Webhook called

Config: `authorization` (`api-key` or `hmac`, the kinds integriq's `consumer`
supports) and an optional JSON example of the body for the field pickers. On
apply, buildiq writes an `endpoint` whose path is
`buildiq/<app slug>/<automation slug>`, method POST, with one rule
`{type: "flow", configRef: <flow uuid>}`, and a `consumer` with the chosen
authorization and a rate limit. Integriq serves the path, checks the credential
and starts the flow. The composer shows the full address and a "Show key" link
that opens integriq's consumer page; buildiq never stores or shows the secret.
The flow's input item is the request body.

## D4. No route in buildiq

Buildiq adds no `#[PublicPage]` controller and no route. Every call from outside
reaches integriq, which checks the credential (ADR-091) and throttles (ADR-082).

## D5. Whose rights a run has

A webhook run executes as the identity bound to the integriq consumer (ADR-099,
trigger table). A mail run executes as the `userId` of the subscription, which
buildiq sets to the maker who applied the automation, and the composer says so.
A step that writes to a schema the run's identity cannot write fails into the
run log; nothing widens.

## D6. Integriq absent

Without integriq, or without a mailbox source the maker can read, the two
triggers are disabled with the reason, as `generateDocument` is without the
document app. The compiler checks again on apply and writes nothing when the
integriq objects cannot be written.

## Risks

- A mailbox with heavy traffic starts many runs. The subscription filters and the
  flow's own condition keep that down, and runs are asynchronous.
- A leaked key lets a caller start the automation. The consumer's rate limit
  bounds it, and rotating the key in integriq needs no recompile.
- Until integriq starts OpenRegister flows from both paths, applying these
  triggers fails closed with the reason shown.

## What it does not do

- It adds no endpoint, mailbox reader or credential store to buildiq.
- It does not reply to mail.
