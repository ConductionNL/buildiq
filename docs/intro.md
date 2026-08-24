---
sidebar_position: 1
description: What a Nextcloud app is made of, and how Buildiq lets you build one in the browser. Navigation, pages, data and flows, saved as a manifest.
---

# Buildiq

Buildiq is a no-code app builder inside Nextcloud. You build the app in the
browser and it runs on the Nextcloud you already have.

Before the buttons make sense, it helps to know what an app actually is.

## What an app is made of

Every app Buildiq builds is four things:

| Part | What it is |
|---|---|
| **Navigation** | The menu entries a user clicks to move around your app. |
| **Pages** | The screens themselves. |
| **Data** | Your schemas. The shape of what you store. |
| **Business logic** | Your flows. What happens when something changes. |

That is the whole model. Everything below is detail on one of those four.

### Navigation

A menu entry points at a page. Entries can sit in the main list or in the
footer, and they can be grouped. Order is yours to set.

### Pages

A page has a **type**, and the type decides how Buildiq renders it. Four types
cover almost everything you will build:

| Type | Use it for |
|---|---|
| `dashboard` | An overview built from widgets. |
| `index` | A list of records from one schema. |
| `detail` | One record, in full. |
| `custom` | A screen you supply the component for. |

Nine more types exist for narrower jobs: `form`, `settings`, `logs`, `chat`,
`files`, `map`, `roadmap`, `search` and `wiki`. Reach for those when one of them
is exactly your screen. Reach for `custom` when none of them is.

### What a page carries

Three things hang off a page:

- **Action buttons.** The controls in the page header. What a user can *do*
  here.
- **A sidebar.** Secondary detail beside the main content, in tabs.
- **Widgets.** The blocks that make up the body.

### Widgets and the grid

Widgets are placed on a page in a **grid**. You choose the position and the
span, so a widget can be a narrow card or a full-width table.

A widget gets its interactive content from your **data**. Point a table widget
at a schema and it lists those records. Point a stats widget at the same schema
and it counts them. This is why the data comes first: a widget with nothing
behind it has nothing to show.

### Business logic, as flows

Logic lives in OpenRegister flows, not in code you maintain. A flow reacts to
something happening to your data and does the next thing. State machines,
aggregations, calculations and notifications are declared as schema metadata
rather than written as service classes.

## And then there is the manifest

Now the manifest makes sense.

Buildiq saves all of the above, your navigation, your pages, your widgets and
their wiring, as a single **manifest**. The manifest is the app. Buildiq's
designers are a way of editing it without hand-writing JSON, and the running
app is that manifest rendered.

Two consequences worth knowing:

- **Your changes survive upgrades.** Overrides are stored as a delta over the
  shipped manifest, so an update does not overwrite your edits.
- **An app is portable.** Because the app is one document, you can snapshot it,
  roll a bad edit back, export it as a ZIP, or publish it to a GitHub
  repository and install it on another instance. See
  [GitHub store](./github-store.md).

## What else you get

- A template catalogue of starters (CRM, intake form, asset register, help
  desk) plus a blank app. Administrators decide what appears.
- A schema designer. Define your data field by field, backed by OpenRegister.
  No database migration.
- A page designer for laying out pages over your schemas.
- Connections to an OpenConnector connector or a DocuDesk template, read and
  written through OpenRegister.
- RBAC. Administrators control who may build, and per-record access is enforced
  by OpenRegister.
- An **AI copilot**. Describe the app in a sentence, review the proposed
  schemas, pages and widgets, and approve before anything is created. See
  [AI Copilot](./ai-copilot.md).

## Reference pages per element

One page per part of the model:

- [Pages](./elements/pages.md)
- [Navigation](./elements/navigation.md)
- [Schemas](./elements/schemas.md)
- [Flows](./elements/flows.md)
- [Widgets](./elements/widgets.md)
- [Actions](./elements/actions.md)
- [Sidebars](./elements/sidebars.md)
- [The manifest](./elements/manifest.md)

## Start building

Install Buildiq from the Nextcloud app store, or enable it in your Nextcloud
admin settings. Then open the app and clone a template.

Next: [create a virtual app](./tutorials/create-a-virtual-app.md).
