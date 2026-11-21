---
sidebar_position: 9
description: Give each case type its own tabs, widgets, header, task list and upload fields.
---

# Layouts per case type

A detail page shows every case of a schema the same way, unless you give a case
type its own layout. A building permit can then open on a widget overview with
its term and handler, while an event permit opens on its advice.

Open the detail page in the page designer and go to "Applies to". Fill in the
type property and the type value, for example `caseType` and `bouwvergunning`.
Leave both empty and the layout covers every case in the schema.

## Tabs

Choose the kind of tab and "Add tab":

- **Group of fields** shows the fields you list.
- **Widgets** shows a grid of widgets.
- **Content from another app** shows a leaf, such as `filinq-documents`.
- **List of related records** shows the records of another register and schema.

Move a tab with "Up" and "Down". A tab that misses what its kind needs says so,
and the layout cannot be saved until it has it. When no installed app offers
the leaf you named, the save goes through with a warning, because that tab
stays empty until the app is installed.

## Widgets

A widget has a width: small, medium, large or extra large, a quarter to the
whole row. The preview under the widgets shows where a row breaks. Add a
condition to show a widget only when a field of the case holds, for example
"result is not empty". Tick high contrast for a widget that must stand out.

## Header, task list and upload fields

- **Header**: the title field, the subtitle field, chips, and the fields shown
  in the header, in order. A case type's header replaces the schema-wide one
  as a whole.
- **Task list**: the columns and search fields the task list shows for this
  case type.
- **Upload fields**: the fields of the upload dialog, each editable, read only
  or hidden, with a default. A hidden field needs a default, or it can never be
  filled.

### Where the task list sits

Under **Show the task list**, pick where the consuming app shows the task list
on this case type:

- **Beside the tabs**: a panel next to the case's tabs. Tick the tabs it shows
  beside; with none ticked it shows beside every tab. **Start folded** opens it
  folded.
- **As a tab of its own**: the task list is one of the tabs.
- **Not at all**: this case type shows no task list. This is a choice, not an
  absence: the schema-wide layout's panel does not apply.
- **As the schema-wide layout does**: nothing is declared here, so the
  schema-wide layout decides.

The panel remembers tabs by their id, so renaming a tab keeps it. Removing a tab
the panel shows beside makes the save fail and names the tab, so a panel never
quietly disappears. Buildiq serves the choice as `taskList.panel`; it renders
nothing itself, the consuming app does.

Save the screen. The consuming app asks buildiq for the most specific layout: the
type value first, then the schema-wide layout, then its own.
