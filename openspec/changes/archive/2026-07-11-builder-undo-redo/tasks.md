## 0. Prerequisite (hard dependency — verify against HEAD, do not assume)

- [x] 0.1 Confirm the published `@conduction/nextcloud-vue` **beta**
      release containing `manifestEditHistory` (nc-vue change
      `manifest-edit-history`): bump `package.json` from
      `^1.0.0-beta.168` to that beta, run `npm install`, and verify the
      export and its exact surface (push/undo/redo/reset, reactive
      canUndo/canRedo, depth option, structural-identity no-op on push)
      in the installed
      `node_modules/@conduction/nextcloud-vue` — read the actual
      installed source, not this change's assumptions. BLOCK all tasks
      below until confirmed; if the surface differs, adapt only the
      integration seam in task 1.1.
      **Verified against installed beta.173**: the leaf ships
      `createManifestEditHistory` (`src/utils/manifestEditHistory.js`)
      and `useManifestEditHistory` (`src/composables/useManifestEditHistory.js`),
      both exported from the package root. Its actual surface is
      `{ push, undo, redo, clear, canUndo, canRedo, current, size }` —
      **no constructor-time seed and no `reset(state)`** (only `clear()`,
      which empties the stack entirely). Adapted at the integration seam
      per the note above: `src/composables/useSessionHistory.js` wraps
      the leaf and implements `reset(state)` as `clear()` + `push(state)`,
      and seeds the session with one `push(initial)` on creation. Depth
      defaults to 100 on the leaf already; the seam also accepts an
      explicit `limit` option (REQ-BUR-007).
- [x] 0.2 Re-verify at current HEAD that
      `src/composables/useManifestHistory.js` still has no `reset()`
      callers and that `src/views/PageDesigner.vue` still wires
      `useManifestHistory` in `setup()` and pushes from the deep
      `manifest` watcher — the rewire in section 1 assumes exactly that
      wiring.
      **Confirmed** — both were still true at this branch's base
      commit (`grep -rn "history.reset"` had zero hits outside the
      composable itself; `PageDesigner.vue` wired `useManifestHistory`
      in `setup()` and pushed from the deep `manifest` watcher).

## 1. Page designer — rewire to the shared engine (REQ-BUR-001, REQ-BUR-002)

- [x] 1.1 In `src/views/PageDesigner.vue` `setup()`, replace
      `useManifestHistory(props.manifest)` with `manifestEditHistory`
      from `@conduction/nextcloud-vue`, configured with depth 100
      (REQ-BUR-007) and seeded with the incoming `manifest` prop; keep
      the returned surface shape (`push`, `undo`, `redo`, `reset`,
      `canUndo`, `canRedo`) consumed by the existing computed
      `canUndo`/`canRedo` and methods `undo()`/`redo()`.
      Implemented via the `useSessionHistory` seam (see task 0.1 note)
      rather than calling the leaf's `useManifestEditHistory` directly —
      the seam is the single adaptation point for the `reset()`
      surface mismatch.
- [x] 1.2 Keep the deep `manifest` watcher's `history.push(m)` feed and
      confirm the structural-identity no-op still absorbs the host's
      echoed prop updates (no double entries per edit).
      Confirmed via `tests/views/PageDesigner.undo-redo.spec.js`
      ("re-emitting an undone state does not re-push it").
- [x] 1.3 Keep the toolbar Undo/Redo buttons and their
      `:disabled="!canUndo"` / `:disabled="!canRedo"` bindings and
      shortcut-naming `title` tooltips unchanged (REQ-BUR-002).
- [x] 1.4 Add a `sessionKey` String prop to `PageDesigner.vue`; watch it
      and on change call `history.reset(this.manifest)` so a key change
      re-baselines the session with both buttons disabled (REQ-BUR-004).
- [x] 1.5 Delete `src/composables/useManifestHistory.js` once nothing
      imports it (`grep -rn "useManifestHistory" src/ tests/` must come
      back empty apart from the migrated test of task 4.1).
      Deleted along with its spec; `grep` confirmed clean.

## 2. Page designer host — session boundaries (REQ-BUR-004)

- [x] 2.1 In `src/views/PageDesignerHost.vue`, add a `saveCounter` data
      field incremented after each **successful** save (both the
      ApplicationVersion PATCH branch and the Application PUT fallback
      in `save()`), and compute
      `sessionKey = \`${routeSlug}:${versionSlug || ''}:${saveCounter}\``.
- [x] 2.2 Pass `:session-key="sessionKey"` to `<PageDesigner>` so slug
      change, `?_version=` switch, and successful save each reset the
      history seeded with the then-current manifest (fixes the HEAD
      cross-version undo-bleed; publish/rollback re-entry arrives via
      these same boundaries).
      **Deviation (pre-existing gap fixed, in scope):** verified against
      HEAD that the `versionSlug` watcher only called `resolveVersion()`
      and never `load()` — a `?_version=` switch never actually reloaded
      the displayed manifest at all (worse than the "bleed" the design
      describes). Fixed by also calling `this.load()` from that watcher,
      mirroring the existing `routeSlug` watcher's pattern, so the
      session-key reset in task 2.2 has a real manifest change to reset
      against. Covered by a new PageDesignerHost.spec.js test.
- [x] 2.3 Verify a failed save does NOT increment `saveCounter` (the
      session — and its undo stack — survives a save error).

## 3. Keyboard handling — editable-target guard (REQ-BUR-003)

- [x] 3.1 In `src/views/PageDesigner.vue` `onKeydown`, before matching
      chords, return early when the event target (or
      `document.activeElement`) is an `<input>`, `<textarea>`,
      `<select>`, or `contenteditable` element, so native text-field
      undo wins while typing; keep `metaKey` (`Cmd`) equivalent to
      `ctrlKey` and keep `preventDefault()` only on consumed chords.
- [x] 3.2 Extract the guard as a small shared helper (e.g.
      `src/utils/isEditableTarget.js`) so the schema designer's handler
      (task 5.4) reuses it instead of duplicating the rule.

## 4. Unit tests — engine integration seam (Vitest)

- [x] 4.1 Migrate `tests/composables/useManifestHistory.spec.js` to a
      seam spec (e.g. `tests/composables/manifestEditHistory.seam.spec.js`)
      exercising the leaf as OpenBuild consumes it: push/undo/redo
      round-trip, identical-state push no-op, redo-tail truncation on
      new edit, `reset()` re-baselining, never-issues-network (no axios
      calls), depth-100 bound with oldest-entry trimming and
      trim-safe redo (REQ-BUR-001, REQ-BUR-006, REQ-BUR-007 — includes
      the one-entry whole-manifest-replacement and
      invalid-input-records-nothing contracts).
      14 tests, all green. Also required updating the Vitest alias stub
      `tests/vitest/stubs/conduction-nextcloud-vue.js` to re-export the
      leaf's real `createManifestEditHistory`/`useManifestEditHistory`
      (previously only NcButton-style/no-op stubs existed there) —
      worked around a reproducible Vite/Vitest SSR-transform bug where a
      bare `export { X } from '...'` re-export of these two specific
      bindings resolved to `ReferenceError: X is not defined` at call
      time; wrapping them in local function declarations avoids it.
- [x] 4.2 Add `tests/vitest/views/pageDesignerUndoRedo.spec.js`: mount
      `PageDesigner.vue`; assert toolbar disabled states in a fresh
      session and after edit/undo (REQ-BUR-002); assert `update:manifest`
      re-emission on undo/redo; assert a `sessionKey` prop change resets
      the stack (REQ-BUR-004); assert history survives a `selectPage`
      sub-editor switch (REQ-BUR-004).
      **Deviation:** extended the existing, passing
      `tests/views/PageDesigner.undo-redo.spec.js` in place instead of
      adding a new file at the `tests/vitest/views/` path — that file
      already covered the pre-existing toolbar/keydown scenarios end to
      end; duplicating it under a second path would have forked
      coverage. New cases: sub-editor-switch survival, sessionKey reset
      (and no-op on an unchanged key), Cmd (`metaKey`) parity, and the
      editable-target guard (input + contenteditable). 16 tests total,
      all green.
- [x] 4.3 In the same spec (or a keydown-focused sibling), dispatch
      synthetic keydown events at the handler: `ctrlKey` chords drive
      undo/redo; `metaKey` (`Cmd`) chords behave identically
      (REQ-BUR-003 Cmd scenario — the Vitest side of that requirement's
      `@e2e exclude`); chords targeting an `<input>`/`<textarea>`/
      contenteditable are ignored and not `preventDefault`ed.
      Folded into the same extended spec (see 4.2).
- [x] 4.4 Add `tests/vitest/views/schemaDesignerUndoRedo.spec.js`
      (store-integration seam): mount `SchemaDesigner.vue` with
      `useSchemasStore` mocked; assert each staged `on*Change` commit is
      one history entry; assert undo restores the staged model without
      any store call; assert `discardChanges()` is one undoable entry;
      assert a mocked successful `store.saveObject` resolution resets
      the history while a rejected save leaves it intact (REQ-BUR-005).
      11 tests, all green. Also covers `onAccessChange` (see task 5.2
      deviation) and the list-mode `onKeydown` no-op.

## 5. Schema designer — staged-model undo/redo (REQ-BUR-005)

- [x] 5.1 In `src/views/SchemaDesigner.vue`, instantiate the same
      `manifestEditHistory` engine (depth 100) over the staged editor
      model, seeded when `loadDetail()` stages a schema.
- [x] 5.2 Route every staged mutation (`onHeaderChange`,
      `onFieldsChange`, `onStatesChange`, `onTransitionsChange`,
      `onRelationsChange`, `onWidgetsChange`) and `discardChanges()`
      through one commit helper that replaces `this.staged` and pushes
      the new snapshot — discard thereby becomes one undoable entry.
      **Deviation (reconciled with HEAD):** also routed `onAccessChange`
      through the same `commitStaged()` helper. The design/spec's
      handler list predates `data-scopes-authoring` (merged after this
      spec was written), which added `staged.access` and its
      `onAccessChange` handler as a normal staged-model mutation;
      leaving it out of undo/redo would be an inconsistent gap in the
      same feature — every other staged mutation is undoable, access
      edits would silently not be.
- [x] 5.3 Add Undo/Redo `NcButton`s (tertiary) beside "Discard staged
      edits" in the detail-header actions, disabled via
      `canUndo`/`canRedo`, with shortcut-naming `title`s and
      `t('openbuild', …)` English-keyed labels.
      Uses `vue-material-design-icons`' `Undo.vue`/`Redo.vue` for the
      button icons, matching the existing `ArrowLeftIcon` convention on
      this view.
- [x] 5.4 Add the document-level keydown handler (mounted/beforeDestroy,
      detail mode only) reusing the task 3.2 guard helper; undo/redo
      re-assign `this.staged` from the returned snapshot.
      The listener is attached unconditionally in `mounted()`/removed in
      `beforeDestroy()` (this view mounts once for both list and detail
      routes); `onKeydown` itself bails out when `!this.schemaId ||
      !this.staged`, which is functionally "detail mode only" without
      needing to add/remove the listener on every route change.
- [x] 5.5 Reset the history on: successful `save()` (after the store
      PUT resolves, re-seeded from the freshly staged `bodyToStaged`
      result), the existing `schemaId` watcher, and the
      `appSlug`/`versionSlug` watchers (REQ-BUR-004 semantics; a failed
      save keeps the stack).
      The `schemaId` reset lives inside `loadDetail()`'s `finally` block
      (covers the watcher, the initial `mounted()` call, and every
      early-return branch — not-found / load error / no schema selected
      all reset to a sensible baseline); `appSlug`/`versionSlug`
      watchers reset explicitly.

## 6. Docs

- [x] 6.1 Update `docs/integrator-guide.md` (designer sections): the
      undo/redo affordance in both designers, the shortcut table
      (`Ctrl+Z`, `Ctrl+Shift+Z`, `Ctrl+Y`, `Cmd` equivalents), the
      editable-field rule (native text undo wins while typing), and the
      session boundary — history resets on save/publish/version switch;
      restore across saves is Version History's job.
      Added a new "Editing session: undo/redo" section (the doc
      otherwise predates the visual designers and does not have a
      pre-existing "designer sections" home for this).

## 7. Playwright e2e (tests/e2e/builder-undo-redo.spec.ts)

Follow the existing designer-suite conventions (globalSetup storage
state, hello-world seed, `test.describe` wrapper carrying the
Conduction/openbuild#41 quarantine note like
`tests/e2e/page-designer.spec.ts` until #41 is fixed). One test per
scenario, named with the requirement ID.

- [x] 7.1 REQ-BUR-001 "Undo restores the previous draft state" +
      "Redo re-applies an undone edit": open
      `/apps/openbuild/builder/hello-world/pages` (designer host), add
      a page, click Undo → page gone, click Redo → page back; assert no
      PATCH/PUT fired during undo/redo via `page.on('request')`
      capture.
      Written against `pw-undo-redo` (a dedicated create-if-not-present
      app, matching the newest designer e2e convention). NOT run against
      the shared dev instance per the task brief; `--list` confirms it
      parses and collects correctly. Quarantined under #41 like every
      sibling designer suite.
- [x] 7.2 REQ-BUR-001 "A new edit after undo truncates the redo tail":
      edit, undo, make a different edit, assert the Redo button is
      disabled.
- [x] 7.3 REQ-BUR-002 "Both buttons disabled in a fresh session" +
      "Buttons enable and disable as the stack moves": assert disabled
      states on load, after one edit, and after one undo.
- [x] 7.4 REQ-BUR-003 "Ctrl+Z / Ctrl+Shift+Z drive undo and redo outside
      fields": blur into the page body, `keyboard.press('Control+z')`
      and `Control+Shift+z` (plus `Control+y`), assert the draft moves.
- [x] 7.5 REQ-BUR-003 "Ctrl+Z inside a text field leaves draft history
      untouched": after a draft-level edit, focus a sub-editor text
      input, type, press `Control+z`, assert the earlier draft-level
      edit is still applied.
- [x] 7.6 REQ-BUR-004 "History survives a sub-editor switch": edit page
      A, select page B (different `SUB_EDITOR_MAP` type), undo, assert
      page A's edit reverted.
- [x] 7.7 REQ-BUR-004 "Save resets the session history": edit, save via
      the host "Save pages" button, wait for the saved toast, assert
      Undo/Redo disabled and `Control+z` leaves the draft unchanged.
- [x] 7.8 REQ-BUR-004 "Version switch resets the session history": edit
      version X's draft, navigate with `?_version=` to another seeded
      version (reuse the `versionRouting.spec.ts` seed approach),
      assert Undo disabled and no state of version X reachable.
      Skips gracefully (`test.skip`) when no "staging" ApplicationVersion
      is seeded for `pw-undo-redo`, matching `versionRouting.spec.ts`'s
      9.1/9.3 precondition-skip convention.
- [x] 7.9 REQ-BUR-005 "Undo restores a staged field edit" + "Discard
      staged edits is one undoable entry" + "Schema save resets the
      schema session history": in
      `/apps/openbuild/builder/hello-world/schemas/:schemaId`, add a
      field → undo → field gone; re-add → Discard → undo → staged edits
      back; save → Undo/Redo disabled.
      Written against a dedicated `undo-redo-record` schema on
      `pw-undo-redo` (create-if-not-present), combining all three
      sub-scenarios into one test per the existing schema-designer e2e
      style.
