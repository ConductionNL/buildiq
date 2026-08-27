---
sidebar_position: 1
description: What a page is in a Buildiq app, the thirteen page types, and the fields a page declares.
---

# Pages

A page is one screen in your app. It has a route, a title, and a **type**. The
type decides how Buildiq renders it, so it is the first choice you make.

## The four you will use most

| Type | Use it for |
|---|---|
| `dashboard` | An overview assembled from widgets. |
| `index` | A list of records from one schema. |
| `detail` | A single record, in full. |
| `custom` | A screen whose component you supply. |

## The other nine

`form` · `settings` · `logs` · `chat` · `files` · `map` · `roadmap` · `search` ·
`wiki`

Each one is a narrower job. Pick one when it is exactly your screen. Pick
`custom` when none of them is, because `custom` means you write the component
yourself and maintain it.

## What a page declares

| Field | What it does |
|---|---|
| `id` | The page's name, referenced by menu entries and deep links. |
| `route` | The path, for example `/applications`. |
| `type` | One of the thirteen above. |
| `title` | What the user reads at the top. |
| `permission` | Who may open it. |
| `widgets` | The blocks in the body. See [Widgets](./widgets.md). |
| `actions` | The controls in the header. See [Actions](./actions.md). |
| `sidebar` | Whether the page shows a sidebar. See [Sidebars](./sidebars.md). |
| `primaryAction` | The one prominent button. |
| `config` | Type-specific settings, for example which schema an `index` lists. |
| `slots` | Named insertion points for your own components. |
| `component` | For `custom` only, the component to mount. |

`headerComponent`, `actionsComponent` and `sidebarComponent` let you replace one
region of a page with your own component while the rest stays declarative.

## Templates and instances

Two fields exist for repetition. A `pageTemplate` declares a page shape once
with `params`. A `pageInstance` points at that template with `templateRef` and
fills in the `register`, `schema` and `label`. Use them when the same page shape
repeats across several schemas, so a change lands in one place.

## Where a page lives

Pages are entries in your app's manifest under `pages`. Buildiq's page designer
edits those entries.

Next: give your page something to show. See [Widgets](./widgets.md).
