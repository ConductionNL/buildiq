# Design: v2-widget-placement-editor

## Context

See `proposal.md` for motivation, and `../publish-widgets-to-nc-dashboard/design.md`
decision D8 for why the consumer of this change cannot proceed without it. The rest of this
section is the state of the code, verified by reading it, because almost every decision
below follows from what already exists rather than from what has to be built.

**The library already ships the editor.** Read in the installed package under
`node_modules/@conduction/nextcloud-vue/`:

- `src/components/CnWidgetGrid/CnWidgetGrid.vue` has an `editable` prop (around line 158).
  Its docblock: when true, the `body` slot renders a GridStack drag and resize grid instead
  of the read-only CSS grid, geometry changes are written back to the widget entries and
  emitted via `@layout-change`. The emit is declared at line 164 and fired at line 437 as
  `this.$emit('layout-change', this.widgets)`.
- `editableBody()` (around line 174) is `this.editable && this.slotName === 'body'`. Only
  the body slot is drag editable. This single line is what decision D2 exists to answer.
- `handleGridChange()` (line 412 onwards) already clamps the width to the resolved column
  count and the x to `cols - width` before writing back, so the drag path cannot produce an
  out-of-range placement on its own.
- `src/dialogs/CnAddWidgetModal.vue` is exported from the barrel (line 37 of
  `src/index.js`). It takes `show`, `preselectedType`, `editingWidget`, `surface`,
  `userAddableOnly`, `pageConfig`, `dataContext`, `uploadFn`, `fileUploadFn` and
  `calendarsFetcher`, and emits exactly two events, `close` and `submit`, the latter with
  an assembled `{ type, content, chrome }` payload for the parent to persist (line 734).
  It has no grid awareness at all: its own header comment says it does no grid work.
- The type catalogue is a library API exported from
  `src/components/CnWidgetGrid/dashboardWidgetRegistry.js`: `listWidgetTypes(surface)`,
  `listUserAddableWidgetTypes(surface)`, `getWidgetTypeEntry(type)`, `getDefaultContent(type)`.
- `resolveSlotColumns(slotName, slotColumns, propColumns)` is exported from the barrel at
  line 542, with `SLOT_COLUMNS_DEFAULTS` of `body: 12`, `sidebar: 1`, `header-actions: 12`,
  `footer: 12`, `modal: 12`, and 12 for `tab:*` and `section:*`.

**The validator's post-schema checks are the real contract.** `src/utils/validateManifest.js`
runs three checks the JSON Schema cannot express, and each is reachable from this editor:

1. `gridX + gridWidth > resolveSlotColumns(widget.slot, page.config.slotColumns)` (line 199).
   The validator calls the *same exported function* the grid calls. So must this editor.
2. A `type: "dashboard"` page with exactly one body widget at `0,0,12,12` whose
   `widgetKey` is not in `LIBRARY_BUILT_IN_WIDGET_KEYS` is rejected as a custom page in
   disguise (ADR-036 decision 1). This is the first thing an author does to an empty
   dashboard page, which is why it earns a requirement rather than a comment.
3. `id` uniqueness within a page's `widgets[]`, because the id is the delta merge key.

**The bootstrap constraints are already satisfied for this surface, and that is worth
recording rather than assuming.** The designer is routed from `src/registry.js`, which is
imported by `src/main.js`, and `src/main.js` already imports
`gridstack/dist/gridstack.min.css` (line 38) and already calls
`registerBuiltinDashboardWidgets()` (line 52), each with a comment explaining the silent
failure it prevents. `src/builder.js` does the same for the runtime. No new entry and no
new bootstrap work is needed. The reason to write this down is that both failures are
silent: an empty registry renders "Widget not available" with an empty type picker, and a
missing stylesheet renders every grid item 0px wide, and neither throws.

**The write path already has a shape to copy.** `PageDesigner.vue` is a controlled
component with one write method, `emitManifest(next)`. `onPagesUpdate(pages)` spreads the
current manifest and replaces the whole `pages` array; `onInsertWidgets(widgets)` instead
builds a `{ pages: [{ id, widgets }] }` delta and runs it through `mergeManifestDelta`.
Both already exist; decision D5 picks between them.

## Goals / Non-Goals

**Goals**

- One editor that authors the v2 `widgetEntry` for every slot, not only the one the
  library's drag grid covers.
- Every rule the validator enforces after the schema is enforced in the editor before the
  save, using the library's own functions rather than a second copy of the numbers.
- Mount what the library ships. Any component written here that the library already
  exports is a defect.

**Non-Goals**

- Removing or migrating the v1 `WidgetBuilder.vue` path. A v1 page keeps its v1 editor.
- Drag and drop in slots the library does not drag-enable. See D2.
- Editing the page `config` blocks the existing sub-editors already own. This editor owns
  `widgets[]` and nothing else.
- The `ncDashboard` promote toggle and the schema property it needs. Those belong to
  `publish-widgets-to-nc-dashboard`.
- A live preview of the page being edited. `page-designer-live-preview-pane` owns that.

## Decisions

### D1. Mount `CnWidgetGrid :editable`, do not build a grid

The body-slot canvas is `CnWidgetGrid` with `editable` set and `@layout-change` handled.
The handler takes the emitted entries, writes them onto the selected page's `widgets[]` and
emits the manifest upward. Nothing here touches GridStack.

**Why.** The library component already clamps geometry to the resolved column count on
every change, already keys entries by id with an index fallback, and is the same component
that renders the page at runtime. A hand-rolled grid would have to re-derive all of that
and would drift from the renderer, which is the failure mode where the designer shows one
layout and the app shows another.

**Note on the emit.** `handleGridChange` mutates the entries in place and then emits
`this.widgets`. So the payload is the same array object the designer passed down. The
handler must treat it as a signal to re-read and re-emit, not as a fresh value to diff
against the old one, because there is no old one left to diff against. A test asserts the
save path stores the new geometry rather than asserting an object identity that is
meaningless here.

**Alternative considered: read GridStack directly in Buildiq.** Rejected. It makes
`gridstack` a direct API dependency of this app rather than a peer the library drives, and
it duplicates the clamping.

### D2. Slots other than `body` are authored through explicit fields, not drag

**Decision.** The editor has two authoring paths over one shape. The `body` slot gets the
drag canvas from D1. Every other slot (`sidebar`, `header-actions`, `footer`, `modal`,
`tab:<id>`, `section:<id>`) gets a list of placement rows with numeric position and span
fields and a slot selector. Both paths write the same `widgetEntry`, both run the same
geometry helper from D4, and both persist through the same method from D5.

**Why.** `editableBody()` is `this.editable && this.slotName === 'body'`. Setting
`editable` on a sidebar or footer grid changes nothing: the grid renders read-only and
never emits `layout-change`. A designer that only drag-edits therefore cannot author five
of the seven slot families at all, and the failure is invisible, because the grid renders
perfectly and simply does not respond.

The list path is also the honest one for those slots. `sidebar` is schema-pinned to
`gridWidth: 1` and `header-actions` to `gridY: 0`, so in both cases dragging would offer
two degrees of freedom where one exists. A numeric row that shows only the fields the slot
actually has tells the author the truth about the slot.

The list path is the universal one, present for every slot including `body`. The canvas is
an enhancement on top of it, not a replacement, so an author who cannot use a pointer is
never locked out of an entire slot family, and neither is an automated test.

**Alternative considered: lift the body gate in the library.** The right long-term answer
and the wrong one for this change. It widens the blast radius to every consumer of
`CnWidgetGrid` in the fleet, needs a release, and the pinned geometry of `sidebar` and
`header-actions` means most non-body slots would still not want free dragging. If it lands
later, the canvas simply covers more slots and the list path stays as the accessible one.

**Alternative considered: render every slot in a synthetic `body` grid and rewrite `slot`
on save.** Rejected. It lies to the author about the layout, since a sidebar is one column
wide and a synthetic body grid is twelve, and it would produce geometry clamped against the
wrong column count, which is exactly the check the validator runs.

**This is the decision in this document least settled by evidence.** It is recorded in
`## Open Questions` as well, because it is a product call about the authoring experience
rather than a fact about the code.

### D3. Add and edit both go through `CnAddWidgetModal`

One modal for both, driven by `editingWidget`: null for add, the current placement for
edit. The modal supplies the type selector, the per-type sub-form and the appearance
fields, and emits `submit` with the assembled payload. The editor merges that payload onto
the placement's `widgetKey` and `props`, keeps the geometry the placement already has, and
assigns fresh geometry only for a new placement.

**The catalogue is the administrator one.** The editor leaves `userAddableOnly` off, so
the modal calls `listWidgetTypes(surface)`. The library's own comment explains the
distinction and it is worth restating because getting it backwards is silent: the
user-addable subset exists because a user picking a widget for their own dashboard has
neither a register nor a schema in front of them and cannot be asked for one. A page
designer is the opposite situation. An author here configures a widget once, for everybody,
with the data source in hand. Passing `userAddableOnly` would hide most of the catalogue
from the one surface that is entitled to all of it, and would do so with no error.

**The surface is derived from the page.** `surface` is `'detail-page'` for a page of type
`detail` and `'app-dashboard'` otherwise. Each registry entry carries a `surfaces` array,
so a detail-only type such as `data` is filtered out of a dashboard page's picker by the
library itself. Passing a wrong surface offers a type that will not render on the page
being edited, which is again silent.

`dataContext` is passed as `{ register, schema }` from the active page's config where the
page has one, so the data sub-form resolves the right schema. `pageConfig` is passed for
completeness; it is only read when `userAddableOnly` is on, which it is not here.

### D4. One pure geometry helper, used by both paths and by the tests

A single module owns everything geometric: the resolved column count for a slot, the clamp
of `gridX` and `gridWidth` into it, the pin of `gridY` to 0 in `header-actions`, the pin of
`gridWidth` to 1 in `sidebar`, and the default geometry for a newly added placement in a
given slot.

It resolves columns by calling the library's exported `resolveSlotColumns(slot,
page.config.slotColumns)`. Not a local constant, not a copy of `SLOT_COLUMNS_DEFAULTS`, and
not the number 12. The validator calls that exact function with those exact arguments
(`validateManifest.js` line 199); any second source of the number is a disagreement waiting
to be discovered as a rejected save on a page that declares `slotColumns`.

The helper is pure and has no Vue import, following `blockInsert.js` and
`templateCapture.js`. That is what lets the clamp be tested across the default counts and a
widened `slotColumns` in a vitest spec rather than through a browser, which is why two
scenarios in the spec carry an `@e2e exclude` naming that spec.

### D5. Persist by replacing the page's `widgets[]`, not by merging a delta

A new `onWidgetsUpdate(widgets)` on `PageDesigner.vue`, beside the existing
`onPagesUpdate`: it rebuilds the selected page as `{ ...page, widgets }`, rebuilds `pages`
with that page replaced, spreads it onto the manifest and calls `emitManifest`.

**Why not `mergeManifestDelta`, which `onInsertWidgets` uses.** The merge engine keys
`widgets[]` by `id`. That is exactly right for insert, which only ever adds freshly minted
entries, and wrong for an editor, for two reasons. A keyed merge cannot express a removal,
so delete would need a second path anyway. And an entry with no `id`, which every
schema-valid entry is allowed to be and many existing entries are, has no merge key at all,
so a delta cannot target it. Replacing the array sidesteps both and matches the pattern
`onPagesUpdate` already sets for whole-collection edits.

`onInsertWidgets` keeps its delta path unchanged. Two write paths with different semantics
is deliberate here, and the difference is stated in both docblocks so that a later tidy-up
into one shared helper has to argue with the reason rather than just the code.

### D6. Lossless round-trip: spread the entry, never rebuild it

Every write starts from the existing placement object and spreads it: `{ ...entry,
...changed }`. No code path constructs a placement from a whitelist of known keys, because
a whitelist silently drops `tabGroup`, `roles`, `visibleWhen`, `requiredApp`, `dateChip`,
`dataSource`, and every key a future library version adds, including the `ncDashboard` that
the sibling change is about to add.

Where a value becomes empty, the key is deleted rather than stored as `''`, `[]` or `{}`.
This is the discipline `DashboardPageEditor.vue`'s `update()` already documents and
implements (it deletes the key rather than storing an empty value, lines 95 to 102), and
`tests/composables/manifestRoundTrip.spec.js` is where it is enforced, against the
installed schema. Extending that suite with a placement carrying keys this editor does not
surface is a task, not a footnote: a whitelist bug passes every test that only looks at the
keys the editor knows about.

### D7. Ids come from `mintWidgetId`, and an existing id is never regenerated

Every placement the editor creates gets an id from `src/services/blockInsert.js`'s
`mintWidgetId(base, existingIds)`, seeded with the ids already on the page, exactly as
`insertBlock` seeds it. No second generator, no second kebab-case normaliser, no second
collision strategy.

An edit never regenerates an id. A stored delta override keys `widgets[]` by id, so a
changed id does not error, it stops matching, and the override silently stops applying. The
sibling change makes this sharper still, since a promoted widget's Nextcloud dashboard
identity is derived from the entry id and a user's chosen panels are stored by that
identity in an appconfig namespace no migration of ours can reach.

### D8. The seam for `publish-widgets-to-nc-dashboard`

That change adds an optional `ncDashboard` object to `widgetEntry` and hangs a per-row
promote toggle off this editor. This change adds neither, and needs nothing from the
library release that carries the property. It only has to leave room, which D6 and D7
already do: the spread preserves an `ncDashboard` written by anything else, and every
placement this editor creates already carries the stable `id` that a promoted entry
requires. The placement row is the element that gains the toggle, so it keeps a slot for
per-placement controls beyond edit and delete rather than hard-coding two buttons.

## Declarative-vs-imperative decision (ADR-031)

Not applicable, and the reason is worth one paragraph rather than one line because the word
"widget" makes it look applicable.

ADR-031 names dashboard widget behaviour among its triggers. This change introduces and
modifies none of it. No lifecycle, aggregation, derived field, notification or declarative
relation is touched, no runtime widget behaviour changes, and no rendering path changes: a
manifest written by this editor renders exactly as the identical manifest written by a
template or the copilot does today. What changes is who can write that JSON and through
what surface. The declaration itself stays where ADR-024 puts it, in the app manifest, and
this change's entire purpose is to let an author edit that declaration directly instead of
by hand in raw JSON, which moves the work toward the declarative surface rather than away
from it.

## Seed Data (ADR-001)

Not applicable. No OpenRegister schema is introduced or modified. A `widgetEntry` is a key
inside the manifest JSON blob on an existing Application object, not a property on a schema
in a register. No seed objects are required and no seed task appears in `tasks.md`.

## Risks / Trade-offs

**R1. The drag path and the field path can disagree about geometry.** Two ways to set the
same four numbers is two chances to clamp differently, and the disagreement surfaces as a
save rejected by a validator rule the author never saw. → Mitigation: D4. One pure helper
owns every geometric rule, both paths call it, and it resolves columns through the
library's own `resolveSlotColumns` rather than a local number. The vitest spec covers the
default counts and a widened `slotColumns` together.

**R2. A key the editor does not know about is dropped on save, silently.** This is the
failure mode with no error message anywhere: a `visibleWhen` or a `roles` array written by
the copilot vanishes and the page simply becomes visible to more people. → Mitigation: D6
plus an extension to `tests/composables/manifestRoundTrip.spec.js` that round-trips a
placement carrying keys this editor never surfaces. The test has to carry an unknown key on
purpose, since a test written only from the editor's own field list cannot fail this way.

**R3. The library's drag emit hands back the same array it was given.** `handleGridChange`
mutates the entries in place before emitting, so a handler that compares the payload to its
previous value sees no change and may skip the write, leaving the designer clean while the
manifest has moved. → Mitigation: the handler re-emits unconditionally, and its test drives
a geometry change through the whole save path and asserts the stored manifest, not an
in-memory diff.

**R4. The single full-width custom widget on a dashboard page.** The validator rejects it
(ADR-036 decision 1), and it is the most natural first action on an empty dashboard page:
add one widget, make it fill the grid. An editor that only reports it at save time teaches
the author nothing about why. → Mitigation: it is a requirement in the spec, surfaced where
the author is, naming both documented ways out. Hydra gate 28 remains the backstop.

**R5. `editable` silently does nothing outside the body slot.** If a later refactor points
the canvas at another slot, nothing throws, nothing logs, and the widgets simply stop
responding to drags while still rendering correctly. → Mitigation: the canvas is mounted
only for the body slot, at one place, with the `editableBody()` line quoted in the comment
beside it, and a test asserts the non-body path is the field editor.

**R6. This change is a prerequisite, so its slip is someone else's slip.**
`publish-widgets-to-nc-dashboard` cannot land its promote toggle until this exists. →
Mitigation: scope discipline. The `ncDashboard` toggle, the v1 to v2 page migration, and
lifting the library's body-slot gate are all explicitly out of scope, and none of the three
is needed for the consumer to proceed.

## Migration Plan

No data migration and no release coordination. Every existing manifest is already in the
shape this editor reads: the editor is new behaviour over data that already exists, written
until now only by templates, saved blocks and the copilot.

No library release is required. Every API this change consumes, `CnWidgetGrid`'s `editable`
prop and `layout-change` emit, `CnAddWidgetModal` with `editingWidget`, the registry
listing functions and `resolveSlotColumns`, is present in the installed version. This is
the one respect in which this change is simpler than its consumer, which does need a
release.

**Rollback.** Revert this change. The editor disappears and every placement it wrote stays
valid and keeps rendering, because it wrote nothing that only this editor can read.

## Open Questions

**Q1. Does the body slot keep both the canvas and the field rows, or does the canvas
replace the rows for that slot?** D2 chooses both, so that the field path is universal and
the canvas is an enhancement. This is a product call about the authoring experience and it
does not change the specs, the shape written, or the task breakdown: the same two paths are
built either way, and the answer only decides what the body slot shows by default. Worth
putting in front of an author on the first build rather than deciding from the code.

**Q2. Should the field path offer a slot selector for `tab:<id>` and `section:<id>` by
free text, or only offer the tab and section ids the page's config already declares?**
Offering only the declared ids prevents a placement that renders nowhere; free text allows
authoring a placement before its tab exists. The spec requires that changing a slot
re-applies that slot's rules, which holds either way. Deferrable to the build.
