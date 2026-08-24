---
sidebar_position: 4
description: Actions are what a user can do on a page. The eleven action types and the fields each one needs.
---

# Actions

An action is a control in a page header. Where a widget is what a user *reads*,
an action is what a user *does*.

## The action types

| `type` | What it does |
|---|---|
| `handler` | Calls a named handler in your app. |
| `open-modal` | Opens a modal. |
| `open-page` | Opens another page. |
| `navigate` | Goes to a route. |
| `object-op` | Runs an operation on the record, for example a status change. |
| `export` | Downloads the data in a chosen format. |
| `open-form` | Opens a form. |
| `refresh` | Re-reads the current data. |
| `api-call` | Calls an endpoint with a method and payload. |
| `agent` | Hands the work to an assistant with a skill and a prompt. |
| `toggle` | Flips a boolean on the record. |

## Fields every action shares

`id`, `label`, `icon`, `variant`, and `description`. `label` is what the user
reads, so it takes the voice rules like any other string: start with a verb, say
what happens.

## Fields particular types need

- **`confirm`** puts a confirmation step in front of a destructive action.
- **`api-call`** uses `url`, `method`, `payload` and `params`.
- **`export`** uses `formats`, `download` and `filename`.
- **`object-op`** uses `op`, `values`, `register`, `schema` and `objectId`.
- **`toggle`** uses `field`, `labelOn`, `labelOff`, `stateSource` and
  `writeUrl`.
- **`agent`** uses `agent`, `skill`, `prompt` and `resultField`.

## After it runs

`successMessage` and `errorMessage` are what the user is told. Say what
happened, not that something happened.

`refresh` re-reads the data so the screen reflects the change. `onSuccessRoute`
sends the user somewhere next, which is often the point of the action.

## Showing an action only sometimes

`visibleWhen` gates an action on state. An action a user cannot use is worse
than an absent one, because they will click it.

Next: put secondary detail beside the content. See [Sidebars](./sidebars.md).
