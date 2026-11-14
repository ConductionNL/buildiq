---
kind: code
depends_on: []
---

# Proposal: pages-widget-grid-drag-and-resize

## Why

Capability row `pg-drag-drop-canvas` (drag components onto a canvas and position them) is built: in a published app, Edit with Buildiq makes the widget grid of a dashboard page and of a detail-page body draggable and resizable, and Save writes the layout back to the manifest. No requirement says so. The row cited `openbuild-page-designer`, whose nearest requirement drags menu entries, not widgets. The board BqPaginaOntwerper draws the behaviour ("Sleep een widget aan de greep").

## What changes

- Adds one requirement to `openbuild-page-designer`: in Edit with Buildiq mode the widget grids are draggable and resizable on a column grid, with keyboard moves, and Save writes the new positions to the manifest.
- Tags the code that carries it and adds the e2e test that proves it.
- Records the requirement on row `pg-drag-drop-canvas` in `openspec/parity/capabilities.json`.

The grid comes from nextcloud-vue (`CnDashboardGrid` on GridStack, `CnDashboardPage`, `CnDetailPage`); buildiq supplies the editor mode and the save (`src/builder.js` `persistManifestDelta`). Positioning is on the grid, not at free pixel positions; that matches how the compared builders that rate yes (Budibase, Appsmith) place components.

## Out of scope

- The numeric position fields of the page designer's own dashboard editor (`LayoutItemBuilder.vue`); they stay as the precise alternative.
- Free pixel placement.
