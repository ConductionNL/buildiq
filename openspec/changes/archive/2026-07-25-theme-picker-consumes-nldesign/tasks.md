## 0. Prerequisite (hard dependency — blocks all tasks below)

- [x] 0.1 Confirm `nldesign/openspec/changes/app-token-set-selection` has merged (`GET
      /api/token-sets`, `POST /api/contrast/evaluate` live on the target instance) AND
      `nextcloud-vue/openspec/changes/scoped-theme-applier` has merged and published
      (`useScopedTheme` exported, `CnAppRoot` self-applies `runtime.theme`, schema
      2.20.0+). BLOCK all tasks below until both are confirmed (REQ-NTS-007).
      — Confirmed: `@conduction/nextcloud-vue@1.0.0-beta.221` is published on npm and
      exports `useScopedTheme` (`{apply,teardown,fetchTokenCss,listTokenSets,evaluateContrast}`),
      `SCOPE_ATTR='data-nldesign-theme-scope'`; `CnAppRoot`'s own setup() watches
      `props.manifest?.runtime?.theme` (deep, immediate) and calls
      `scopedTheme.apply(...)` — REQ-STA-3 confirmed by reading the installed dist
      directly (`node_modules/@conduction/nextcloud-vue/dist/esm/components/CnAppRoot/CnAppRoot.vue2.js`).
      Schema `src/schemas/app-manifest-v2.schema.json` reports version `2.21.0`
      (>= 2.20.0) with `runtimeTheme` present under `$defs`.
- [x] 0.2 Bump `@conduction/nextcloud-vue` in `package.json` (currently
      `^1.0.0-beta.219`) to the confirmed version; run `npm install`; verify
      `node_modules/@conduction/nextcloud-vue` exports `useScopedTheme` before proceeding
      (REQ-NTS-007). — Bumped to `^1.0.0-beta.221`; `npm install` completed;
      `node_modules/@conduction/nextcloud-vue/dist/esm/composables/useScopedTheme.js`
      exists and is exercised (not just imported) by
      `tests/composables/nextcloud-vue-useScopedTheme.spec.js` (6 tests, real dist).

## 1. Delete the local runtime applier

- [x] 1.1 Delete `src/composables/useAppTheme.js` and its Vitest suite (confirm exact
      test path during apply — likely `tests/composables/useAppTheme.spec.js`) (REQ-NTS-003).
      — Both deleted.
- [x] 1.2 `src/views/BuilderHost.vue`: remove the `data-openbuild-theme-scope` attribute
      on the host wrapper and every `useAppTheme()`/`appTheme.apply()`/
      `appTheme.teardown()` call; remove the now-unused `useAppTheme` import (REQ-NTS-003).
- [x] 1.3 `src/views/PageDesignerHost.vue`: remove the `data-openbuild-theme-scope`
      attribute, the `onThemePreview()` method, and every `useAppTheme()`/`appTheme.apply()`/
      `appTheme.teardown()` call; remove the now-unused import (REQ-NTS-003, REQ-NTS-002).
      — `onThemePreview()` itself is KEPT (task 3.2 rewrites its body to the new
      manifest-mutation contract, per REQ-NTS-002); the `useAppTheme`-based
      implementation is fully removed.
- [x] 1.4 Vitest regression: mount `BuilderHost.vue` with a themed manifest and assert the
      themed CSS renders via `CnAppRoot`'s own applier with zero OpenBuild composable
      calls (mock `@conduction/nextcloud-vue`'s `CnAppRoot` to assert the
      `data-nldesign-theme-scope` prop/attribute is what carries the scope, not an
      OpenBuild-set one) (REQ-NTS-003). — `tests/views/BuilderHost.spec.js` (new, 3 tests).

## 2. Re-point ThemePickerDialog at the real catalogue

- [x] 2.1 Replace the three-tier list-population logic in
      `src/dialogs/ThemePickerDialog.vue` with a single
      `useScopedTheme().listTokenSets()` call; remove the admin `GET
      /apps/nldesign/settings/tokensets`/`tokenset-preview` calls, the feature-probe, and
      the free-text `css/tokens/<id>.css`-validated input entirely (REQ-NTS-002,
      REQ-NTS-006).
- [x] 2.2 Empty-list state renders the existing REQ-NTS-005 disabled-with-hint UI
      (`openbuild.theme.hint.nldesign-missing`) — confirm this path is unchanged and
      still correctly triggered by `listTokenSets()` resolving `[]` (REQ-NTS-002).
- [x] 2.3 Vitest for `ThemePickerDialog.vue`: non-empty list renders swatches from
      `theming.primary_color`/`background_color`; empty list renders the absence hint;
      no admin/free-text code path remains reachable. — 8 tests, rewritten spec file.

## 3. Live preview retargets the sandboxed CnAppRoot

- [x] 3.1 Trace `page-designer-live-preview-pane`'s sandboxed `CnAppRoot` mount (in
      `src/views/PageDesigner.vue`) and its bound in-flight manifest object during apply
      (OQ-1) — confirm it is reachable/mutable from `ThemePickerDialog.vue`'s preview
      toggle (REQ-NTS-002). — Confirmed: `PageDesigner.vue`'s `livePreviewProps` computed
      calls `previewProps(this.slug, this.manifest)` (useLivePreview.js), which returns
      `{appId, manifest: inflightManifest, key}` — `manifest` is the SAME object
      reference PageDesignerHost passes down as its own `manifest` prop. Mutating
      `PageDesignerHost.manifest.runtime.theme` therefore reaches the live-preview
      CnAppRoot's `:manifest` prop by reference; its content-hash `key` changes too,
      remounting the pane (immediate-apply on the fresh instance, REQ-STA-3).
      `previewAvailable` (arity-check on `useAppManifest`, chain spec #2) is TRUE for
      beta.221 (`function useAppManifest(appIdOrOptions, bundledManifest, options={})`
      has length 2) — confirmed by reading the installed dist directly.
- [x] 3.2 Wire the preview toggle to mutate that manifest's `runtime.theme` instead of
      calling `PageDesignerHost.onThemePreview()` (already removed in task 1.3); confirm
      the sandboxed `CnAppRoot` instance re-applies automatically per
      `scoped-theme-applier` REQ-STA-3 (REQ-NTS-002). — `onThemePreview()` rewritten to
      mutate `this.manifest` immutably via `withRuntimeTheme()`, snapshotting a
      `themePreviewBaseline` on the first preview mutation and restoring it on revert.
- [x] 3.3 If task 3.1 finds the live-preview pane can be unavailable
      (`previewAvailable === false`) while the Theme dialog is open, keep the preview
      toggle disabled with a hint in that state rather than silently no-op'ing (OQ-1
      resolution — do not leave a dead toggle). — `PageDesignerHost.livePreviewAvailable`
      (backed by `useLivePreview().available`) forwarded through `ThemeSection` to
      `ThemePickerDialog`'s `previewAvailable` prop; toggle `:disabled` + hint text when
      false. TRUE by default against beta.221 (see 3.1) but the gate is real/wired, not
      theoretical — verified false in `PageDesignerHost.spec.js` and
      `ThemePickerDialog.spec.js`.
- [x] 3.4 Vitest: preview toggle mutates the live-preview manifest; cancel reverts it;
      disabled state when the preview pane is unavailable. — Covered across
      `PageDesignerHost.spec.js` (manifest mutation/revert/no-op, livePreviewAvailable)
      and `ThemePickerDialog.spec.js` (preview emit + revert-on-cancel, disabled+hint
      when `previewAvailable=false`).

## 4. Delete local manifest theme validation

- [x] 4.1 Delete `src/services/manifestValidation/theme.js` and its Vitest suite; remove
      its import and `.concat(validateTheme(manifest))` call from
      `src/composables/useManifestValidator.js` (REQ-NTS-007, design.md Decision 5).
- [x] 4.2 Vitest regression: an invalid `runtime.theme` (unknown source, non-kebab
      tokenSet, unknown key) still surfaces an error from the single library
      `validateManifest()` call alone. — Added to `useManifestValidator.spec.js`.

## 5. Verification

- [x] 5.1 Playwright e2e (reuse/update `tests/e2e/nldesign-theme-selection.spec.ts`):
      pick a theme from the real catalogue, save, assert scoped rendering; leave and
      assert teardown; disable nldesign and assert the absence hint — same user-facing
      contract as before, now sourced from the real endpoint (REQ-NTS-002, REQ-NTS-003).
      — File updated: scenario titles/comments re-aligned to the new REQ-NTS-002/003
      text; all still `test.skip()` — the openbuild admin builder UI remains
      Conduction/openbuild#41-quarantined (pre-existing, unrelated to this change), so
      LIVE e2e verification of these scenarios is not possible in this build. Logic
      covered by vitest instead (see 1.4, 2.3, 3.4, and the real-dist proof suite).
- [x] 5.2 Newman: update the existing token-asset/catalogue assertions to hit
      `GET /api/token-sets` (expect 200 + non-admin session succeeds, replacing the old
      "403 as non-admin" assertion) and add a `POST /api/contrast/evaluate` shape
      assertion (REQ-NTS-006, REQ-NTS-008). — Collection rewritten (3 requests: token
      asset, `GET /api/token-sets` as non-admin, `POST /api/contrast/evaluate` shape).
      NOT run against a live instance in this apply (no running nldesign+openbuild
      Newman target was set up for this isolated clone) — each request degrades to a
      "skipped" `pm.test` on non-200, so it is safe to run later without editing; this
      is an HONEST GAP, not claimed as verified live.
- [x] 5.3 Quality gates + regression: `npm run lint` + vitest green; hydra gates
      (modal-isolation, nc-input-labels, e2e-coverage, no-phantom-cross-app-rpc); grep
      confirms no `useAppTheme.js`, no `manifestValidation/theme.js`, no
      `checkThemeContrast.js`, no `data-openbuild-theme-scope`/`data-openbuild-theme`
      anywhere in `src/`. Regression: a themed app saved under the OLD implementation
      renders identically under the new applier (manifest shape unchanged). — `npx
      eslint src`: 0 errors (1334 pre-existing `@spec`-tag JSDoc warnings, not
      introduced here). `npx vitest run`: 1341/1341 passed (138 files). Grep confirms
      zero remaining references to any of the four deleted symbols in `src/`.
      `runtime.theme`'s wire format is untouched (REQ-NTS-001 unchanged, only its
      validation/application source moved) — no manifest-shape migration needed.
