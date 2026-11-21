---
sidebar_position: 7
description: Flows are your app's business logic. They run in OpenRegister and react to your data changing.
---

# Flows

A flow is business logic that runs when your data changes. It is the fourth part
of an app, after navigation, pages and data.

Flows are **OpenRegister flows**. They run in OpenRegister, beside the data they
react to, rather than as service code you maintain in the app.

:::note
Earlier versions of these docs described this layer as n8n workflows. That is no
longer how it works. Business logic runs on OpenRegister flows.
:::

## What belongs in a flow

Reach for a flow when something should happen *because* the data changed:

- a record reaching a status triggers a notification
- accepting a quote creates the project and assigns the first task
- a nightly recalculation over a set of records
- calling another system when a record is created

## What belongs on the schema instead

Not everything needs a flow. State machines, aggregations, calculations and
notifications are declared as schema metadata. That is simpler, and it applies
however the record was written. See [Schemas](./schemas.md).

The rule of thumb: if it is a property of the data, put it on the schema. If it
is a sequence of steps, make it a flow.

## Rule sets, for decisions

For decision logic specifically, Buildiq has a rule engine: FEEL conditions,
decision tables and hit policies. A matching rule can set a field, send a
notification, or evaluate another rule set. See
[Business rules engine](../business-rules-engine.md).

One caveat worth knowing before you design around it: the rule engine's
`start-workflow` action is **reserved and currently a no-op**. Buildiq wires no
workflow engine of its own, so that action logs a warning and changes nothing.
To run a flow, use OpenRegister.

## Attaching a flow to your app

Any OpenRegister flow can be attached to an app, including one written for
another app. Open the app, choose **Settings**, and pick the flow under
**Flows**. Attaching it on its own only records that the app uses the flow, so
it travels with an export. To make it run on the app's records, add where it
runs under **Run on**:

- one of the app's schemas with **Created**, **Updated** or **Deleted**, to
  run the flow on every record of that schema that changes that way
- one of the app's schemas with **From a record**, for a run an app user starts
  from a record

Only the schemas of the app's production version are offered. A flow can have
several entries.

For each entry Buildiq keeps a small flow of its own, named
`aut-attach-<app>-<code>`: the app's trigger, then a step that starts the
attached flow with the record. The attached flow itself is never changed, so a
flow shared by several apps stays one flow. Removing an entry, or the flow,
removes Buildiq's small flow with it. These small flows are rebuilt wherever the
app is installed, so an export does not carry them.

Only an owner of the app can change its flows. If an entry cannot be wired, for
instance because the app has no production version yet, the settings say so;
save again once that is fixed.

The app's detail page lists each attached flow with where it runs and how its
last run went.

## Sharing an automation with other apps

An owner of an app can mark one of its automations as **shared**. An editor
can change a shared automation, but cannot share or unshare it; an edit that
does not mention sharing keeps it as it was.

A shared automation runs on data other apps hand it, so it may not write
records of its own app. Sharing one with a step that creates or updates a
record is refused, and the refusal names that step.

Other apps find a shared automation in their flow picker (App settings,
Flows), under **Shared automations**, named with the app it comes from, for
example "Stuur bevestiging (permits)". Only an automation that has been
compiled is listed there, because the picker binds its flow. Bind it like any
other flow and give it a "Run on" entry.

### Running another automation as a step

The step **Run another automation** names one automation of the same app, or a
shared automation of another app. It runs that automation's flow and waits for
it, so the steps after it see what it returned: a shared "Controleer adres"
that sets `adresGeldig`, followed by a record step that reads `adresGeldig`.

Saving is refused when the step names no automation, names the automation
itself, names another app's automation that is not shared, or names one that
has not been compiled yet. OpenRegister also stops a longer loop of
automations calling each other when it runs.

## Triggering from the UI

An action on a page can call an endpoint or run an operation, which is how a
button reaches logic. See [Actions](./actions.md).

Next: wire your first one. Open [Business rules engine](../business-rules-engine.md).
