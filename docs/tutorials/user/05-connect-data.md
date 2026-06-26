---
sidebar_position: 5
title: Connect a register or connector
description: Point a page or widget at an existing OpenRegister register, or pull data from an external system via OpenConnector — configured in-app.
---

# Connect a register or connector

A virtual app doesn't have to own its data. Its pages and widgets can read from
any OpenRegister register on the same Nextcloud, or from any **OpenConnector**
source (HTTP API, database, file feed) an admin has wired up. You bind the data
**in-app**, on the page or widget itself.

## Goal

By the end you will have pointed a page (or a list/table widget) at a register —
or at an external source through OpenConnector — and seen it list rows from there.

## Prerequisites

- A virtual app with a page that lists data (see [Build your app in-app](./04-design-page.md)).
- The register or connector you want to read from exists on the Nextcloud.
  OpenConnector sources live under **Connector → Sources**; ask an admin if the
  source you need isn't there yet.

## Where data binding lives

There are two places to bind data, both in-app:

- **A page's register + schema** — open **Edit with OpenBuild → Edit pages…**,
  select the page, and set its **Register** and **Schema**. An index/detail page
  reads from this binding.
- **A widget's data source** — for an **Object list**, **Table**, **Chart** or
  **KPI** widget, click the widget's **cog** in edit mode and set its register,
  schema, filter, sort and columns. This lets one page show several differently
  sourced widgets. See the [Widgets reference](../../widgets.md).

## Steps

1. Open your app and click **Edit with OpenBuild → Edit page**.

2. **Re-point a page.** Open **Edit with OpenBuild → Edit pages…**, pick the
   page, and choose a different **Register** and **Schema**. The page reloads
   against the new binding; if the columns no longer match, adjust them in the
   same dialog.

3. **Or bind a widget.** Add (or select) an **Object list** / **Table** widget,
   click its **cog**, and set the **Register**, **Schema**, **filter**, **sort**
   and **columns**. The widget previews live as you change them.

   ![Configuring a widget in-app](/screenshots/widgets/01-add-widget.png)

4. **Read from an external source.** For a connector-backed source, point the
   register/schema at the register an OpenConnector **Source** writes into (the
   connector mediates auth, caching and rate-limiting and syncs rows into
   OpenRegister). Manage sources under **Connector → Sources**.

5. **Save page.** The page now reads from the chosen register/source on every
   load.

## Verification

The binding is good when: the page (or widget) lists rows in the right shape —
columns populated, no error banner — and the rows still load after a reload.

## Common issues

| Symptom | Fix |
|---|---|
| List is empty after switching | The register/source has no rows for this binding — add records, or check the OpenConnector source under **Connector → Sources**. |
| Columns show as *undefined* | The data's shape doesn't match the columns — fix the columns in the page/widget config, or map the response in **Connector → Mappings**. |
| *"Register/schema not found"* | The register or schema was renamed/removed — pick an existing one in the page or widget config. |

## Reference

- [Build your app in-app](./04-design-page.md) — where you add and configure widgets.
- [Widgets](../../widgets.md) — the data-bound widgets and their config.
- [Run and refine your app](./06-preview-app.md) — see the data load in the live app.
