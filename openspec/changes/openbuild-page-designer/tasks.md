## 0. Pre-flight checks

- [ ] 0.1 Run `npm ls vuedraggable` in the openbuild app directory. If `vuedraggable` is
      present transitively via `@nextcloud/vue` or `@conduction/nextcloud-vue`, plan to
      import it directly (no extra dep). If absent, add `vuedraggable@^2.x` as a
      direct `devDependency` (Decision 2 in design.md).
- [ ] 0.2 Verify the Pinia application-version store (from spec #3
      `openbuild-versioning-model`) exposes the currently selected
      `ApplicationVersion.uuid` and `ApplicationVersion.manifest`. The designer's
      save path (REQ-OBPD-009) and in-flight state both read from this store.
- [ ] 0.3 Confirm `validateManifest` is exported from the installed version of
      `@conduction/nextcloud-vue` (`grep -r "export.*validateManifest"
      node_modules/@conduction/nextcloud-vue/src`). If absent, raise a blocking issue
      before continuing.

## 1. Foundations

- [ ] 1.1 Add `src/composables/useManifestValidator.js` — debounced (300ms) wrapper
      around `validateManifest` from `@conduction/nextcloud-vue`. Expose:
      - `errors: Ref<ValidationError[]>` — the current error list.
      - `validate(manifest: object): void` — re-runs validation (debounced).
      - `register(pathPrefix: string, fieldRef: Ref): void` — links a JSON Pointer
        prefix to a Vue component ref so inline error marks can be applied.
      - `unregister(pathPrefix: string): void` — clean-up on sub-editor unmount.
      The composable MUST NOT block the calling component (async, non-blocking).
      Implements REQ-OBPD-011.
- [ ] 1.2 Add `src/composables/useLivePreview.js` — feature-detects the in-memory
      `useAppManifest(appId, manifestObject)` overload from chain spec #2 by checking
      `useAppManifest.length >= 2`. Expose:
      - `available: Ref<boolean>`.
      - `previewProps(slug, manifest, hash): object` — returns the prop bag for the
        sandboxed `CnAppRoot` (`appId: "openbuild-preview-{slug}"`, `manifest`,
        `:key = hash`).
      Falls back to the "save & reload" affordance when `available` is false.
      Implements REQ-OBPD-008 fallback logic.
- [ ] 1.3 Extend the Pinia application-editor store (or add `src/store/modules/
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

- [ ] 2.1 `ColumnBuilder.vue` — `v-model` on a single column entry. Round-trips both
      the `column` `$def` typed-object shape AND the legacy string shorthand. Surfaces
      `@self.*` virtual columns (`@self.uuid`, `@self.created`, `@self.updated`,
      `@self.owner`, `@self.organisation`, `@self.locked`) when bound to a schema.
      Implements the column row for REQ-OBPD-004.
- [ ] 2.2 `ActionBuilder.vue` — `v-model` on a single `action` `$def` entry. Used by
      `IndexPageEditor.vue`. Implements action row for REQ-OBPD-004.
- [ ] 2.3 `WidgetBuilder.vue` — `v-model` on a `widgetDef` `$def` entry. Used by
      `DashboardPageEditor.vue`.
- [ ] 2.4 `LayoutItemBuilder.vue` — `v-model` on a `layoutItem` `$def` entry. Used by
      `DashboardPageEditor.vue`.
- [ ] 2.5 `FormFieldBuilder.vue` — `v-model` on a `formField` `$def` entry. Exposes
      field-level validation rules (`required`, `pattern`, `min`, `max`, `enum`).
      Used by `FormPageEditor.vue` and `SettingsPageEditor.vue`. Implements
      REQ-OBPD-006 field authoring.
- [ ] 2.6 `SidebarSectionBuilder.vue` — `v-model` on a `sidebarSection` `$def` entry.
      Used by `SettingsPageEditor.vue` and `IndexPageEditor.vue` sidebar block.
- [ ] 2.7 `SidebarTabBuilder.vue` — `v-model` on a `sidebarTab` `$def` entry
      (`{ id, label, icon?, widgets?, component?, order? }`). Enforces exactly-one-of
      `widgets[]` OR `component`. Used by `DetailPageEditor.vue`. Implements tab
      authoring for REQ-OBPD-005.

## 3. Page-list and menu-tree editors

- [ ] 3.1 `PageListEditor.vue` — drag-reorder pages using `vuedraggable`, add/remove,
      force page-type pick on add (closed enum of 9 types), enforce unique `pages[].id`
      with inline error marks, validate `pages[].route` against vue-router pattern
      grammar. Disable the parent Save button when any invariant is violated.
      Implements REQ-OBPD-002.
- [ ] 3.2 `MenuTreeEditor.vue` — drag-reorder top-level + child entries using nested
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

- [ ] 4.1 `IndexPageEditor.vue` — register picker (via OR REST `GET /registers`),
      schema picker filtered to selected register (via OR REST `GET
      /schemas?register={id}`), column selector with `@self.*` options via
      `ColumnBuilder.vue`, actions list via `ActionBuilder.vue`, optional sidebar block
      via `SidebarSectionBuilder.vue`, optional `cardComponent` string input.
      Implements REQ-OBPD-004.
- [ ] 4.2 `DetailPageEditor.vue` — register + schema picker (mirroring index), route-
      param schema auto-derived from the parent page's `route` string (warn if no
      `:param` segment), sidebar config supporting boolean AND object shapes, tab list
      via `SidebarTabBuilder.vue`. Implements REQ-OBPD-005.
- [ ] 4.3 `DashboardPageEditor.vue` — widgets list via `WidgetBuilder.vue` + grid
      layout editor via `LayoutItemBuilder.vue`.
- [ ] 4.4 `LogsPageEditor.vue` — register/schema picker OR free-text `source` picker
      (exactly-one-of), columns list via `ColumnBuilder.vue`. Ships as a
      `StubPageEditor` passthrough for lossless round-trip if full implementation is
      deferred to v1.1.
- [ ] 4.5 `SettingsPageEditor.vue` — section list where each section exposes exactly-
      one-of `fields[]` / `component` / `widgets[]`; built-in widget types
      `version-info` and `register-mapping` offered as presets. Ships as
      `StubPageEditor` passthrough if deferred to v1.1.
- [ ] 4.6 `ChatPageEditor.vue` — `conversationSource` OR `postUrl` exactly-one-of
      picker plus optional `schema` input. Ships as `StubPageEditor` passthrough if
      deferred to v1.1.
- [ ] 4.7 `FilesPageEditor.vue` — folder path picker + allowed-types multi-select.
      Ships as `StubPageEditor` passthrough if deferred to v1.1.
- [ ] 4.8 `FormPageEditor.vue` — field list via `FormFieldBuilder.vue`, exactly-one-of
      `submitHandler` / `submitEndpoint` (setting one clears the other),
      `submitMethod` enum picker (`POST` | `PUT` | `PATCH`, default `POST`), `mode`
      enum picker (`edit` | `create` | `public`, default `public`), optional
      `submitLabel` / `successMessage` / `initialValue` inputs. Implements REQ-OBPD-006.
- [ ] 4.9 `CustomPageEditor.vue` — component-name picker: dropdown from
      `customComponents` registry keys when live-preview is active; free-text input
      with i18n warning when unavailable. Free-form JSON textarea for `config`
      (any-shape). Implements REQ-OBPD-007.

## 5. Top-level designer view and tabbed editor swap

- [ ] 5.1 Create `src/views/PageDesigner.vue` — three-pane layout:
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
- [ ] 5.2 Modify `src/views/ApplicationEditor.vue` (from spec #1): wrap the existing
      textarea and the new `PageDesigner.vue` in a two-tab shell. The "Design" tab
      (PageDesigner) is the default; the "Raw JSON" tab (textarea) is the fallback.
      Both tabs bind to `applicationEditor.inflightManifest` from the Pinia store.
      Switching tabs without saving MUST preserve the dirty indicator.
      Implements REQ-OBPD-010.
- [ ] 5.3 Modify `src/router/index.js`: add a `/applications/:slug/design` named route
      (or query-param alias) that opens `ApplicationEditor.vue` pre-focused on the
      Design tab. Ensure the route is version-aware (reads `?version=` param from
      the version-routing spec). Register the route in `appinfo/routes.php` per
      ADR-016 (only `appinfo/routes.php`; no runtime-registered routes).
- [ ] 5.4 Wire the live-preview pane in `PageDesigner.vue`: when
      `useLivePreview.available` is true, mount
      `<CnAppRoot v-bind="previewProps(slug, inflightManifest, manifestHash)" />`;
      when false, render the "Save & open preview" button (saves via
      `applicationEditor.save()` then opens `/builder/:slug` in a new tab).
      Implements REQ-OBPD-008.
- [ ] 5.5 Wire the validator surface: error-list side panel in the right column
      (collapsible band when preview pane occupies right) populated from
      `useManifestValidator.errors`; inline marks on each error-path field via the
      `register` / `unregister` API. Implements REQ-OBPD-011.
- [ ] 5.6 Wire the Save flow in the toolbar: serialise → `validateManifest` check →
      PUT via `applicationEditor.save()`. Disable Save button while
      `useManifestValidator.errors.length > 0`; surface a tooltip with the blocking
      error count on click of the disabled button. Implements REQ-OBPD-009.

## 6. i18n

- [ ] 6.1 Add `l10n/en.json` entries for every designer pane label, button,
      placeholder, validation message, and empty state introduced by this spec.
      Required keys include at minimum:
      - `openbuild.page-designer.menu.error.nesting-depth`
      - `openbuild.page-designer.preview.unavailable`
      - Labels for all nine page types in the type picker
      - "Add page", "Remove page", "Add menu entry", "Save", "Design", "Raw JSON"
      - All inline error messages for duplicate id, invalid route, invalid submitMethod
      All keys MUST use sentence case per ADR-007.
- [ ] 6.2 Add the matching `l10n/nl.json` Dutch translations for every key added in
      6.1. Both files MUST contain exactly the same keys with zero gaps (ADR-007).

## 7. Tests

- [ ] 7.1 Vitest unit suite for `useManifestValidator.js`:
      - Assert 300ms debounce coalesces rapid edits (REQ-OBPD-011 scenario 2).
      - Assert `register` / `unregister` correctly map JSON Pointer paths to component
        refs (REQ-OBPD-011 scenario 1).
      - Assert the composable does not block the synchronous UI thread.
- [ ] 7.2 Vitest unit suite for `useLivePreview.js`:
      - Assert `available` returns `false` when `useAppManifest.length === 1`.
      - Assert `available` returns `true` when `useAppManifest.length === 2`.
      - Assert the fallback affordance renders when `available` is false
        (REQ-OBPD-008 scenario 2).
- [ ] 7.3 Vitest component suite for `MenuTreeEditor.vue`:
      - Mount with a three-entry `menu[]`, simulate drag to first position, assert
        `order` integers are re-assigned monotonically (REQ-OBPD-001 scenario 1).
      - Assert third-level add is refused with `nesting-depth` i18n key
        (REQ-OBPD-001 scenario 2).
      - Assert `route` + `href` are disabled and cleared when `action` is set
        (REQ-OBPD-001 scenario 3).
- [ ] 7.4 Vitest component suite for `PageListEditor.vue`:
      - Assert duplicate `id` shows inline error and disables Save (REQ-OBPD-002
        scenario 1).
      - Assert page-type pick mounts the matching sub-editor (REQ-OBPD-002 scenario 2).
- [ ] 7.5 Vitest component suite for `FormPageEditor.vue`:
      - Assert setting `submitHandler` clears `submitEndpoint` (REQ-OBPD-006
        scenario 1).
      - Assert invalid `submitMethod` value surfaces a validator error (REQ-OBPD-006
        scenario 2).
- [ ] 7.6 Vitest round-trip suite (`manifest-roundtrip.spec.js`):
      - Load the seeded hello-world `ApplicationVersion.manifest` into the Pinia store.
      - Simulate opening each of the nine sub-editors.
      - Re-serialise and assert bytewise equivalence ignoring whitespace + key order.
      Covers design.md Risk 2 (round-trip-losslessness).
- [ ] 7.7 Playwright end-to-end test:
      - Open the seeded hello-world ApplicationVersion's editor view.
      - Confirm the Design tab is the default.
      - Add a new page (`type: dashboard`) via `PageListEditor.vue`.
      - Assert `DashboardPageEditor.vue` mounts in the centre pane.
      - Save and reload; assert the new page appears in the manifest under
        `/builder/hello-world`.
      Covers REQ-OBPD-002 + REQ-OBPD-003 + REQ-OBPD-009 end-to-end.
- [ ] 7.8 Playwright fallback test:
      - Stub `useAppManifest` to length 1 to simulate chain spec #2 absent.
      - Confirm the live-preview pane renders the "Save & open preview" affordance
        (REQ-OBPD-008 scenario 2).

## 8. Deduplication check

- [ ] 8.1 Confirm no existing `openbuild` controller or service duplicates the
      manifest-write path (`grep -r "manifest" src/Controller lib/Service`). The
      designer MUST continue to write through OR REST; no new PHP service is required
      (ADR-022). Document findings (even if "no overlap found").

## 9. Documentation and chain coordination

- [ ] 9.1 Update the openbuild app README with a short "Visual designer" section that
      points to the Design tab as the default editor and notes the Raw JSON tab as the
      integrator fallback.
- [ ] 9.2 File follow-on issues for the deferred items:
      - OQ-1 (undo/redo) → label `designer-undo-redo`.
      - OQ-2 (optimistic concurrency / ETag on PUT) → label `designer-concurrency`.
      - OQ-3 (i18n-key picker backed by catalogue) → label `designer-i18n-picker`.
      - v1.1 stub sub-editors (4.4–4.7) → label `designer-v1.1-sub-editors`.
- [ ] 9.3 When chain spec #2 (`nextcloud-vue-in-memory-manifest`) merges into
      `@conduction/nextcloud-vue`, bump the library version in `package.json`, re-run
      task 7.7 (Playwright), and verify the live-preview pane activates automatically
      via runtime feature-detection. No editor-code change should be required.
