---
sidebar_position: 9
title: Widgets on the Nextcloud dashboard
description: Promote a widget from one of your apps so people can add it to their own Nextcloud dashboard, next to Files and Calendar.
---

# Widgets on the Nextcloud dashboard

A widget you build lives on a page inside your app. People have to remember the
app, open it, and find the right page. Promote the widget and it shows up in the
Nextcloud dashboard picker instead, next to Files and Calendar.

Nothing is promoted by default. You decide, per widget.

## What promoting does

Promote a widget and three things happen.

1. The widget appears in the dashboard widget picker, under the title you gave it.
2. Anyone allowed to see the widget in your app can add it to their dashboard.
3. The Nextcloud mobile and desktop clients get the same content, computed on the server.

Nobody else is affected. People who never add the panel see no change at all.

## Promote a widget

You promote a widget from the page designer, on the widget itself. Switch
**Show on the Nextcloud dashboard** on. Two fields appear:

- **Panel title**, what the picker and the panel header show.
- **Panel icon**, picked from the shared icon set.

Save the page. Open your Nextcloud dashboard, click **Customise**, and the widget
is in the list.

## The one thing you cannot undo

**A widget keeps the identity it was given the first time you promoted it, and
that identity can never change.**

Nextcloud stores each person's chosen widgets under that identity, in its own
place, which your app cannot read or rewrite. Change the identity and the panel
disappears from every dashboard that had it. Nobody gets an error. Nobody gets a
warning in a log. The dashboard simply looks like one where the panel was never
added.

So the identity is built from the app's permanent id, never from its name or its
web address. Rename your app, rename the page, rename the widget: the panel stays
where people put it.

Demoting is safe. Switch the toggle off and the panel leaves the picker. Switch it
back on later and it returns under the same identity, on the dashboards that had
it.

## Who sees a promoted widget

The same people who can see it inside your app. The check runs per person, per
request, against your app's permissions and the widget's own roles.

Change who may see the app and the dashboard follows on the next page load. There
is nothing to re-save and nothing to re-publish.

A person outside every listed role is never offered the widget. If they had it
already and you take their access away, the panel stops rendering for them too.

## What the mobile and desktop clients show

Those clients cannot run the widget, so the server works the answer out instead.

| Your widget reads | They see |
|---|---|
| A list of records | One row per record, each opening the page it came from |
| A single number | That number |
| A chart, a gauge, or a query you wrote yourself | An empty panel that points at the app page |

The third row is deliberate. A chart is a picture, and a query you wrote yourself
has your own meaning in it. Rather than guess a number, the panel says where to go
and read the real thing.

It never shows a zero it did not compute. A zero you can trust and a zero that
means "we gave up" look identical, so the second one is not offered at all.

## Next

Build the widget first, then promote it. The [widget library](./widgets.md) covers
every type you can place on a page and how to configure it.
