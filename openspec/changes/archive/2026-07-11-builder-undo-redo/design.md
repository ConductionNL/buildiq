## Context

Both builder editors are **controlled components over component-held
draft state** (verified at HEAD — the drafts are NOT in Pinia):

- **Page designer** — `PageDesignerHost.vue` fetches the Application /
  ApplicationVersion, holds the draft `manifest` in `data()`, and passes
  it to `<PageDesigner :manifest @update:manifest>`. `PageDesigner.vue`
  re-emits every edit (`onPagesUpdate` / `onMenuUpdate` /
  `onConfigUpdate`) and its deep `manifest` watcher validates and
  records each accepted state. It already has an undo/redo toolbar and
  a document-level keydown handler (`Ctrl/Cmd+Z`, `Shift+Z`, `Y`)
  backed by the app-local `src/composables/useManifestHistory.js` —
  a bounded (50) snapshot stack whose `push` no-ops on
  structurally-identical states and whose `reset()` has **no callers**.
  The centre pane dispatches per-page-type sub-editors via
  `SUB_EDITOR_MAP` (`PageDesigner.vue:153`); switching pages only moves
  `selectedIndex` and does not touch the manifest, so history naturally
  survives sub-editor switches today.
- **Schema designer** — `SchemaDesigner.vue` holds `staged` (editor
  model) + `persisted` (last-saved body) in `data()`; section editors
  emit `update:*` events into `onHeaderChange` / `onFieldsChange` /
  `onStatesChange` / `onTransitionsChange` / `onRelationsChange` /
  `onWidgetsChange`, each of which replaces `this.staged` with a new
  object. Save composes the JSON Schema body and PUTs through
  `useSchemasStore` (`src/store/schemas.js`, a `createObjectStore`
  wrapper — the only Pinia involvement). There is no undo; only
  `discardChanges()` (full revert to `persisted`).
- **Raw JSON** — `src/components/tabs/ApplicationManifestTab.vue` is a
  textarea editor (validate via `validateManifest`, save via
  `obPatchApp`) mounted as a sidebar tab on the VirtualAppDetail
  manifest page. It saves directly rather than sharing the designer's
  draft, but its edit shape — a whole-manifest replacement — is exactly
  what the history engine must treat as one entry.
- **Version rollback** — `VersionHistory.vue` + `RollbackConfirmModal.vue`
  already cover restore-across-saves; editor history deliberately does
  not compete with them (D3).

The shared library `@conduction/nextcloud-vue` owns the manifest
structural utilities (`src/utils/diffManifest.js`,
`src/utils/mergeManifestDelta.js` upstream) and is gaining the leaf
`manifestEditHistory` (change `manifest-edit-history` on that repo): a
bounded undo/redo history over manifest JSON snapshots/deltas built on
those utils. Per the fleet convention that shared Vue logic lives in
nc-vue, OpenBuild consumes the leaf instead of keeping its local clone.

Constraints: no server-side changes; controlled-component contract
preserved (`manifest` in, `update:manifest` out); nc-vue leaves ship on
the **beta** dist-tag first and every consumer must rebuild after a
bump; existing designer e2e suites are quarantined under
Conduction/openbuild#41.

## Goals / Non-Goals

**Goals:**
- One shared history engine (`manifestEditHistory`) behind both
  designers, bounded at 100 entries.
- Undo/redo reachable three ways: toolbar buttons (disabled at stack
  boundaries), `Ctrl+Z` / `Ctrl+Shift+Z` (+ `Ctrl+Y`), and `Cmd`
  equivalents on macOS.
- Correct session boundaries: survive sub-editor/page switches; reset
  on save, publish/rollback re-entry, version switch, and app switch —
  fixing the HEAD bug where a version switch bleeds into the undo stack.
- Native text-editing undo keeps working inside form fields.
- A raw whole-manifest replacement is exactly one history entry.
- Schema designer gains parity, including an undoable "Discard staged
  edits".

**Non-Goals:**
- Implementing the history engine itself — that is nc-vue change
  `manifest-edit-history`; this change is its first consumer.
- Persisting history across reloads/sessions (in-memory only).
- Collaborative / multi-user undo semantics.
- Undo for the walkthrough designer or other builder surfaces (can
  adopt the same leaf later; out of scope here).
- Replacing or altering version-level rollback
  (`VersionHistory` / `RollbackConfirmModal`).
- Any backend/server-side change.

## Decisions

### D1 — Consume nc-vue's `manifestEditHistory`; delete the local composable
**Choice:** Replace `src/composables/useManifestHistory.js` with the
shared leaf, exposed to the Options-API components through the same
narrow surface the designer already consumes (`push`, `undo`, `redo`,
`reset`, reactive `canUndo`/`canRedo`). Migrate
`tests/composables/useManifestHistory.spec.js` to the integration seam.
**Why:** The local composable is a functional duplicate of what the
leaf provides, minus the delta-aware internals built on
`diffManifest`/`mergeManifestDelta`. Fleet rule: shared Vue logic lives
in nc-vue; every duplicated engine is a divergence risk (the local one
already drifted — 50-entry bound, no reset callers). Consuming the leaf
also means the schema designer and any future surface (walkthrough
designer, fleet edit-shell) share one tested engine.
**Alternative considered:** Keep the local composable and only fix its
gaps. Rejected — it entrenches the duplicate, and the nc-vue leaf exists
precisely to be consumed; keeping both violates the
shared-deps-via-nc-vue convention.

### D2 — Depth bounded at 100; snapshots-vs-deltas is the leaf's concern
**Choice:** Configure the history with a 100-entry bound (oldest entry
dropped on overflow). OpenBuild hands the engine plain manifest JSON
states; whether the leaf stores full snapshots or `diffManifest` deltas
internally is its implementation detail — OpenBuild's contract is
"push a state, get a state back".
**Why:** 100 comfortably covers a real editing session (competitor
builders bound between 50 and a few hundred) while capping memory for
large manifests. Treating storage strategy as the leaf's concern keeps
OpenBuild insulated from the snapshot→delta optimisation the leaf may
apply, and matches the leaf's advertised contract (bounded history over
manifest JSON snapshots/deltas).
**Alternative considered:** Unbounded history. Rejected — manifests can
be large (embedded schemas), and an unbounded JSON stack in a
long-running session is a memory leak with no UX payoff.

### D3 — History is per-editing-session: survives sub-editor switches, resets on save / publish / version switch (THE session-boundary decision)
**Choice:** One history instance per editing session. Within a session,
selecting a different page (and thus a different `SUB_EDITOR_MAP`
sub-editor) or moving between panes never clears history. The history
**resets** (re-seeded with the then-current draft as the new baseline)
on: (a) successful save, (b) app-slug change, (c) `?_version=` version
switch, (d) publish or version rollback — which re-enter the designer
through (a)–(c) as a new session. The host owns these boundaries: it
passes a **session key** (derived from slug + version slug + a
save counter) to the designer; a key change resets the history seeded
with the current manifest. The same pattern applies to the schema
designer (`schemaId` + version + save counter).
**Why:**
- *Survive sub-editor switches:* a page switch is navigation, not an
  edit; users expect Ctrl+Z after inspecting another page to undo their
  last actual edit. HEAD already behaves this way (selection isn't
  pushed); the spec locks it in.
- *Reset on save:* save is the hand-off point to the **version-level**
  history — VersionHistory/RollbackConfirmModal own restore-across-
  saves. Letting editor undo walk behind the persisted baseline would
  make the draft silently diverge from what the user believes is saved,
  and break the dirty-state reasoning (schema designer's
  `hasStagedChanges` compares staged vs persisted). Editor undo covers
  the *unsaved* session; version rollback covers everything before it.
- *Reset on version/app switch:* this is a correctness fix. At HEAD the
  host reseeds the `manifest` prop on a version switch, the deep
  watcher pushes it onto the same stack, and one Ctrl+Z "restores" the
  previous version's manifest into the new version's draft —
  cross-version state bleed. A session key makes the boundary explicit.
**Alternative considered:** Keep history across saves (Google-Docs
style continuous undo). Rejected — with a separate version-history
feature already owning cross-save restore, two overlapping restore
mechanisms with different granularities confuse rather than help; and
undoing past a save would need a "your draft is now older than the
saved version" affordance we don't want to build.

### D4 — Document-level shortcuts with an editable-target guard
**Choice:** Keep the document-level `keydown` listener, but ignore the
undo/redo chords when `event.target` (or the active element) is an
`<input>`, `<textarea>`, `<select>`, or `contenteditable` element —
there the browser's native text-field undo wins. Outside editable
fields, `Ctrl+Z` undoes, `Ctrl+Shift+Z` and `Ctrl+Y` redo, with
`metaKey` (`Cmd`) treated as `ctrlKey` for macOS. `preventDefault()` is
called only when the chord is actually consumed.
**Why:** HEAD's handler fires manifest-undo even while typing in a
config field, stacking a manifest-level revert on top of the native
character undo — double undo, data loss feel. Every competitor builder
lets the focused text control consume Ctrl+Z. The guard is the smallest
rule that resolves the conflict deterministically: focus decides.
**Alternative considered:** Debounce-merge text edits into the manifest
history so Ctrl+Z inside a field could still mean manifest-undo.
Rejected for this change — it requires coalescing heuristics (per-field
edit grouping) the leaf may add later; the guard is correct and simple
now, and field-level edits still reach manifest history the moment they
are committed (emitted) as an accepted state.

### D5 — Schema designer history operates on the staged editor model; Discard is one undoable entry
**Choice:** Push snapshots of `staged` (the editor model:
fields/states/transitions/relations/widgets/header), not the composed
JSON-Schema body. Every `on*Change` handler routes its new staged object
through a single commit point that pushes to history.
`discardChanges()` also routes through that point, so a discard is
exactly one history entry — one Ctrl+Z brings the staged edits back.
History resets on successful `save()` (the store PUT resolving), on
`schemaId` route change, and on app/version switch (D3).
**Why:** The staged model is what the sub-editors read and write —
undoing at that level restores the UI exactly, including editor-only
state (e.g. a widget row's `configError`), while the composed body is a
lossy projection built only at save time (`composeSchemaBody`). Making
Discard undoable removes the current foot-gun where one misclick on
"Discard staged edits" irrevocably destroys a session's work.
**Alternative considered:** Reuse the manifest-shaped engine over the
composed body. Rejected — round-tripping staged→body→staged on every
undo re-runs the `bodyToStaged` projection and loses editor-only state;
the engine is JSON-agnostic anyway, so feeding it the staged model is
both simpler and lossless.

### D6 — A raw whole-manifest replacement is one history entry
**Choice:** The history engine records **accepted states**, not input
events: any single commit that replaces the whole draft manifest — the
shape a raw-JSON surface produces (cf. `ApplicationManifestTab.vue`'s
parse-validate-apply flow) — is exactly one entry, and one undo
restores the complete pre-edit manifest. Invalid JSON never reaches the
draft (the raw surface validates first), so it never pollutes history.
The `ApplicationManifestTab` itself remains a direct-save surface at
HEAD (its save is a session boundary per D3); the one-entry contract is
specified on the engine seam so that any raw surface wired into the
designer session — now or later — inherits it.
**Why:** Users reason about a raw paste as one action; exploding it
into per-key entries (or capturing per-keystroke textarea states) would
make undo useless after a raw edit. The push-per-accepted-state model
already gives this for free — specifying it prevents a future
keystroke-granular regression.
**Alternative considered:** Wire the raw tab's textarea input directly
into history (entry per keystroke, coalesced). Rejected — the textarea
has native undo while focused (D4), and only *valid, applied* manifests
belong in manifest history.

## Risks / Trade-offs

- **Leaf not yet published / API drift** → all tasks are blocked on
  task 0.1: confirm the installed `@conduction/nextcloud-vue` beta
  actually exports `manifestEditHistory` and verify its exact surface
  against the installed `node_modules` (not against this design's
  assumptions). If the surface differs, adapt at the single integration
  seam (D1) rather than across components.
- **nc-vue bump side-effects** — bumping from `^1.0.0-beta.168` to the
  leaf-carrying beta pulls every intervening beta change into
  OpenBuild's bundle. Mitigation: `npm run build` + full Vitest suite +
  the designer smoke e2e run on the bump commit; per fleet reality,
  every nc-vue fix needs the consumer rebuilt anyway.
- **Reset-on-save surprises users who expect undo after save** →
  mitigated by scope clarity: the Save toast already points at version
  history ("publish from Apps"); version rollback covers post-save
  restore. Documented in the integrator guide (task 6.1).
- **Deep-watcher double-push** — `PageDesigner`'s `manifest` watcher
  sees both user edits and the host's echoed prop update; the engine's
  structural-identity no-op on push (already the local composable's
  behaviour, kept by the leaf) makes echoes free. Regression-tested in
  Vitest.
- **Memory for very large manifests** — 100 snapshots of a
  schema-embedding manifest could be noticeable; the leaf's
  delta-based internals (built on `diffManifest`) are the mitigation,
  and the bound caps the worst case.
- **Quarantined e2e** — the new Playwright spec lands under the same
  Conduction/openbuild#41 quarantine as the existing designer suites;
  scenario coverage is real but gated on #41's fix to run in CI. Vitest
  carries the interim regression net.

## Migration Plan

1. Confirm the `@conduction/nextcloud-vue` beta containing
   `manifestEditHistory` is published; bump `package.json`, reinstall,
   and verify the export in the installed `node_modules` (hard block).
2. Rewire `PageDesigner.vue` to the leaf behind the existing
   toolbar/shortcut surface; add the session-key prop; delete
   `src/composables/useManifestHistory.js` and migrate its unit spec.
3. Wire `PageDesignerHost.vue` session boundaries (save / slug /
   version → key change).
4. Add the editable-target guard to the keydown path.
5. Add undo/redo to `SchemaDesigner.vue` (staged-model commit point,
   toolbar, shortcuts, boundaries, undoable discard).
6. Land Vitest + Playwright specs; update the integrator guide.

**Rollback:** revert the frontend commits — the local composable and
its spec return with them; no data, schema, or route migration exists
in either direction. The nc-vue dependency bump may stay (the leaf is
additive).

## Open Questions

- Should the leaf's future edit-coalescing (grouping rapid keystroke
  commits into one entry) change the one-commit-one-entry contract
  here? Lean: no — coalescing happens before acceptance; the
  accepted-state contract stands.
- Should the walkthrough designer (`WalkthroughDesignerHost.vue`) adopt
  the same session history in a follow-up? Lean: yes, as a separate
  small change once this one proves the seam.
- Should undo/redo emit an ARIA live-region announcement ("Undid: …")
  for screen-reader parity? Lean: follow-up with the accessibility
  sweep; not load-bearing for this change.
