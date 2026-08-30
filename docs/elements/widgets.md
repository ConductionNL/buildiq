---
sidebar_position: 2
description: Widgets are the blocks in a page body. They sit in a grid and take their content from your data.
---

# Widgets

A widget is one block in a page's body. Widgets are how a `dashboard` gets its
cards and how a page shows a table without you writing one.

## Widgets sit in a grid

Every widget places itself with four numbers:

| Field | What it sets |
|---|---|
| `gridX` | Column position. |
| `gridY` | Row position. |
| `gridWidth` | How many columns it spans. |
| `gridHeight` | How many rows it spans. |

So a widget can be a narrow card beside three others, or a full-width table.
You set the layout by setting the numbers, not by dragging markup around.

`slot` puts the widget in a named region of the page. `tabGroup` groups several
widgets into tabs, so one region can hold more than one view.

## A widget gets its content from your data

This is the part worth internalising: **a widget is a view, and your schema is
what it views.** A widget with nothing bound to it has nothing to show.

The binding is `dataSource`, and it has two forms:

- **Shorthand.** `{register, schema, filter, aggregate: 'count'}`. Buildiq
  builds the query for you. A `count` aggregate resolves to a number, which is
  what a stats block wants.
- **Raw query.** `{graphql: {query, variables, selectors}}`. Buildiq issues the
  query and runs your selectors over the result. Reach for this when the
  shorthand cannot express what you need.

Point a table widget at a schema and it lists those records. Point a stats
widget at the same schema and it counts them. Same data, two views.

## Which widget

`widgetKey` names the component. It resolves against the component map the app
hands to `CnAppRoot` at boot, so the set available to you is the set your app
registers. Built-in table and stats widgets cover lists and counts without any
custom component, which is why most dashboards need no widget code at all.

`props` passes configuration through to the component.

## Fields are widgets too

Inside a form, `fieldWidget` does the same job at field level: an `id`, a
`component` and `props`. A field is a widget for one value.

Next: decide what a user can *do* on the page. See [Actions](./actions.md).
