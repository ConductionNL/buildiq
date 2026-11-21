# Design: pages-action-buttons

Read at buildiq development `d21e42f`, nextcloud-vue 2.57.1 as installed.

## What exists

- `src/components/page-editor/fields/ActionBuilder.vue` (header comment: "authors
  the `action` $def. Used by IndexPageEditor") renders per action an id, a label,
  an icon and a `target` select with `navigate`, `emit` and `none`. It is mounted
  by `IndexPageEditor.vue:75-80` on `config.actions`.
- `src/components/page-editor/DetailPageEditor.vue` has no action field; it
  ends with `AppliesToPanel`, `ScreenOverrideList` and `RegistrationFormList`
  (lines 138-156).
- nextcloud-vue 2.57.1:
  - `src/schemas/app-manifest-v2.schema.json` `$defs.action`: `type` enum
    `handler`, `open-modal`, `open-page`, `navigate`, `object-op`, `export`,
    `open-form`, `refresh`, `api-call`, `agent`, `run-node`, `toggle`, with
    `additionalProperties: false` and per-type keys (`op`, `values`, `route`,
    `url`, `flowId`, `nodeId`, `confirm`, `visibleWhen`, and others). `target`
    is the modal id of `open-modal`, not a behaviour.
  - `CnDetailPage.vue:1542` `lifecycleActions` (`{field, transitions?, autoFetch}`,
    transitions fetched from OpenRegister's `/available-actions` when not
    listed) and `:1567` `headerActions` (rendered in the header's Actions menu by
    `CnActionButtons`).
- The form editor's `VisibleWhenBuilder.vue` authors `{field, op, value}`
  predicates with the closed operator set.

## D1. One typed action editor, reused on both pages

`ActionBuilder.vue` is rewritten around the `type` discriminator. Each type has a
small sub-form: `object-op` (operation patch, the fields and values to set, an
optional confirm text), `run-node` (a flow from the app's declared `flows`, then
a node of it), `open-form` (a schema), `open-page` (a page of this app),
`navigate` (a URL), `export` (formats). Types that need code (`handler`) or
another app (`agent`, `api-call`) are shown read-only when already present and
are not offered for new actions. The editor validates each action against
`$defs.action` with the manifest validator the page designer already runs.

## D2. Status buttons are transitions, not field patches

Moving a record to another status goes through `lifecycleActions` so
OpenRegister's lifecycle decides what is allowed. The "Status buttons" section
defaults to `{field: 'status'}` (live transitions) and lets the maker list
transitions with their own labels and confirm texts instead. A plain
`object-op` patch of a lifecycle field is refused in the editor with a pointer
to status buttons, because it would bypass the lifecycle's guards.

## D3. Old actions migrate on read

`target: navigate` becomes `type: open-page` when the value names a page route,
otherwise `navigate`; `emit` becomes `handler` (kept read-only); `none` is
dropped with a note. The rewrite happens when the editor loads the page, and is
saved only when the maker saves.

## D4. Conditions reuse the form predicate

`visibleWhen` on an action is authored with `VisibleWhenBuilder.vue`, so the
operator set is the one the schema allows (ADR-085's single predicate grammar).

## Risks

- A mis-authored `object-op` can change many records from an index page's mass
  actions. The editor asks for a confirm text on any delete or patch offered as
  a mass action.
- A flow picked for `run-node` may be removed later; the editor marks an action
  whose flow no longer exists.

## What it does not do

- It adds no action type to the manifest schema.
- It runs no code a maker wrote.
