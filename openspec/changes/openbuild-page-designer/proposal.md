---
kind: code
depends_on: [bootstrap-openbuild, openbuild-versioning-model, openbuild-app-creation-wizard]
chain:
  - bootstrap-openbuild          # spec #1 — Application register + textarea editor
  - openbuild-versioning-model   # spec #3 — Application/ApplicationVersion split (ADR-002)
  - openbuild-schema-editor      # spec #4 — schema designer
  - openbuild-page-designer      # spec #5 — THIS spec
---

## Why

Spec #1 (`bootstrap-openbuild`) shipped a textarea-based JSON manifest editor as the
integrator-only entry point for authoring a virtual app. That textarea proves the runtime
contract but is unusable for the citizen-developer audience OpenBuild actually targets:
hand-typing a manifest means knowing the canonical
`@conduction/nextcloud-vue/src/schemas/app-manifest.schema.json` (v1.4.0) by heart,
including its closed 9-type page enum, the per-type `config` sub-shapes, the
menu/permission/route grammar, and the `$ref`'d `column` / `action` / `widgetDef` /
`layoutItem` / `formField` / `sidebarSection` / `sidebarTab` sub-shapes. A visual
designer is the missing piece between "we can store and render a manifest" and "a
non-technical user can build one".

This is spec #5 of the OpenBuild chain. It is **purely a frontend code change** inside
the existing `openbuild` Nextcloud app — no new schemas, no new backend controllers, no
new register namespaces. The editor reads/writes the same `ApplicationVersion.manifest`
JSON blob the textarea already reads/writes (via OR REST per ADR-022), just through a
graphical UI instead of a raw text area. Per ADR-002 the manifest now lives on
`ApplicationVersion`, not on `Application`; the save path targets
`/api/objects/openbuild/applicationVersion/{uuid}` accordingly.

## What Changes

- **NEW** `src/views/PageDesigner.vue` — top-level visual manifest designer view, routed
  at `/applications/:slug/design`. Split into three panes: a left **page list + menu
  tree editor**, a centre **per-page-type config sub-editor** (one component per page
  type), and an optional right **live preview pane** (mounts a sandboxed `CnAppRoot`
  against the in-flight manifest when the in-memory manifest loader from chain spec #2
  is available; otherwise falls back to a "save & reload" preview).
- **NEW** per-page-type sub-editor components under `src/components/page-editor/` — one
  Vue component for each of the nine canonical page types declared by ADR-024:
  - `IndexPageEditor.vue` — register + schema picker, column selector (with `@self.*`
    metadata options), action declarations, sidebar/card-component config.
  - `DetailPageEditor.vue` — sidebar tab config (open-enum tab builder), route param
    schema, sidebar widget list.
  - `DashboardPageEditor.vue` — widget list + grid layout editor.
  - `LogsPageEditor.vue` — register/schema or `source` picker + columns.
  - `SettingsPageEditor.vue` — section list builder (fields vs component vs widgets
    exactly-one-of).
  - `ChatPageEditor.vue` — `conversationSource` or `postUrl` picker + optional `schema`.
  - `FilesPageEditor.vue` — folder picker + allowed-types selector.
  - `FormPageEditor.vue` — field list (reuses the `formField` builder), validation
    rules, `submitHandler` vs `submitEndpoint` exactly-one-of, method/mode pickers.
  - `CustomPageEditor.vue` — `customComponents` registry-name picker (read from the
    running `CnAppRoot`'s injected registry) + free-form slot/config JSON.
- **NEW** `src/components/page-editor/MenuTreeEditor.vue` — drag-reorder menu builder
  with two-level nesting (top-level entries + their `children[]`), `target: main |
  settings` picker, i18n-key label binding, `permission` and `action` field support.
- **NEW** `src/components/page-editor/PageListEditor.vue` — page-list view with
  add/remove, drag-reorder, uniqueness enforcement on `id`, and route-pattern validation.
- **NEW** shared field-builder components under `src/components/page-editor/fields/`
  (`ColumnBuilder.vue`, `ActionBuilder.vue`, `WidgetBuilder.vue`,
  `LayoutItemBuilder.vue`, `FormFieldBuilder.vue`, `SidebarSectionBuilder.vue`,
  `SidebarTabBuilder.vue`) that match the canonical `$defs` and are reused across the
  per-page-type sub-editors.
- **NEW** `src/composables/useManifestValidator.js` — debounced wrapper around
  `validateManifest` from `@conduction/nextcloud-vue` that surfaces errors in the
  side-panel error list and decorates each editor pane with inline marks pointing at the
  offending requirement.
- **NEW** `src/composables/useLivePreview.js` — feature-detects the in-memory
  `useAppManifest` overload shipped by chain spec #2 and either mounts a sandboxed
  `CnAppRoot` (preview pane active) or falls back to a "save & reload" link that opens
  `/builder/:slug` in a new tab.
- **MODIFIED** `src/views/ApplicationEditor.vue` (from spec #1) — the existing textarea
  becomes one of two tabs (the **"Raw JSON"** fallback tab); the new **"Design"** tab
  embeds `PageDesigner.vue` and is the default. The Application save path (PUT to OR
  REST targeting `ApplicationVersion.manifest`) is unchanged.
- **MODIFIED** `src/router/index.js` (from spec #1) — adds a
  `/applications/:slug/design` route alias that opens the editor pre-focused on the
  Design tab.
- **NEW** i18n strings under `l10n/en.json` + `l10n/nl.json` for every editor pane
  label, button, validation message, and empty state (per ADR-007).

### Capabilities

#### New Capabilities

- `openbuild-page-designer`: The visual manifest / page designer that produces output
  validating against
  `@conduction/nextcloud-vue/src/schemas/app-manifest.schema.json`. Owns the
  menu-tree editor, page-list editor, per-page-type sub-editors for all nine canonical
  types, the shared field builders for the seven `$defs`, the live-preview pane (when
  the in-memory manifest loader is available), and the validator surface.
  Reads/writes the `ApplicationVersion.manifest` blob via OR REST — no new backend.

#### Modified Capabilities

- `openbuild-runtime`: The editor swap. The Application edit view registered by spec
  #1 (`ApplicationEditor.vue` — single-textarea) is reshaped into a tabbed editor with
  a "Design" tab (the new `PageDesigner.vue`) as default and a "Raw JSON" tab (the
  existing textarea) as the integrator fallback. The runtime contract (manifest
  endpoint, nested `CnAppRoot` mount, seeded hello-world) is unchanged.

## Impact

- **New code** — `src/views/PageDesigner.vue` (~300 LOC),
  `src/components/page-editor/*.vue` (9 page-type sub-editors plus menu/page/field
  builders, ~150–250 LOC each), `src/composables/useManifestValidator.js` +
  `useLivePreview.js`, i18n strings in `l10n/en.json` + `l10n/nl.json`.
- **Modified code** — `src/views/ApplicationEditor.vue` (tabbed shell around the
  existing textarea + new designer), `src/router/index.js` (design route alias).
- **External dependency** — `@conduction/nextcloud-vue` (already installed) for
  `validateManifest` re-export and — when chain spec #2 ships — the in-memory
  `useAppManifest` overload. The editor feature-detects the overload and degrades to
  "save & reload" preview when it is absent, so this spec does **not** block on spec #2.
- **Drag-and-drop dependency** — uses `vuedraggable` if already pulled in transitively
  by `@nextcloud/vue` / `@conduction/nextcloud-vue`; otherwise adds it as a direct
  devDep. Decided during apply via the `npm ls vuedraggable` check.
- **No backend changes** — manifest CRUD continues to flow through OR REST per ADR-022.
  No new PHP, no new routes, no migration.
- **No breaking changes** — the existing textarea path is preserved as the "Raw JSON"
  tab; users with externally authored manifests keep their workflow.
- **Foundational ADRs honoured** — ADR-024 (manifest contract / closed 9-type enum),
  ADR-022 (no per-app REST wrappers), ADR-002 (manifest on ApplicationVersion, not
  Application), ADR-031 (no service classes — editor output is the declarative manifest
  blob itself).
