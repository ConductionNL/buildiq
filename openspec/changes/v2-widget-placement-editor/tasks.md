# Tasks: v2-widget-placement-editor

Most of this is wiring. `CnWidgetGrid :editable`, `CnAddWidgetModal` and the widget type
registry already exist in the installed `@conduction/nextcloud-vue` (design.md, Context),
so the work is mounting them, feeding them the selected page's `widgets[]`, and persisting
what comes back. Nothing below builds a grid, a type picker or a validator.

## 1. The pure rules

- [x] 1.1 Add the slot geometry helper (resolved columns via the library's exported `resolveSlotColumns`, clamp of `gridX`/`gridWidth`, `gridY` pinned to 0 in `header-actions`, `gridWidth` pinned to 1 in `sidebar`, default geometry per slot) with no Vue import, and verify a vitest spec covers the default counts and a page declaring a widened `config.slotColumns`
- [x] 1.2 Add the page-level checks the editor has to surface (the widget surface for a page type, and the lone full-width custom widget on a `dashboard` page) as pure functions, and verify a vitest spec covers a library built-in key as exempt and a custom key as flagged

## 2. The editor

- [x] 2.1 Add the placement panel: the selected page's `widgets[]` grouped by slot, with add, edit and delete per row, and verify a vitest spec covers the empty state, add, edit and a delete that leaves the other entries untouched
- [x] 2.2 Add the field path for every slot (slot selector, numeric position and span, only the fields the slot actually has) running every value through task 1.1, and verify a vitest spec covers a sidebar placement and a slot change that re-applies the target slot's rules
- [x] 2.3 Mount `CnWidgetGrid` with `editable` for the `body` slot only and handle `@layout-change` by re-emitting unconditionally, and verify a vitest spec feeds a layout-change payload through the save path and asserts the stored geometry (not an in-memory diff, per design.md R3)
- [x] 2.4 Wire `CnAddWidgetModal` for both add and edit (`editingWidget` for edit, `surface` from the page type, `userAddableOnly` left off so the modal calls `listWidgetTypes`, `dataContext` from the page's register and schema), and verify a vitest spec asserts those props and that a detail-only type is absent on a dashboard page
- [x] 2.5 Collect `_note` as a required field on a page of type `custom`, blocking confirm while empty and saying what the note is for, and verify a vitest spec covers a custom page blocking and a dashboard page writing no `_note` key
- [x] 2.6 Surface the lone full-width custom widget on a dashboard page from task 1.2 where the author is, naming both documented ways out, and verify a vitest spec asserts the message appears and names both

## 3. Persistence

- [x] 3.1 Add `onWidgetsUpdate(widgets)` to `PageDesigner.vue` (rebuild the selected page as `{ ...page, widgets }`, replace `pages`, spread onto the manifest, `emitManifest`) with a docblock stating why this is a whole-array replace and not the `mergeManifestDelta` path `onInsertWidgets` uses, and verify a vitest spec asserts the emitted manifest
- [x] 3.2 Mint ids for every placement the editor creates through `blockInsert.js`'s `mintWidgetId`, seeded from the page's current ids, and never regenerate an existing id, and verify a vitest spec covers two placements of one type and an edit that keeps its id
- [x] 3.3 Spread every placement on write and delete a key rather than storing an empty value, and verify `tests/composables/manifestRoundTrip.spec.js`, extended with a placement carrying keys this editor never surfaces, round-trips them unchanged against the installed schema
- [ ] 3.4 Mount the placement panel in the designer's centre pane beside `WidgetSelectionPanel` (which keeps "save as block") and verify the designer loads and saves a page end to end in the browser

## 4. Copy, access and verification

- [ ] 4.1 Load the hydra `writing` skill and write every string on this surface through it, and verify no em-dash and no Title Case survives in the added strings
- [ ] 4.2 Verify the whole editor is operable without a pointer: every `NcSelect` carries an `inputLabel`, the field path reaches add, edit and delete by keyboard, and no slot family is drag-only
- [ ] 4.3 Add `tests/e2e/widget-placement-editor.spec.ts` covering every scenario that carries an `@e2e` reference in the delta spec, and verify gate 19 reports no unreferenced added scenario
- [ ] 4.4 Run `hydra/scripts/diff-check.sh` green on the diff, then `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict` and `npm run lint` once before push

## Acceptance criteria

- An author can add, edit, reposition, reorder and delete a v2 `widgets[]` placement in every slot without touching raw JSON.
- Drag and resize works in the `body` slot; every other slot is fully authorable through fields.
- No geometry the editor writes can fail `validateManifestV2()`: `gridX + gridWidth` stays within the slot's resolved column count, `header-actions` keeps `gridY` at 0, `sidebar` keeps `gridWidth` at 1.
- A page of type `custom` cannot receive a placement without a `_note`.
- A lone full-width custom widget on a `dashboard` page is reported in the editor, not as a rejected save.
- Every placement the editor creates carries a unique kebab-case `id` minted by `mintWidgetId`; an existing id is never regenerated.
- Opening a page and saving it unchanged leaves the manifest byte-for-byte identical, including keys the editor does not surface.
- The type picker offers the administrator catalogue, filtered to the page's surface.
- Nothing in this change adds `ncDashboard` or its toggle, and nothing in it makes adding them awkward.
