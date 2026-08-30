# Design — buildiq-walkthrough-editor

## Builds on
- `cn-walkthrough-engine` (change 1): the `manifest.walkthrough` schema + the
  `data-walkthrough-id` targeting convention this editor writes.
- ADR-041 universal in-app editing: the orange edit shell + mode switcher.
- `layered-versioned-app-deltas` + `app-override-persistence`: per-app manifest
  delta storage and `ApplicationVersion` assignment.

## Flow

1. Owner opens an Buildiq virtual app, enters edit mode, picks **Edit walkthrough**.
2. The recorder overlay arms: hovering highlights resolvable targets; clicking one
   creates a step and opens the step editor panel.
3. **Target resolution priority:** the clicked element is walked up to the nearest
   element carrying a manifest identity — a menu item's route (`nav-item`), a
   widget's `widgetKey` (`widget`), an action's id (`action`), or a page region
   (`page`). If none, Buildiq injects/uses a `data-walkthrough-id` and records
   `element`; only if that is impossible does it fall back to a CSS `selector`
   (flagged as brittle in the UI).
4. **advanceOn recording:** while a step is being recorded, Buildiq watches the
   router and the OR object store. If the owner navigates, it suggests
   `route-match` (+ `capture` for an `:id` param); if a new object is created, it
   suggests `object-created` with that register/schema. The owner confirms/edits.
5. **Step fields:** title, body, task, placement, advanceOn, optional/allowManualNext,
   sinceVersion (defaults to the app's current `ApplicationVersion`).
6. **Reorder / tour management:** drag to reorder; add/rename/delete tours.
7. **Persist:** on save, the `walkthrough` block is merged into the app's manifest
   delta via the existing Buildiq persistence endpoint and tagged to the chosen
   `ApplicationVersion`. Live preview reloads `useWalkthrough` so the owner can run
   the tour immediately.

## Setup-block reuse

The same step-editor panel renders an alternate field set when editing
`manifest.setup` steps (type: info/choice/config-fields/run-action/summary),
writing to the `setup` block of the same delta. One editor, two manifest blocks.

## Validation

Authored blocks are validated against the canonical v2 schema (the
hydra-vendored copy) before persist — an invalid tour can't be saved. Brittle
`selector` targets and missing `sinceVersion` raise non-blocking warnings.

## Files (indicative)
```
src/components/edit/WalkthroughEditorMode.vue     # recorder overlay + arming
src/components/edit/WalkthroughStepPanel.vue      # per-step field editor
src/components/edit/WalkthroughTourList.vue       # tour add/rename/delete/reorder
src/services/walkthroughTargetResolver.js         # element → stable target
src/services/walkthroughRecorder.js               # route/store observation → advanceOn
# persistence reuses the existing manifest-delta service + version assignment
```
