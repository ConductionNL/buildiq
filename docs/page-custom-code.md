<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->

# Code on pages

Where the page designer's widgets do not cover what you need, an app owner can
write a small piece of their own: a **code component** (HTML, CSS and
JavaScript) on a custom page or as a widget, or an **expression** that computes
a column, a field default or a show-when rule. This page covers what that code
can see, what it can ask the page to do, and who may write it.

> Rendering code and expressions in a running app needs the sandbox frame and
> evaluator from `@conduction/nextcloud-vue`. Until the app's nextcloud-vue
> dependency ships them, the manifest keys are stored but inert, and the page
> designer says so.

## Who may write code

- **Only app owners.** A save that adds or changes code or an expression is
  refused for an editor with "Only app owners can add code to a page." and the
  stored manifest is left as it was.
- **Only when the instance allows it.** An administrator switches on **Allow
  code on pages** in Buildiq's administration settings, under *Code on pages*. It
  is off by default. While it is off, code components show a placeholder and
  expressions stay empty, and nobody can add code.
- **Never the copilot or an agent.** The `buildiq.upsertPage` and
  `buildiq.addWidget` tools drop code and expression keys from their plans and
  say so.

## Where it is stored

| Manifest key | What it holds |
| --- | --- |
| `config.code` on a `type: custom` page or a widget | `{html, css, js, inputs}`; mutually exclusive with `config.component` |
| `columns[].expression` | A JavaScript expression evaluated per row |
| `formField.expressionDefault` | An expression that computes a field's default |
| `visibleWhen.expression` | An expression that decides whether a field shows |

`inputs` names what the page hands a code component: `object` (the current
record on a detail page), `rows` (a query on the page's register and schema
with its filters), `route` (the route parameters) and `user` (id and display
name).

## What code can see and do

Every piece of code runs in an `<iframe sandbox="allow-scripts">` without
`allow-same-origin`. Buildiq serves its document from:

- `GET /apps/buildiq/sandbox/{slug}/{version}/{codeId}` for a code component
  (`version` is a version slug, or `production`; `codeId` is the id of the page
  or widget that carries the code);
- `GET /apps/buildiq/sandbox/evaluator` for the expression evaluator.

Both need a signed-in user who may use the app, and both answer with a policy
that allows the document's own inline script and style, `data:` images and
nothing else. So code:

- **cannot** read the page, its cookies or its storage;
- **cannot** make a network request, not even to Nextcloud; a request it tries
  is blocked and would carry no session anyway;
- **cannot** open frames, popups or forms, or navigate the top window.

A code component receives its inputs from the page by message and may post back
only three things:

- `navigate`: open a page of the app's own manifest, with parameters;
- `notice`: show a short text as a toast;
- `setField`: set a field of the current record. The page performs it through
  OpenRegister with the user's own session, so the user's own rights apply.

Anything else is dropped.

## Expressions

An expression is a single JavaScript expression with `row` (in a column),
`values` (in a form), `user` and `route` in scope. It must return a JSON value
and must not change anything. An expression that throws or runs too long
renders as empty with a marker, and the page designer preview shows the error.
Expressions never run in the page itself or on the server.

## Writing code in the page designer

- **A custom page:** in the page's editor, choose **Write your own** instead of
  **Registered component**. You get an HTML editor, CSS and JavaScript editors
  and a checklist of the inputs the code needs. Switching back to a registered
  component takes the code out of the page; switching again within the same
  session brings it back.
- **A widget:** on a dashboard page, or under **Widgets** on a detail page, add
  a widget of type `custom` and tick **Write your own**. The same editors open
  for that widget.
- **An expression:** switch on **fx** next to a column, a field default or a
  show-when rule, and type the expression.

## Trying it before you publish

Under the live preview, **Code preview** runs every code component of the app
in its own sandboxed frame and every expression in the evaluator, the same way
the app will. The page designer has no record open, so the inputs are samples:
an empty record, no rows, the route without parameters and you as the user.

- A code component that throws shows the error under its frame. The frames
  reload a moment after you stop typing.
- An expression that throws, or takes too long, is listed with its error. With
  an empty sample record an expression that reaches into a missing field (for
  example `row.adres.straat`) reports an error the real data may not cause.
- While **Allow code on pages** is off, the frames show the placeholder and the
  panel says that code is switched off.

The preview frames load `GET /apps/buildiq/sandbox/preview`. It holds no app
data and no code of its own: the page designer posts the code under edit into
it, and it runs under the same policy as a code component's document.
