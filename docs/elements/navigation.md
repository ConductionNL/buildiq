---
sidebar_position: 3
description: Menu entries are how a user moves through your app. Sections, grouping, counts and conditional visibility.
---

# Navigation

Navigation is the menu a user clicks to move through your app. Each entry
points at a page, and the order is yours.

## Where an entry sits

| `section` | Where it renders |
|---|---|
| `main` | The primary menu list. |
| `footer` | Below the main list, for documentation and settings-ish links. |
| `settings` | The settings area. |

`order` sorts entries within a section. Lower numbers come first.

## What an entry declares

| Field | What it does |
|---|---|
| `id` | The entry's name. |
| `label` | What the user reads. |
| `icon` | The glyph beside the label. |
| `route` | The page it opens. |
| `query` | Query parameters to apply on open. |
| `href` | An external URL, instead of a route. |
| `permission` | Who sees it. |
| `count` | A badge, for example an unread total. |
| `pinned` | Keep it visible. |
| `tourId` | Start a walkthrough from this entry. |

## Grouping

`children` nests entries under a parent, and `open` controls whether that group
starts expanded. A group can carry a `route` of its own, so the parent is
clickable rather than a dead label.

`type: 'caption'` makes an entry a heading rather than a link. Use it to label a
run of related entries.

## Showing an entry only sometimes

`visibleIf` gates an entry on a condition, so a menu can reflect state rather
than listing everything always. `dynamicSource` builds entries from data, which
is how a menu lists records that did not exist when you designed it.

Next: the screens these entries open. See [Pages](./pages.md).
