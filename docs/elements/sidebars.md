---
sidebar_position: 5
description: A sidebar puts secondary detail beside the main content, in tabs.
---

# Sidebars

A sidebar holds detail that belongs to the record but does not belong in the
middle of the screen. Audit history, related records, raw data, a file list.

## Turning one on

`sidebar` is a field on the page, a sibling of `config`, and it applies to every
page type:

```json
"sidebar": { "show": true }
```

`show` gates the host app's sidebar slot. Leave it off and the page renders
without one.

## What goes in it

Sidebar content arrives as tabs. Two routes get you there:

- **Widgets with a `tabGroup`.** Group widgets and they render as tabs in the
  sidebar region rather than stacked in the body. This is the declarative route
  and needs no component from you.
- **`sidebarComponent`.** Replace the whole sidebar with your own component,
  when the tabs you need are not expressible as widgets.

## When to reach for one

Put something in the sidebar when it is *about* the record but not *the* record.
A user reading a record wants the record first.

If you find yourself putting the primary content in the sidebar, the page type
is probably wrong. Check [Pages](./pages.md).

Next: the data all of this reads. See [Schemas](./schemas.md).
