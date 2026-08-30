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

## Triggering from the UI

An action on a page can call an endpoint or run an operation, which is how a
button reaches logic. See [Actions](./actions.md).

Next: wire your first one. Open [Business rules engine](../business-rules-engine.md).
