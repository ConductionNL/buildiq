---
kind: code
---

## Why

OpenBuild has **version-level** rollback — `src/views/VersionHistory.vue` +
`src/modals/RollbackConfirmModal.vue` restore a whole saved
`ApplicationVersion` — but no complete **editor-level** undo/redo for the
in-flight editing session. Every OSS competitor ships builder undo/redo
(Budibase, Appsmith, ToolJet, Saltcorn, Lowcoder, Baserow 2.3); for a
visual builder it is table-stakes: a mis-drag or accidental delete should
be one keystroke away from recovery, not a full version rollback.

Verified against HEAD (`origin/development`), the current state is partial
and has real gaps:

- `src/views/PageDesigner.vue` already renders an undo/redo toolbar
  (lines 14–42), handles `Ctrl+Z` / `Ctrl+Shift+Z` / `Ctrl+Y` (+ `Cmd`
  via `metaKey`) in `onKeydown` (lines 588–600), and records every
  accepted manifest state through a **local** composable,
  `src/composables/useManifestHistory.js` — a bounded snapshot stack
  with `DEFAULT_LIMIT = 50`.
- That composable's `reset()` is **never called anywhere** (confirmed:
  `grep -rn "history.reset"` matches nothing outside the composable).
  Because `PageDesignerHost.load()` reseeds the `manifest` prop on a
  slug or `?_version=` change and the designer's deep `manifest` watcher
  pushes every prop value onto the *same* stack, a user who switches
  versions can press `Ctrl+Z` and "restore" the *other version's*
  manifest into the current draft. Likewise nothing resets on save, so
  undo silently walks the draft back past the persisted baseline.
- `onKeydown` has **no editable-target guard**: pressing `Ctrl+Z` while
  typing in a sub-editor text field fires the browser's native input
  undo *and* a manifest-level undo simultaneously (double undo).
- `src/views/SchemaDesigner.vue` has **no undo/redo at all** — its
  staged editor model (`staged` in `data()`) offers only the
  all-or-nothing "Discard staged edits" button.
- The history engine is app-local, duplicating manifest-JSON history
  logic inside OpenBuild while the shared library already owns the
  manifest structural utilities (`diffManifest` / `mergeManifestDelta`).
  Per fleet convention, shared Vue logic lives in
  `@conduction/nextcloud-vue`; the leaf utility `manifestEditHistory`
  (nc-vue change `manifest-edit-history`: a bounded undo/redo history
  over manifest JSON snapshots/deltas built on those existing utils) is
  the canonical home. This change **consumes** that leaf.

Note (verified, corrects the initial briefing): the draft state is NOT
held in a Pinia store. `PageDesignerHost.vue` holds `manifest` in
component `data()` and `SchemaDesigner.vue` holds `staged`/`persisted`
in component `data()`; the Pinia stores under `src/store/`
(`useObjectStore`, `useSettingsStore`, and `useSchemasStore` — a
`createObjectStore` wrapper in `src/store/schemas.js`) carry only CRUD
state, not the drafts. The history therefore attaches to the
component-held drafts, and the "store integration" surface to test is
the save-through-store boundary (a successful `useSchemasStore` /
ApplicationVersion PATCH save resets the session history).

## What Changes

- **Consume `manifestEditHistory` from `@conduction/nextcloud-vue`**
  (hard dependency, see Impact) as the single history engine for both
  designers, replacing the app-local
  `src/composables/useManifestHistory.js`. History depth is bounded at
  **100** entries (up from the local 50), oldest dropped on overflow.
- **Page designer** (`src/views/PageDesigner.vue`): keep the existing
  toolbar buttons (with `:disabled` bound to `canUndo`/`canRedo`) and
  keyboard shortcuts (`Ctrl+Z` undo, `Ctrl+Shift+Z` / `Ctrl+Y` redo,
  `Cmd` equivalents on macOS), rewired to the shared leaf.
- **Session boundaries (DECISION, design D3):** history is
  per-editing-session. It **survives** page selection / sub-editor
  switches (the `SUB_EDITOR_MAP` dispatch at `PageDesigner.vue:153`)
  within the session, and **resets** on successful save, on a
  `?_version=` switch, on an app-slug switch, and on publish/rollback
  re-entry (those re-enter the designer as a new session). The host
  (`src/views/PageDesignerHost.vue`) signals the reset via a session
  key; this also fixes the cross-version undo-bleed bug at HEAD.
- **Editable-target guard (design D4):** the document-level keydown
  handler ignores undo/redo chords when focus is inside a text input,
  textarea, select, or contenteditable element — the native text-field
  undo wins there; manifest-level undo applies only outside editable
  fields.
- **Schema designer** (`src/views/SchemaDesigner.vue`): add the same
  affordance over its staged editor model — undo/redo toolbar buttons
  (next to "Discard staged edits" / "Save") with disabled states, the
  same keyboard chords, history fed by the staged-model update handlers
  (`onHeaderChange`, `onFieldsChange`, `onStatesChange`,
  `onTransitionsChange`, `onRelationsChange`, `onWidgetsChange`).
  "Discard staged edits" records as **one** history entry, so a discard
  is itself undoable. History resets on successful save (through
  `useSchemasStore`), on a `schemaId` route change, and on
  app/version switch.
- **Raw JSON round-trip:** a whole-manifest replacement (the shape a
  raw-JSON editing surface produces — cf.
  `src/components/tabs/ApplicationManifestTab.vue`) lands as exactly
  **one** history entry: one undo restores the full pre-edit manifest.
  The engine's per-accepted-state push semantics guarantee this; it is
  specified and tested so it cannot regress.
- **Migrate/replace** `tests/composables/useManifestHistory.spec.js`
  to cover the integration with the shared leaf instead of the deleted
  local composable.
- **No server-side changes.** No routes, controllers, schemas, or
  migrations — this is a pure frontend change; undo/redo never issues a
  network request.
- No BREAKING changes: toolbar, shortcuts, and controlled-component
  contract (`manifest` prop in, `update:manifest` out) are preserved.

## Capabilities

### New Capabilities

- `builder-undo-redo`: per-editing-session, bounded (depth 100)
  undo/redo for the page designer and schema designer drafts, powered
  by nc-vue's `manifestEditHistory` leaf — toolbar buttons with
  disabled states, `Ctrl+Z` / `Ctrl+Shift+Z` (+ `Ctrl+Y`, `Cmd`
  equivalents) with an editable-target guard, survival across
  sub-editor switches, reset on save / publish / version switch, and
  single-entry semantics for whole-manifest (raw JSON) replacements.

### Modified Capabilities

_None._ The `openbuild-page-designer` capability's existing toolbar
requirement surface is preserved unchanged (same buttons, same chords);
this change relocates the engine underneath it and adds the session
semantics as a new capability rather than rewriting that spec.

## Impact

- **Hard dependency:** a published `@conduction/nextcloud-vue` **beta**
  release containing `manifestEditHistory` (nc-vue change
  `manifest-edit-history` — bounded undo/redo history over manifest
  JSON snapshots/deltas built on the existing `diffManifest` /
  `mergeManifestDelta` utils). Per fleet convention, nc-vue leaves
  publish to the **beta dist-tag first**; OpenBuild currently pins
  `"@conduction/nextcloud-vue": "^1.0.0-beta.168"`
  (`package.json`) and must bump to the beta that exports the leaf.
  All implementation tasks are blocked until that export is confirmed
  in the installed `node_modules` (verify against the installed
  version, not assumptions).
- **Files touched (frontend only):** `src/views/PageDesigner.vue`,
  `src/views/PageDesignerHost.vue`, `src/views/SchemaDesigner.vue`,
  `src/composables/useManifestHistory.js` (deleted/replaced),
  `package.json` (dependency bump),
  `tests/composables/useManifestHistory.spec.js` (migrated), new Vitest
  specs, new `tests/e2e/builder-undo-redo.spec.ts`.
- **No backend impact:** no `appinfo/routes.php`, `lib/`, or register
  schema changes; hydra route/auth gates are unaffected by design.
- **E2e context:** the existing designer Playwright suites
  (`tests/e2e/page-designer.spec.ts`, `tests/e2e/schema-designer.spec.ts`)
  are quarantined under Conduction/openbuild#41 (`test.describe.skip`);
  the new `builder-undo-redo.spec.ts` follows the same seed/global-setup
  conventions and inherits that quarantine status until #41 is fixed.
- **i18n:** new user-facing strings (schema-designer Undo/Redo labels
  and tooltips) follow the standard `t('openbuild', …)` flow with
  English keys.
