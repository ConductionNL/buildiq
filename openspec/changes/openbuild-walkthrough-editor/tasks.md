# Tasks — openbuild-walkthrough-editor

## 1. Edit-shell mode
- [x] Delivered as a standalone **WalkthroughDesigner view** (route
      `/builder/:slug/walkthrough`, page in manifest, registered in registry.js,
      linked from `ApplicationDetailActions` "Design walkthrough").
- [x] Live **recorder**: a "Record from app" button (shown when an `appSlug` +
      active tour exist) mounts `WalkthroughRecorder.vue`, which embeds the running
      virtual app (`/apps/openbuild/builder/{slug}`) in a same-origin iframe and,
      while armed, captures clicks inside it to create steps (instead of hover-
      highlight). Toggle recording off to navigate the app, back on to keep picking.

## 2. Target resolution
- [x] `recorderTargetResolver.js` — pure `resolveTargetFromElement(el)` resolving a
      clicked node to the most stable descriptor via `closest()` priority order:
      data-walkthrough-id → data-cn-route (nav-item) → data-widget-key → data-action-id
      → data-testid → short CSS path (`cssPath`). 6 vitest specs.

## 3. advanceOn recording
- [x] `WalkthroughRecorder` emits each resolved target as `pick`; the designer's
      `onRecorderPick` appends a step with a default advance — `click-target` for
      instrumented controls, `manual` for bare selector/page targets. 2 vitest specs.
      (Auto-observing the iframe router/store for route-match/object-created advances
      remains an optional follow-up; the form editor still sets those directly.)

## 4. Step + tour editor
- [x] `WalkthroughDesigner.vue` (controlled component, `manifest` prop →
      `update:manifest` / `save-and-preview`): tours add/rename/delete/select;
      steps add/delete/drag-free reorder (up/down); per-step title/body/task/
      placement/advanceOn(+route/register/schema)/optional/allowManualNext/
      sinceVersion (default = current app version); target kind + ref.

## 5. Persistence + versioning
- [x] `WalkthroughDesignerHost.vue` resolves the active ApplicationVersion
      (`useApplicationVersion`, `?_version=`), seeds the editor from its manifest,
      and persists `{ ...version, manifest }` back onto the ApplicationVersion
      (Application fallback) — the exact PageDesignerHost plumbing, so edits
      round-trip through the versioned manifest delta.
- [x] Validate against the canonical v2 schema before save: the designer surfaces
      `walkthrough`-scoped `validateManifest` errors and disables Save while invalid.

## 6. Setup-block reuse
- [x] WalkthroughDesigner gains a Walkthrough|Setup mode toggle; in Setup mode it
      edits `manifest.setup` steps (info/choice/config-fields/run-action/summary/
      component) — type-aware fields, required/multiple/healthCheck switches, a
      choice options editor, reorder/delete — committing to `manifest.setup` via the
      same controlled contract + host persistence. 6 added vitest specs (12 total).

## 7. Validate
- [x] `openspec validate openbuild-walkthrough-editor --strict` passes.
- [x] vitest: WalkthroughDesigner 14 tests + recorderTargetResolver 6 tests green
      (20 total); manifest tests unaffected.
- [x] Live (:8080): designer renders, authors tour+step (all fields), validates.
      Save initially 400'd — root-caused to a PRE-EXISTING bug (ApplicationVersion
      schema requires `register`, which collides with OR's reserved system field;
      every raw /api/objects/.../applicationVersion PUT 400s, no-op round-trip
      included, affecting PageDesignerHost too). FIXED: persist via openbuild's
      ApplicationVersionsController#update; walkthrough manifest now persists (200,
      verified).
- [x] Live (:8080) recorder: on test23, "Record from app" mounts the iframe runtime
      (armed); clicking the inner span of a `data-cn-route="MessagesIndex"` nav item
      resolved to `{kind:'nav-item', ref:'MessagesIndex'}` (closest() through the
      child) and appended `step-1` with a `click-target` advance. Deployed 0.5.8.
