---
sidebar_position: 8
title: Widgets
description: The widget library you can drop onto any OpenBuild page — object widgets (Data, Object relations, tables, charts, KPIs) and content widgets (text, media, navigation), each configurable in-app.
---

# Widgets

Widgets are the building blocks you place on a page. In edit mode, **Edit with
OpenBuild → Add widget…** opens the picker; pick a type, set its appearance, and
it drops into the page's grid. Each widget has a **cog** for its own
configuration. Most widgets can be added more than once and configured
differently each time.

![Object widgets rendered on a detail page — the Object data and Object Id widgets showing live data](/screenshots/widgets/object-widgets-on-page.png)

Widgets fall into two groups: **object widgets** that read your app's data, and
**content widgets** for text, media and navigation.

## Object widgets

These read from the page's object or a register/schema you point them at.

| Widget | What it shows | Configure |
|---|---|---|
| **Object data** | The current object's fields, as an editable data grid. | Which properties show, their order, layout (Stacked / 2-col / 3-col), per-field editor and editability. Inline click-to-edit; the same config drives the full edit form. |
| **Object relations** | Everything linked to the object — related objects, files, and leaf integrations (mail, calendar, contacts, tasks, deck, …) — as tabs. | **Relations to show**: pick which relation groups appear. Add it several times to scope each to different relations. |
| **Object list** | A list/table of objects from a register + schema. | Register, schema, filter, sort, columns, limit. |
| **Table** | A tabular object list (alias of Object list for `type:"table"`). | Register, schema, columns, sort, limit. |
| **Statistic / KPI** | A single headline metric (count / sum / avg) over a register. | Data source, metric, label, icon. |
| **Statistic card** | A KPI card with a count and breakdown. | Data source, metric, variant, icon. |
| **Comparison / delta** | A metric vs. a previous period, with a delta. | Data source, metric, comparison window. |
| **Gauge / utilization** | A gauge for a value against a target/capacity. | Data source, value, max/target. |
| **Chart** | A bar/line/area chart bucketed over a register. | Chart kind, data source, bucket field/interval, metric. |

### Object data

The default body of a detail page. Click the cog to choose exactly which fields
show and how they're laid out — hide `id`, show `name` and `race` stacked, and
so on. The same map drives both the inline display and the edit form.

![The Object data widget rendered on a detail page, showing the object's fields as a data grid](/screenshots/widgets/object-data-widget.png)

### Object relations

Aggregates the object's relations into tabs. Pick **Relations to show** to scope
a widget to a subset — for example one widget for *Objects + Files* and another
for *Mails + Events* — and add as many as you need.

![Object relations configuration — pick which relation groups to show](/screenshots/widgets/02-object-relations-config.png)

### Statistic, gauge & chart widgets

The metric widgets read a register and render a live value. Here is a **Gauge**
widget on a built app's dashboard, showing a value against its target:

![A Gauge widget rendered on a built app's dashboard](/screenshots/widgets/gauge-widget.png)

## Content & layout widgets

These don't need data — they're for text, media, navigation and structure.

| Widget | What it is |
|---|---|
| **Label** | A short styled text label (font size, colour, weight, alignment). |
| **Text** | A rich text / paragraph block. |
| **Header Banner** | A page banner with title and styling. |
| **Image** | An image (uploaded or by URL). |
| **Video** | An embedded video. |
| **Divider** | A horizontal rule to separate sections. |
| **Container** | A grouping container for nesting widgets. |
| **Tile** | A quick-access tile with an icon and a link. |
| **Links** | A list of links. |
| **Quicklinks** | A compact quick-links grid. |
| **Menu** | A navigation menu block. |
| **News** | A news/announcements feed block. |
| **Files** | A files panel for the object or a folder. |

## Appearance (every widget)

The Add-widget dialog and each widget's cog share an **Appearance** section:
show/hide the title, set a **custom title**, pick a **background** colour, and
choose an **icon**. Widgets that bring their own title (like Object data and
Object relations) hide the duplicate title field automatically.

## Widgets placed via the manifest

A few widgets are rendered by the runtime but aren't offered in the Add-widget
picker because they have no in-app config form (they're wired through the
manifest or an integration): **Calendar**, **People**, **Knowledge base
search**, **Nextcloud widget**, **Spend analytics**, and **Interaction form**.
Integrations registered by other apps also surface here as `integration`
widgets. See the [integrator guide](./integrator-guide.md).

## Reference

- [Build your app in-app](./tutorials/user/04-design-page.md) — add and configure widgets in context.
- [Integrator guide](./integrator-guide.md) — register your own widgets and integrations.
