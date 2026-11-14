# Design: pages-task-panel-beside-case-tabs

Read at buildiq development `d21e42f`.

## Where it sits

- Schema: `lib/Settings/register.d/51-page-layouts.json`. `taskList` is declared
  at lines 367-435 with `columns[]` and `searchFields[]`; `tabs[]` at line 118,
  each tab with a stable `id` (line 124, "the key an override patches by").
- Served: `lib/Integration/PageLayoutLeafProvider.php:207` `list()` composes the
  published layers and hands the result to
  `lib/Service/PageLayoutPresenter.php:56` `serve()`, which returns
  `taskList` through `declaredPart()` (line 67, fall-through at lines 88-100).
- Authored: `src/components/page-editor/DetailPageEditor.vue:138-144` mounts
  `AppliesToPanel` (`src/components/page-editor/fields/AppliesToPanel.vue`),
  which saves the binding and leaves tabs alone (its header comment, lines
  5-12). The task-list panel is task 6.5 of `case-page-layout-per-case-type`
  and does not exist yet; this change adds two controls to it.

## D1. The panel belongs to `taskList`, not to `tabs[]`

The placement describes the task list, so it lives on `taskList` as
`taskList.panel = {placement, tabs[], collapsed}`. That keeps the presenter
unchanged in shape: `declaredPart()` already serves `taskList` whole, and a type
layout that declares a `taskList` replaces the schema-wide one whole, the same
rule the header follows. A `panel` on each tab was considered and rejected: it
would make "show the panel on every tab" a change to every tab, and a new tab
would silently lack it.

## D2. Tab ids, never labels

`panel.tabs[]` holds tab ids. A relabelled tab keeps its panel; a removed tab
makes the save fail with the unknown id named, so the handler never gets a panel
that quietly vanished. The check runs in the save path that
`PageLayoutAuthoringService` already uses for the layout, not only in the
editor, so an API write is held to it too.

## D3. `none` is a real value

`placement: none` tells the consumer to show no task list on this case type,
which differs from declaring nothing (fall back to the schema-wide layout). The
presenter already keeps that difference, because `declaredPart()` falls through
only when the part is absent.

## D4. Overrides patch it by key

An audience override (`screen-overrides-as-a-patch-with-fall-through`) patches
`taskList.panel` like any keyed part, so a team can fold the panel for itself
without copying the layout.

## Risks

- The panel is only useful once dossiq reads it. Until then the served key is
  inert, which is the intended fall-back and not a failure.
- A layout with many tabs makes the checklist long. The checklist lists tabs in
  the layout's order and says "every tab" when none is ticked.

## What it does not do

- It renders nothing in buildiq's own runtime; the consumer renders.
- It adds no task data, no task actions and no per-user placement.
