---
kind: code
depends_on: []
---

# Proposal: pages-index-calendar-and-manual-order

## Why

nextcloud-vue's index page can show records on a calendar and can let each user drag rows into their own order. Both shipped on nextcloud-vue development (`e7b3a09b6`):
- calendar: `index-calendar-view-mode`, `CnIndexPage.vue:1080-1084`, `config.viewModes` and `config.calendar`;
- manual order: `case-page-and-list-as-a-place`, `page.manualOrder` read in `CnPageRenderer.vue:1385`.

buildiq's index page editor (`src/components/page-editor/IndexPageEditor.vue`) writes neither, so a built app cannot offer them.

Rows: `pg-calendar`, `data-drag-sort-rows`.

## What changes

- The index page editor gets a "Views" section: which view modes the page offers (table, cards, calendar) and, with calendar, the date field, the optional end date field and the title field, picked from the page's schema.
- The same editor gets a switch "Users can order rows by dragging", written as `manualOrder: true` on the page.

## Out of scope

- Rendering, which nextcloud-vue owns.
- A calendar page type of its own: the calendar is a view of an index page.
