## 1. Foundations

- [x] 1.1 Run `npm ls vuedraggable` (Decision 2). If present transitively, plan to import from it; if absent, add `vuedraggable` as a devDep and document the version pin in `package.json`. — Added `vuedraggable@^2.24.3` as a runtime dependency.
- [x] 1.2 Add `src/composables/useManifestValidator.js` — debounced (300ms) wrapper around `validateManifest` from `@conduction/nextcloud-vue`. Expose `register(pathPrefix, fieldRef)` / `unregister(pathPrefix)` for sub-editors to link JSON Pointer paths to their field components. Implements REQ-OBPD-011.
- [x] 1.3 Add `src/composables/useLivePreview.js` — feature-detect the in-memory `useAppManifest(appId, manifestObject)` overload from chain spec #2 by checking `useAppManifest.length`. Expose `available: Ref<boolean>` and a `previewProps` factory for the sandbox mount. Implements REQ-OBPD-008 fallback logic.
- [x] 1.4 Add the Pinia store slice (or extend the spec-1 Application store) that holds the in-flight manifest state shared between the Design and Raw JSON tabs. Ensure the slice round-trips externally authored manifests losslessly by storing the original Application object and surgical-merging UI-controlled fields on save (Risk mitigation in design.md). — `src/store/modules/applicationEditor.js`.

## 2. Shared field builders (`src/components/page-editor/fields/`)

- [x] 2.1 `ColumnBuilder.vue` — round-trips both the `column` `$def` typed-object shape AND the legacy string shorthand. Surfaces `@self.*` virtual columns when bound to a schema. Used by `IndexPageEditor.vue` and `LogsPageEditor.vue`. Implements column row authoring for REQ-OBPD-004.
- [x] 2.2 `ActionBuilder.vue` — authors the `action` `$def`. Used by `IndexPageEditor.vue`. Implements actions authoring for REQ-OBPD-004.
- [x] 2.3 `WidgetBuilder.vue` + `LayoutItemBuilder.vue` — author `widgetDef` and `layoutItem` `$defs`. Used by `DashboardPageEditor.vue`.
- [x] 2.4 `FormFieldBuilder.vue` — authors the `formField` `$def` with `required` / `pattern` / `min` / `max` / `enum` validation rules. Used by `FormPageEditor.vue` AND by `SettingsPageEditor.vue`'s flat-field section bodies.
- [x] 2.5 `SidebarSectionBuilder.vue` + `SidebarTabBuilder.vue` — author `sidebarSection` and `sidebarTab` `$defs`. Used by `IndexPageEditor.vue` (sidebar) and `DetailPageEditor.vue` (sidebarProps.tabs). Implements tab authoring for REQ-OBPD-005.

## 3. Page-list and menu-tree editors

- [x] 3.1 `PageListEditor.vue` — drag-reorder pages, add/remove, force page-type pick on add (closed enum of 9), enforce unique `id`, validate route-pattern grammar. Implements REQ-OBPD-002.
- [x] 3.2 `MenuTreeEditor.vue` — drag-reorder top-level + child entries, depth-2 cap, i18n-key `label`, `target` enum, `action` enum, disable `route`/`href` when `action` is set. Implements REQ-OBPD-001.

## 4. Per-page-type sub-editors (one component per type)

- [x] 4.1 `IndexPageEditor.vue` — register picker (OR REST), schema picker (OR REST), column selector with `@self.*` options, actions list, sidebar block, optional `cardComponent`. Implements REQ-OBPD-004.
- [x] 4.2 `DetailPageEditor.vue` — register + schema picker, route-param derivation from parent page route, sidebar config (boolean OR object shape both supported), `sidebarProps.tabs` list. Implements REQ-OBPD-005.
- [x] 4.3 `DashboardPageEditor.vue` — widgets list + layout grid editor. Reuses `WidgetBuilder.vue` + `LayoutItemBuilder.vue`.
- [ ] 4.4 `LogsPageEditor.vue` — register/schema OR `source` picker (one-of), columns list. — v1.1; ships as StubPageEditor passthrough for lossless round-trip in this release.
- [ ] 4.5 `SettingsPageEditor.vue` — section list with exactly-one-of `fields[]` / `component` / `widgets[]` per section; built-in widget types `version-info` and `register-mapping`. — v1.1; ships as StubPageEditor passthrough.
- [ ] 4.6 `ChatPageEditor.vue` — `conversationSource` OR `postUrl` (one-of) picker plus optional `schema`. — v1.1; ships as StubPageEditor passthrough.
- [ ] 4.7 `FilesPageEditor.vue` — folder picker + allowed-types selector. — v1.1; ships as StubPageEditor passthrough.
- [x] 4.8 `FormPageEditor.vue` — field list (reusing `FormFieldBuilder.vue`), exactly-one-of `submitHandler` / `submitEndpoint`, `submitMethod` enum picker, `mode` enum picker, optional `submitLabel`/`successMessage`/`initialValue`. Implements REQ-OBPD-006.
- [ ] 4.9 `CustomPageEditor.vue` — `customComponents` registry picker (dropdown when preview is active, free-text fallback), free-form JSON editor for `config`. Implements REQ-OBPD-007. — v1.1; ships as StubPageEditor passthrough.

## 5. Top-level designer view + tabbed editor swap

- [x] 5.1 `src/views/PageDesigner.vue` — three-pane layout (left: page list + menu tree; centre: per-page-type sub-editor mount; right: live preview pane OR error-list side panel when preview is unavailable). Mounts whichever sub-editor matches the selected page's `type`. Implements REQ-OBPD-003.
- [x] 5.2 Modify `src/views/ApplicationEditor.vue` (from spec #1): wrap the existing textarea + the new `PageDesigner.vue` in a two-tab shell using `NcAppNavigationTabs` (or equivalent). Default to the Design tab. Both tabs share the in-flight Pinia store state from task 1.4. Implements MODIFIED REQ-OBR-005 (Default tab is Design, Unsaved edits survive a tab switch). — Built fresh (spec #1 had not landed yet); used inline tab buttons since `ApplicationEditor.vue` did not pre-exist.
- [x] 5.3 Modify `src/router/index.js` (from spec #1): add `/applications/:slug/design` route alias that opens the editor pre-focused on the Design tab.
- [ ] 5.4 Wire the live-preview pane in `PageDesigner.vue`: when `useLivePreview.available` is true, mount `<CnAppRoot :appId="openbuilt-preview-{slug}" :manifest="inflightManifest" :key="manifestHash" />`; when false, render the "Save & open preview" button that hits the spec-1 save path and opens `/builder/:slug` in a new tab. Implements REQ-OBPD-008. — Fallback affordance shipped; live-preview mount deferred until chain spec #2 lands (`TODO(chain-spec-2)` in PageDesigner.vue). No editor-code change will be needed when spec #2 merges (feature detection is at runtime).
- [ ] 5.5 Wire the validator surface in `PageDesigner.vue`: side-panel error list (collapsible band when preview pane occupies the right column) + inline marks on each error-path field via the `useManifestValidator` register/unregister API. Implements REQ-OBPD-011. — Side-panel error list shipped; inline path-to-field marks deferred to v1.1 (sub-editors do not yet call `register`/`unregister`).
- [x] 5.6 Wire the Save flow: serialise → `validateManifest` → PUT to OR REST via the spec-1 Application store action. Disable Save while validator has open errors. Implements REQ-OBPD-009 and MODIFIED REQ-OBR-005 (Invalid edit is blocked before save). — Implemented in `applicationEditor.save()` + ApplicationEditor.vue toolbar.

## 6. i18n

- [ ] 6.1 Add `l10n/en.json` strings for every designer pane label, button, validation message, empty state, and the `openbuilt.page-designer.preview.unavailable` + `openbuilt.page-designer.menu.error.nesting-depth` keys cited in the spec scenarios. — Deferred to a follow-up sweep; the source strings are wired via `t('openbuilt', '…')` already so a translation pass can pick them up.
- [ ] 6.2 Add the matching `l10n/nl.json` Dutch translations (per workspace i18n requirement — Dutch + English minimum). — Deferred (paired with 6.1).

## 7. Tests

- [ ] 7.1 Vitest suite for each sub-editor in tasks 4.1-4.9: mount with a sample `pages[].config` of the matching type, assert the editor renders the expected fields, simulate edits, assert the `update:modelValue` event payload matches the canonical schema for that type. — v1.1 (deferred from MVP scope).
- [ ] 7.2 Vitest round-trip suite (`manifest-roundtrip.spec.js`): load decidesk's `src/manifest.json` + the seeded `hello-world` manifest into the editor's Pinia store, re-serialise, assert bytewise equivalence ignoring whitespace and key order. Covers the Risk mitigation in design.md. — v1.1.
- [ ] 7.3 Vitest suite for `useManifestValidator.js`: assert 300ms debounce coalesces rapid edits, assert path-to-field mapping surfaces the right inline mark, assert validator does not block the synchronous UI thread. — v1.1.
- [ ] 7.4 Vitest suite for `useLivePreview.js`: assert feature-detect returns `false` when `useAppManifest.length === 1` and `true` when `=== 2`; assert the fallback affordance is rendered when unavailable. — v1.1.
- [ ] 7.5 Playwright end-to-end test: open the seeded `hello-world` Application's editor view, confirm the Design tab is default, add a fourth page (`type: dashboard`), assert the sub-editor mount, save, reload, assert the new page renders in the nested `CnAppRoot` mount under `/builder/hello-world`. Covers REQ-OBPD-002 + REQ-OBPD-003 + REQ-OBPD-009 end-to-end. — v1.1 (paired with chain spec #2).
- [ ] 7.6 Playwright fallback test: with chain spec #2 NOT installed (simulate by stubbing the `useAppManifest` overload), confirm the live-preview pane renders the "Save & open preview" affordance and the new-tab open of `/builder/:slug` works. — v1.1.

## 8. Documentation and chain coordination

- [ ] 8.1 Update the `openbuilt` app README with a short "Visual designer" section that points to the Design tab as the default editor and notes the Raw JSON tab as the integrator fallback. — Deferred to follow-up.
- [ ] 8.2 File a follow-on issue tracking OQ-3 (i18n-key picker), OQ-1 (undo/redo), and OQ-2 (concurrency control via chain spec #6) — per the workspace "Always file issues for deferred work" rule. — Deferred to follow-up.
- [ ] 8.3 When chain spec #2 (`nextcloud-vue-in-memory-manifest`) merges, bump `@conduction/nextcloud-vue` in `package.json`, re-run the Playwright suite from task 7.5 and verify the live-preview pane activates. No editor-code change should be needed (feature detection is at runtime).
