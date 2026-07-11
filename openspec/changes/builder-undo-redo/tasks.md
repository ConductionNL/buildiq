## 0. Prerequisite (hard dependency — verify against HEAD, do not assume)

- [ ] 0.1 Confirm the published `@conduction/nextcloud-vue` **beta**
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
- [ ] 0.2 Re-verify at current HEAD that
      `src/composables/useManifestHistory.js` still has no `reset()`
      callers and that `src/views/PageDesigner.vue` still wires
      `useManifestHistory` in `setup()` and pushes from the deep
      `manifest` watcher — the rewire in section 1 assumes exactly that
      wiring.

## 1. Page designer — rewire to the shared engine (REQ-BUR-001, REQ-BUR-002)

- [ ] 1.1 In `src/views/PageDesigner.vue` `setup()`, replace
      `useManifestHistory(props.manifest)` with `manifestEditHistory`
      from `@conduction/nextcloud-vue`, configured with depth 100
      (REQ-BUR-007) and seeded with the incoming `manifest` prop; keep
      the returned surface shape (`push`, `undo`, `redo`, `reset`,
      `canUndo`, `canRedo`) consumed by the existing computed
      `canUndo`/`canRedo` and methods `undo()`/`redo()`.
- [ ] 1.2 Keep the deep `manifest` watcher's `history.push(m)` feed and
      confirm the structural-identity no-op still absorbs the host's
      echoed prop updates (no double entries per edit).
- [ ] 1.3 Keep the toolbar Undo/Redo buttons and their
      `:disabled="!canUndo"` / `:disabled="!canRedo"` bindings and
      shortcut-naming `title` tooltips unchanged (REQ-BUR-002).
- [ ] 1.4 Add a `sessionKey` String prop to `PageDesigner.vue`; watch it
      and on change call `history.reset(this.manifest)` so a key change
      re-baselines the session with both buttons disabled (REQ-BUR-004).
- [ ] 1.5 Delete `src/composables/useManifestHistory.js` once nothing
      imports it (`grep -rn "useManifestHistory" src/ tests/` must come
      back empty apart from the migrated test of task 4.1).

## 2. Page designer host — session boundaries (REQ-BUR-004)

- [ ] 2.1 In `src/views/PageDesignerHost.vue`, add a `saveCounter` data
      field incremented after each **successful** save (both the
      ApplicationVersion PATCH branch and the Application PUT fallback
      in `save()`), and compute
      `sessionKey = \`${routeSlug}:${versionSlug || ''}:${saveCounter}\``.
- [ ] 2.2 Pass `:session-key="sessionKey"` to `<PageDesigner>` so slug
      change, `?_version=` switch, and successful save each reset the
      history seeded with the then-current manifest (fixes the HEAD
      cross-version undo-bleed; publish/rollback re-entry arrives via
      these same boundaries).
- [ ] 2.3 Verify a failed save does NOT increment `saveCounter` (the
      session — and its undo stack — survives a save error).

## 3. Keyboard handling — editable-target guard (REQ-BUR-003)

- [ ] 3.1 In `src/views/PageDesigner.vue` `onKeydown`, before matching
      chords, return early when the event target (or
      `document.activeElement`) is an `<input>`, `<textarea>`,
      `<select>`, or `contenteditable` element, so native text-field
      undo wins while typing; keep `metaKey` (`Cmd`) equivalent to
      `ctrlKey` and keep `preventDefault()` only on consumed chords.
- [ ] 3.2 Extract the guard as a small shared helper (e.g.
      `src/utils/isEditableTarget.js`) so the schema designer's handler
      (task 5.4) reuses it instead of duplicating the rule.

## 4. Unit tests — engine integration seam (Vitest)

- [ ] 4.1 Migrate `tests/composables/useManifestHistory.spec.js` to a
      seam spec (e.g. `tests/composables/manifestEditHistory.seam.spec.js`)
      exercising the leaf as OpenBuild consumes it: push/undo/redo
      round-trip, identical-state push no-op, redo-tail truncation on
      new edit, `reset()` re-baselining, never-issues-network (no axios
      calls), depth-100 bound with oldest-entry trimming and
      trim-safe redo (REQ-BUR-001, REQ-BUR-006, REQ-BUR-007 — includes
      the one-entry whole-manifest-replacement and
      invalid-input-records-nothing contracts).
- [ ] 4.2 Add `tests/vitest/views/pageDesignerUndoRedo.spec.js`: mount
      `PageDesigner.vue`; assert toolbar disabled states in a fresh
      session and after edit/undo (REQ-BUR-002); assert `update:manifest`
      re-emission on undo/redo; assert a `sessionKey` prop change resets
      the stack (REQ-BUR-004); assert history survives a `selectPage`
      sub-editor switch (REQ-BUR-004).
- [ ] 4.3 In the same spec (or a keydown-focused sibling), dispatch
      synthetic keydown events at the handler: `ctrlKey` chords drive
      undo/redo; `metaKey` (`Cmd`) chords behave identically
      (REQ-BUR-003 Cmd scenario — the Vitest side of that requirement's
      `@e2e exclude`); chords targeting an `<input>`/`<textarea>`/
      contenteditable are ignored and not `preventDefault`ed.
- [ ] 4.4 Add `tests/vitest/views/schemaDesignerUndoRedo.spec.js`
      (store-integration seam): mount `SchemaDesigner.vue` with
      `useSchemasStore` mocked; assert each staged `on*Change` commit is
      one history entry; assert undo restores the staged model without
      any store call; assert `discardChanges()` is one undoable entry;
      assert a mocked successful `store.saveObject` resolution resets
      the history while a rejected save leaves it intact (REQ-BUR-005).

## 5. Schema designer — staged-model undo/redo (REQ-BUR-005)

- [ ] 5.1 In `src/views/SchemaDesigner.vue`, instantiate the same
      `manifestEditHistory` engine (depth 100) over the staged editor
      model, seeded when `loadDetail()` stages a schema.
- [ ] 5.2 Route every staged mutation (`onHeaderChange`,
      `onFieldsChange`, `onStatesChange`, `onTransitionsChange`,
      `onRelationsChange`, `onWidgetsChange`) and `discardChanges()`
      through one commit helper that replaces `this.staged` and pushes
      the new snapshot — discard thereby becomes one undoable entry.
- [ ] 5.3 Add Undo/Redo `NcButton`s (tertiary) beside "Discard staged
      edits" in the detail-header actions, disabled via
      `canUndo`/`canRedo`, with shortcut-naming `title`s and
      `t('openbuild', …)` English-keyed labels.
- [ ] 5.4 Add the document-level keydown handler (mounted/beforeDestroy,
      detail mode only) reusing the task 3.2 guard helper; undo/redo
      re-assign `this.staged` from the returned snapshot.
- [ ] 5.5 Reset the history on: successful `save()` (after the store
      PUT resolves, re-seeded from the freshly staged `bodyToStaged`
      result), the existing `schemaId` watcher, and the
      `appSlug`/`versionSlug` watchers (REQ-BUR-004 semantics; a failed
      save keeps the stack).

## 6. Docs

- [ ] 6.1 Update `docs/integrator-guide.md` (designer sections): the
      undo/redo affordance in both designers, the shortcut table
      (`Ctrl+Z`, `Ctrl+Shift+Z`, `Ctrl+Y`, `Cmd` equivalents), the
      editable-field rule (native text undo wins while typing), and the
      session boundary — history resets on save/publish/version switch;
      restore across saves is Version History's job.

## 7. Playwright e2e (tests/e2e/builder-undo-redo.spec.ts)

Follow the existing designer-suite conventions (globalSetup storage
state, hello-world seed, `test.describe` wrapper carrying the
Conduction/openbuild#41 quarantine note like
`tests/e2e/page-designer.spec.ts` until #41 is fixed). One test per
scenario, named with the requirement ID.

- [ ] 7.1 REQ-BUR-001 "Undo restores the previous draft state" +
      "Redo re-applies an undone edit": open
      `/apps/openbuild/builder/hello-world/pages` (designer host), add
      a page, click Undo → page gone, click Redo → page back; assert no
      PATCH/PUT fired during undo/redo via `page.on('request')`
      capture.
- [ ] 7.2 REQ-BUR-001 "A new edit after undo truncates the redo tail":
      edit, undo, make a different edit, assert the Redo button is
      disabled.
- [ ] 7.3 REQ-BUR-002 "Both buttons disabled in a fresh session" +
      "Buttons enable and disable as the stack moves": assert disabled
      states on load, after one edit, and after one undo.
- [ ] 7.4 REQ-BUR-003 "Ctrl+Z / Ctrl+Shift+Z drive undo and redo outside
      fields": blur into the page body, `keyboard.press('Control+z')`
      and `Control+Shift+z` (plus `Control+y`), assert the draft moves.
- [ ] 7.5 REQ-BUR-003 "Ctrl+Z inside a text field leaves draft history
      untouched": after a draft-level edit, focus a sub-editor text
      input, type, press `Control+z`, assert the earlier draft-level
      edit is still applied.
- [ ] 7.6 REQ-BUR-004 "History survives a sub-editor switch": edit page
      A, select page B (different `SUB_EDITOR_MAP` type), undo, assert
      page A's edit reverted.
- [ ] 7.7 REQ-BUR-004 "Save resets the session history": edit, save via
      the host "Save pages" button, wait for the saved toast, assert
      Undo/Redo disabled and `Control+z` leaves the draft unchanged.
- [ ] 7.8 REQ-BUR-004 "Version switch resets the session history": edit
      version X's draft, navigate with `?_version=` to another seeded
      version (reuse the `versionRouting.spec.ts` seed approach),
      assert Undo disabled and no state of version X reachable.
- [ ] 7.9 REQ-BUR-005 "Undo restores a staged field edit" + "Discard
      staged edits is one undoable entry" + "Schema save resets the
      schema session history": in
      `/apps/openbuild/builder/hello-world/schemas/:schemaId`, add a
      field → undo → field gone; re-add → Discard → undo → staged edits
      back; save → Undo/Redo disabled.
