---
kind: code
---

# Proposal: pages-detail-related-items

## Why

buildiq matrix, row `pg-detail` ("Add a detail page that shows one record with its fields, related items and files.", buildiq rated `partial`, built.state `built`): "src/components/page-editor/DetailPageEditor.vue (504 lines) authors config.register/schema/sidebar(+tabs)/pageLayout for type:\"detail\" ... DetailPageEditor.vue has NO fields for headerActions, lifecycleActions or widgets". The row's note: "header actions, related-item widgets and files on a detail page have no authoring UI in DetailPageEditor.vue, so the row's 'related items and files' half is not reachable from the designer for this page type".

The row came to buildiq from nextcloud-vue. The nextcloud-vue OpenSpec-pass lane recorded `pg-detail` as `existing` for the library ("CnDetailPage renders header and lifecycle actions, related collections, related-object widgets and files. The missing half is buildiq's DetailPageEditor, which has no fields for them") in its `openspec/parity/gap-decisions.json` (nextcloud-vue PR #1269). The sibling pass then moved the row's `built.owner` to `ConductionNL/buildiq` (buildiq PR #989).

Part of that half is already specified. The open change `pages-action-buttons` adds a Buttons section (`headerActions`) and a Status buttons section (`lifecycleActions`) to the detail page editor. This change covers the rest: related items and files.

Four competitors rate the row yes, quoted from the matrix:

- NocoBase: "DetailsBlockModel.tsx:396 details block for one record ... in a popup or page that also takes association table blocks and attachment fields".
- Budibase: "'rowexplorer' (Row Explorer Block) ... relationship and attachment fields render related items and files".
- Mendix: "pages hold data views showing one object with its attributes, and nested data widgets for associated objects" (https://docs.mendix.com/refguide/page/).
- Power Apps: "forms on tables, with relationships that permit navigation between related records" (https://learn.microsoft.com/en-us/power-apps/maker/model-driven-apps/model-driven-app-overview).

No tender, featureRequest or roadmap row carries it.

## What changes

- The detail page editor gets a Related items section. A maker adds a list of related records: a title, the register and schema they live in, the field on them that points at this record, and optionally the columns and how many rows to show.
- In the same section a maker adds link buttons, which let an app user link an existing record of another schema to this one.
- The Sidebar section gets a Files choice, on by default, that shows or hides the record's files tab.

## Rows this closes

| matrix | row id | row name | own rating | what is missing |
|---|---|---|---|---|
| buildiq | pg-detail | Add a detail page that shows one record with its fields, related items and files. | partial | authoring related records and files on a detail page (buttons are `pages-action-buttons`) |

## Sibling halves

None. nextcloud-vue 2.57.1 renders `relatedCollections`, `relationLinks` and the sidebar files tab from the page config.

## Out of scope

- Header and status buttons: `pages-action-buttons`.
- Arbitrary in-body sections from registered components (`bodyWidgets`); `pages-custom-code` covers custom components.
