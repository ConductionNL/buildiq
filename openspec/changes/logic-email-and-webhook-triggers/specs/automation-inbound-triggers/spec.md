# Spec: automation-inbound-triggers

## Purpose

A maker starts an automation when an email arrives in a mailbox or when another
system calls a webhook. Integriq reads the mailbox and serves the webhook;
buildiq authors the trigger and the flow it starts.

## ADDED Requirements

### Requirement: The composer offers email and webhook triggers (REQ-BQTR-001)

The automation composer SHALL offer the triggers "Email received" and "Webhook
called". Both SHALL allow only flow actions and SHALL compile through the flow
backend. Without integriq, or without a mailbox source the maker can read for
"Email received", the trigger SHALL be disabled with the reason.

#### Scenario: A maker picks the mail trigger

- **GIVEN** a maker composing an automation, with integriq and a mailbox source `meldingen@gemeente.nl` they can read
- **WHEN** they open the trigger list
- **THEN** "Email received" and "Webhook called" are offered, and "Email received" lists `meldingen@gemeente.nl`

### Requirement: Mail starts the automation's flow (REQ-BQTR-002)

Applying an "Email received" automation SHALL write an integriq
`event_subscription` for the mailbox, with the maker's filters and an action of
kind `flow` naming the compiled flow, and SHALL record it in provenance.
Disabling or removing the automation SHALL disable or remove the subscription
with the flow. A run SHALL receive the mail's sender, recipients, subject,
received time, text body and attachment names as input.

#### Scenario: A report by mail becomes a record

- **GIVEN** an automation "Email received" on `meldingen@gemeente.nl` with a record step creating a `melding` from the subject and body
- **WHEN** a resident sends a mail with subject "Losse stoeptegel"
- **THEN** a `melding` record exists with that subject as its title, and the flow's run log shows the mail as input

### Requirement: A webhook starts the automation's flow (REQ-BQTR-003)

Applying a "Webhook called" automation SHALL write an integriq `endpoint` at
`buildiq/<app slug>/<automation slug>` with a rule of type `flow` naming the
compiled flow, and a `consumer` with the chosen authorization and a rate limit.
The composer SHALL show the full address and SHALL NOT show or store the secret.
A run SHALL receive the request body as input.

#### Scenario: The planning system reports a finished job

- **GIVEN** an applied "Webhook called" automation with API key authorization and a record step that sets `status` to `gereed`
- **WHEN** the planning system posts `{"opdracht": "42"}` to the shown address with its key
- **THEN** record 42 has `status` `gereed`

#### Scenario: A call without the key does nothing

- **GIVEN** the same automation
- **WHEN** a caller posts to the address without a key
- **THEN** integriq refuses the call and no flow run starts

### Requirement: Buildiq opens no route of its own (REQ-BQTR-004)

Buildiq SHALL NOT register a public route or controller for these triggers. A
run SHALL execute as the identity bound to the integriq consumer for a webhook,
and as the subscription's user for mail, and SHALL NOT widen those rights.

#### Scenario: A webhook cannot write where its identity cannot

- **GIVEN** a webhook automation whose consumer identity may not write schema `besluit`
- **WHEN** a signed call starts a run whose record step writes a `besluit`
- **THEN** the write step fails, the run log shows the refusal, and no `besluit` is written
