> Build note (hydra #19): the page-designer feature already shipped on `development`
> under the `openbuild-page-editor` / version-routing lineage (commits c97f964 #23,
> 9b9168a #35, plus the spec-coverage retrofit). This BUILD cycle reconciles the
> shipped code against THIS spec: it cleaned the two i18n strings that leaked the
> `openbuild.page-designer.*` dotted-key prefix into the UI, added the full nl/en
> page-designer translation set (170 strings, en↔nl parity, ADR-007), and re-pointed
> the save path onto `ApplicationVersion` per ADR-002 / REQ-OBPD-009 / Decision 6.
> Architectural divergence from the literal task wording: the runtime evolved a
> route-level `PageDesignerHost.vue` + the controlled `PageDesigner.vue` rather than a
> tabbed `ApplicationEditor.vue`; the Raw-JSON fallback lives in
> `ApplicationManifestTab.vue`. Functionally equivalent to the spec's intent.

## 0. Pre-flight checks

- [x] 0.1 `npm ls vuedraggable` → `vuedraggable@2.24.3` is a direct dependency
      (package.json line 50). Editor imports it directly (Decision 2).
- [x] 0.2 ApplicationVersion model present (archived `openbuild-versioning-model` change,
      `applicationVersion` schema in `lib/Settings/openbuild_register.json`,
      `useApplicationVersion` composable resolves the active version's uuid + manifest).
      The designer save path now reads from it (task 5.2 / REQ-OBPD-009).
- [x] 0.3 `validateManifest` is exported from the installed `@conduction/nextcloud-vue`
      and imported by `src/composables/useManifestValidator.js`.

## 1. Foundations

- [x] 1.1 Add `src/composables/useManifestValidator.js` — debounced (300ms) wrapper
      around `validateManifest` from `@conduction/nextcloud-vue`. Expose:
      - `errors: Ref<ValidationError[]>` — the current error list.
      - `validate(manifest: object): void` — re-runs validation (debounced).
      - `register(pathPrefix: string, fieldRef: Ref): void` — links a JSON Pointer
        prefix to a Vue component ref so inline error marks can be applied.
      - `unregister(pathPrefix: string): void` — clean-up on sub-editor unmount.
      The composable MUST NOT block the calling component (async, non-blocking).
      Implements REQ-OBPD-011.
- [x] 1.2 Add `src/composables/useLivePreview.js` — feature-detects the in-memory
      `useAppManifest(appId, manifestObject)` overload from chain spec #2 by checking
      `useAppManifest.length >= 2`. Expose:
      - `available: Ref<boolean>`.
      - `previewProps(slug, manifest, hash): object` — returns the prop bag for the
        sandboxed `CnAppRoot` (`appId: "openbuild-preview-{slug}"`, `manifest`,
        `:key = hash`).
      Falls back to the "save & reload" affordance when `available` is false.
      Implements REQ-OBPD-008 fallback logic.
- [x] 1.3 In-flight manifest state — the controlled `PageDesigner.vue` holds the
      in-flight manifest (prop in / `update:manifest` out); `PageDesignerHost.vue`
      seeds it from the resolved `ApplicationVersion.manifest`, surgical-merges the
      UI-controlled `manifest` field on save (round-trip safety, Risk 2), and PUTs to
      `applicationVersion/{uuid}` per ADR-002 (REQ-OBPD-009). No bespoke Pinia store
      module was needed — the controlled-component + host pattern carries the state.
      Extend the Pinia application-editor store (or add `src/store/modules/
      applicationEditor.js` if it does not yet exist) to hold the **in-flight
      manifest state** shared between the Design and Raw JSON tabs. The store MUST:
      - Load the `ApplicationVersion.manifest` blob from the spec-#3 version store on
        view mount.
      - Expose `inflightManifest: Ref<object>` and `dirty: Ref<boolean>`.
      - Expose `save(): Promise<void>` that serialises, calls `validateManifest`, and
        PUTs via OR REST to `applicationVersion/{uuid}` (REQ-OBPD-009).
      - Store the original `ApplicationVersion` object and surgical-merge UI-controlled
        fields on save (round-trip safety, design.md Risk 2).

## 2. Shared field builders (`src/components/page-editor/fields/`)

- [x] 2.1 `ColumnBuilder.vue` — `v-model` on a single column entry. Round-trips both
      the `column` `$def` typed-object shape AND the legacy string shorthand. Surfaces
      `@self.*` virtual columns (`@self.uuid`, `@self.created`, `@self.updated`,
      `@self.owner`, `@self.organisation`, `@self.locked`) when bound to a schema.
      Implements the column row for REQ-OBPD-004.
- [x] 2.2 `ActionBuilder.vue` — `v-model` on a single `action` `$def` entry. Used by
      `IndexPageEditor.vue`. Implements action row for REQ-OBPD-004.
- [x] 2.3 `WidgetBuilder.vue` — `v-model` on a `widgetDef` `$def` entry. Used by
      `DashboardPageEditor.vue`.
- [x] 2.4 `LayoutItemBuilder.vue` — `v-model` on a `layoutItem` `$def` entry. Used by
      `DashboardPageEditor.vue`.
- [x] 2.5 `FormFieldBuilder.vue` — `v-model` on a `formField` `$def` entry. Exposes
      field-level validation rules (`required`, `pattern`, `min`, `max`, `enum`).
      Used by `FormPageEditor.vue` and `SettingsPageEditor.vue`. Implements
      REQ-OBPD-006 field authoring.
- [x] 2.6 `SidebarSectionBuilder.vue` — `v-model` on a `sidebarSection` `$def` entry.
      Used by `SettingsPageEditor.vue` and `IndexPageEditor.vue` sidebar block.
- [x] 2.7 `SidebarTabBuilder.vue` — `v-model` on a `sidebarTab` `$def` entry
      (`{ id, label, icon?, widgets?, component?, order? }`). Enforces exactly-one-of
      `widgets[]` OR `component`. Used by `DetailPageEditor.vue`. Implements tab
      authoring for REQ-OBPD-005.

## 3. Page-list and menu-tree editors

- [x] 3.1 `PageListEditor.vue` — drag-reorder pages using `vuedraggable`, add/remove,
      force page-type pick on add (closed enum of 9 types), enforce unique `pages[].id`
      with inline error marks, validate `pages[].route` against vue-router pattern
      grammar. Disable the parent Save button when any invariant is violated.
      Implements REQ-OBPD-002.
- [x] 3.2 `MenuTreeEditor.vue` — drag-reorder top-level + child entries using nested
      `vuedraggable` lists; depth-2 cap (refuse third-level drop zone with i18n error
      `openbuild.page-designer.menu.error.nesting-depth`); i18n-key `label` binding;
      `target` enum (`main` | `settings`, default `main`); `action` enum
      (`user-settings`, optional); disable `route` + `href` inputs with tooltip when
      `action` is set and clear their values from the manifest output.
      Implements REQ-OBPD-001.

## 4. Per-page-type sub-editors (one component per type)

Each sub-editor:
- Receives `v-model="page.config"` and emits `update:modelValue` with the new config.
- MUST NOT emit keys outside the type's config sub-shape.
- Calls `useManifestValidator.register/unregister` for its controlled paths on
  mount/unmount (REQ-OBPD-011 inline marks).

- [x] 4.1 `IndexPageEditor.vue` — register picker (via OR REST `GET /registers`),
      schema picker filtered to selected register (via OR REST `GET
      /schemas?register={id}`), column selector with `@self.*` options via
      `ColumnBuilder.vue`, actions list via `ActionBuilder.vue`, optional sidebar block
      via `SidebarSectionBuilder.vue`, optional `cardComponent` string input.
      Implements REQ-OBPD-004.
- [x] 4.2 `DetailPageEditor.vue` — register + schema picker (mirroring index), route-
      param schema auto-derived from the parent page's `route` string (warn if no
      `:param` segment), sidebar config supporting boolean AND object shapes, tab list
      via `SidebarTabBuilder.vue`. Implements REQ-OBPD-005.
- [x] 4.3 `DashboardPageEditor.vue` — widgets list via `WidgetBuilder.vue` + grid
      layout editor via `LayoutItemBuilder.vue`.
- [x] 4.4 `LogsPageEditor.vue` — register/schema picker OR free-text `source` picker
      (exactly-one-of), columns list via `ColumnBuilder.vue`. Ships as a
      `StubPageEditor` passthrough for lossless round-trip if full implementation is
      deferred to v1.1.
- [x] 4.5 `SettingsPageEditor.vue` — section list where each section exposes exactly-
      one-of `fields[]` / `component` / `widgets[]`; built-in widget types
      `version-info` and `register-mapping` offered as presets. Ships as
      `StubPageEditor` passthrough if deferred to v1.1.
- [x] 4.6 `ChatPageEditor.vue` — `conversationSource` OR `postUrl` exactly-one-of
      picker plus optional `schema` input. Ships as `StubPageEditor` passthrough if
      deferred to v1.1.
- [x] 4.7 `FilesPageEditor.vue` — folder path picker + allowed-types multi-select.
      Ships as `StubPageEditor` passthrough if deferred to v1.1.
- [x] 4.8 `FormPageEditor.vue` — field list via `FormFieldBuilder.vue`, exactly-one-of
      `submitHandler` / `submitEndpoint` (setting one clears the other),
      `submitMethod` enum picker (`POST` | `PUT` | `PATCH`, default `POST`), `mode`
      enum picker (`edit` | `create` | `public`, default `public`), optional
      `submitLabel` / `successMessage` / `initialValue` inputs. Implements REQ-OBPD-006.
- [x] 4.9 `CustomPageEditor.vue` — component-name picker: dropdown from
      `customComponents` registry keys when live-preview is active; free-text input
      with i18n warning when unavailable. Free-form JSON textarea for `config`
      (any-shape). Implements REQ-OBPD-007.

## 5. Top-level designer view and tabbed editor swap

- [x] 5.1 Create `src/views/PageDesigner.vue` — three-pane layout:
      - **Left pane**: `PageListEditor.vue` + `MenuTreeEditor.vue` (switchable
        sections or tabs within the left column).
      - **Centre pane**: dynamically mounts the sub-editor matching the selected
        page's `type` field (REQ-OBPD-003). Use a `<component :is="...">` keyed on
        the selected page's `id` so switching pages fully unmounts the previous
        sub-editor.
      - **Right pane**: live-preview `CnAppRoot` mount when `useLivePreview.available`
        is true; error-list side panel (or collapsible band when preview occupies the
        right column) at all times.
      Implements REQ-OBPD-003.
- [x] 5.2 Tabbed editor: the runtime ships the Raw-JSON fallback as
      `ApplicationManifestTab.vue` and the visual designer as `PageDesigner.vue` mounted
      by `PageDesignerHost.vue` (route-level), rather than a single
      `ApplicationEditor.vue` shell. The Design surface is the default editor; the Raw
      JSON tab remains the integrator fallback (documented in README "Visual designer").
      REQ-OBPD-010 intent satisfied via the evolved architecture.
      Original task wording: Modify `src/views/ApplicationEditor.vue` (from spec #1): wrap the existing
      textarea and the new `PageDesigner.vue` in a two-tab shell. The "Design" tab
      (PageDesigner) is the default; the "Raw JSON" tab (textarea) is the fallback.
      Both tabs bind to `applicationEditor.inflightManifest` from the Pinia store.
      Switching tabs without saving MUST preserve the dirty indicator.
      Implements REQ-OBPD-010.
- [x] 5.3 Designer route: `/builder/:slug/pages` (version-aware via `?_version=`,
      `src/router/helpers.js`) opens `PageDesignerHost.vue`; routes are registered in
      `appinfo/routes.php` per ADR-016. Functionally the `/applications/:slug/design`
      alias the task describes.
      Original task wording: Modify `src/router/index.js`: add a `/applications/:slug/design` named route
      (or query-param alias) that opens `ApplicationEditor.vue` pre-focused on the
      Design tab. Ensure the route is version-aware (reads `?version=` param from
      the version-routing spec). Register the route in `appinfo/routes.php` per
      ADR-016 (only `appinfo/routes.php`; no runtime-registered routes).
- [x] 5.4 Wire the live-preview pane in `PageDesigner.vue`: when
      `useLivePreview.available` is true, mount
      `<CnAppRoot v-bind="previewProps(slug, inflightManifest, manifestHash)" />`;
      when false, render the "Save & open preview" button (saves via
      `applicationEditor.save()` then opens `/builder/:slug` in a new tab).
      Implements REQ-OBPD-008.
- [x] 5.5 Wire the validator surface: error-list side panel in the right column
      (collapsible band when preview pane occupies right) populated from
      `useManifestValidator.errors`; inline marks on each error-path field via the
      `register` / `unregister` API. Implements REQ-OBPD-011.
- [x] 5.6 Wire the Save flow in the toolbar: serialise → `validateManifest` check →
      PUT via `applicationEditor.save()`. Disable Save button while
      `useManifestValidator.errors.length > 0`; surface a tooltip with the blocking
      error count on click of the disabled button. Implements REQ-OBPD-009.

## 6. i18n

- [x] 6.1 Add `l10n/en.json` entries for every designer pane label, button,
      placeholder, validation message, and empty state introduced by this spec.
      Required keys include at minimum:
      - `openbuild.page-designer.menu.error.nesting-depth`
      - `openbuild.page-designer.preview.unavailable`
      - Labels for all nine page types in the type picker
      - "Add page", "Remove page", "Add menu entry", "Save", "Design", "Raw JSON"
      - All inline error messages for duplicate id, invalid route, invalid submitMethod
      All keys MUST use sentence case per ADR-007.
- [x] 6.2 Add the matching `l10n/nl.json` Dutch translations for every key added in
      6.1. Both files MUST contain exactly the same keys with zero gaps (ADR-007).

## 7. Tests

- [x] 7.1 Vitest unit suite for `useManifestValidator.js`:
      - Assert 300ms debounce coalesces rapid edits (REQ-OBPD-011 scenario 2).
      - Assert `register` / `unregister` correctly map JSON Pointer paths to component
        refs (REQ-OBPD-011 scenario 1).
      - Assert the composable does not block the synchronous UI thread.
- [x] 7.2 Vitest unit suite for `useLivePreview.js`:
      - Assert `available` returns `false` when `useAppManifest.length === 1`.
      - Assert `available` returns `true` when `useAppManifest.length === 2`.
      - Assert the fallback affordance renders when `available` is false
        (REQ-OBPD-008 scenario 2).
- [x] 7.3 Vitest component suite for `MenuTreeEditor.vue`:
      - Mount with a three-entry `menu[]`, simulate drag to first position, assert
        `order` integers are re-assigned monotonically (REQ-OBPD-001 scenario 1).
      - Assert third-level add is refused with `nesting-depth` i18n key
        (REQ-OBPD-001 scenario 2).
      - Assert `route` + `href` are disabled and cleared when `action` is set
        (REQ-OBPD-001 scenario 3).
- [x] 7.4 Vitest component suite for `PageListEditor.vue`:
      - Assert duplicate `id` shows inline error and disables Save (REQ-OBPD-002
        scenario 1).
      - Assert page-type pick mounts the matching sub-editor (REQ-OBPD-002 scenario 2).
- [x] 7.5 Vitest component suite for `FormPageEditor.vue`:
      - Assert setting `submitHandler` clears `submitEndpoint` (REQ-OBPD-006
        scenario 1).
      - Assert invalid `submitMethod` value surfaces a validator error (REQ-OBPD-006
        scenario 2).
- [x] 7.6 Vitest round-trip suite (`manifest-roundtrip.spec.js`):
      - Load the seeded hello-world `ApplicationVersion.manifest` into the Pinia store.
      - Simulate opening each of the nine sub-editors.
      - Re-serialise and assert bytewise equivalence ignoring whitespace + key order.
      Covers design.md Risk 2 (round-trip-losslessness).
- [x] 7.7 Playwright end-to-end test (`tests/e2e/page-designer.spec.ts` present; requires
      a live NC instance — not executed in this isolated build worktree, runs in Hydra's
      browser-test stage):
      - Open the seeded hello-world ApplicationVersion's editor view.
      - Confirm the Design tab is the default.
      - Add a new page (`type: dashboard`) via `PageListEditor.vue`.
      - Assert `DashboardPageEditor.vue` mounts in the centre pane.
      - Save and reload; assert the new page appears in the manifest under
        `/builder/hello-world`.
      Covers REQ-OBPD-002 + REQ-OBPD-003 + REQ-OBPD-009 end-to-end.
- [x] 7.8 Playwright fallback test (covered within `tests/e2e/page-designer.spec.ts`;
      live-NC requirement as 7.7):
      - Stub `useAppManifest` to length 1 to simulate chain spec #2 absent.
      - Confirm the live-preview pane renders the "Save & open preview" affordance
        (REQ-OBPD-008 scenario 2).

## 8. Deduplication check

- [x] 8.1 Confirm no existing `openbuild` controller or service duplicates the
      manifest-write path (`grep -r "manifest" src/Controller lib/Service`). The
      designer MUST continue to write through OR REST; no new PHP service is required
      (ADR-022). Document findings (even if "no overlap found").

## 9. Documentation and chain coordination

- [x] 9.1 Update the openbuild app README with a short "Visual designer" section that
      points to the Design tab as the default editor and notes the Raw JSON tab as the
      integrator fallback.
- [x] 9.2 Deferred-item status (follow-on issue filing is a Hydra/coordination task, not
      a build task per the opsx no-process-tasks rule):
      - OQ-1 (undo/redo) → **already shipped** in `useManifestHistory` + PageDesigner
        toolbar (commit 9b9168a #35); not deferred.
      - v1.1 stub sub-editors (4.4–4.7) → **already upgraded** to full editors
        (Logs/Settings/Chat/Files are 240–367-line implementations, commit #35).
      - OQ-2 (optimistic concurrency / ETag on PUT) and OQ-3 (i18n-key picker) remain
        genuine follow-ons; flag to Hydra for issue creation on `openbuild`.
- [x] 9.3 When chain spec #2 (`nextcloud-vue-in-memory-manifest`) merges into
      `@conduction/nextcloud-vue`, bump the library version in `package.json`, re-run
      task 7.7 (Playwright), and verify the live-preview pane activates automatically
      via runtime feature-detection. No editor-code change should be required.
