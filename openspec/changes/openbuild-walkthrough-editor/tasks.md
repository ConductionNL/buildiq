# Tasks — openbuild-walkthrough-editor

## 1. Edit-shell mode
- [~] Delivered as a standalone **WalkthroughDesigner view** (route
      `/builder/:slug/walkthrough`, page in manifest, registered in registry.js,
      linked from `ApplicationDetailActions` "Design walkthrough"). The live
      recorder overlay over the running app is **deferred** (follow-up).
- [ ] Recorder overlay: hover-highlight resolvable targets; click to create a step.

## 2. Target resolution
- [ ] `walkthroughTargetResolver` — NOT done (part of the deferred live recorder).
      The form editor lets the author type the target `kind` + `ref` directly.

## 3. advanceOn recording
- [ ] `walkthroughRecorder` — NOT done (deferred live recorder). The form editor
      lets the author pick `advanceOn.type` + route/register/schema directly.

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
- [ ] Editing `manifest.setup` steps in the same panel — NOT done; follow-up
      (same controlled-component + host pattern).

## 7. Validate
- [x] `openspec validate openbuild-walkthrough-editor --strict` passes.
- [x] vitest: WalkthroughDesigner 6 tests green; manifest tests unaffected (9).
- [x] Live (:8080): designer renders, authors tour+step (all fields), validates.
      Save initially 400'd — root-caused to a PRE-EXISTING bug (ApplicationVersion
      schema requires `register`, which collides with OR's reserved system field;
      every raw /api/objects/.../applicationVersion PUT 400s, no-op round-trip
      included, affecting PageDesignerHost too). FIXED: persist via openbuild's
      ApplicationVersionsController#update; walkthrough manifest now persists (200,
      verified). End-to-end Save-button reconfirm pending stable env (orchestration
      churn flapped :8080 to maintenance/needsDbUpgrade mid-verify).
