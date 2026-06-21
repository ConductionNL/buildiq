## Context

OpenBuild spec #1 (`bootstrap-openbuild`) shipped a JSON textarea as the only path to
author a virtual app's manifest. The textarea proves the runtime contract (load →
validate → save → render via a nested `CnAppRoot`) but is unusable for citizen
developers — the canonical
`@conduction/nextcloud-vue/src/schemas/app-manifest.schema.json` (v1.4.0) is a 1500+
line OpenAPI document with a closed 9-page-type enum, seven `$defs` for the recurring
sub-shapes (`column` / `action` / `widgetDef` / `layoutItem` / `formField` /
`sidebarSection` / `sidebarTab`), and per-type config sub-shapes that vary substantially
(an `index` page's `config` looks nothing like a `chat` page's).

This spec replaces the textarea-as-only-editor with a tabbed view whose default tab is a
visual designer. The textarea persists as the "Raw JSON" fallback tab for integrators and
for the corner cases the visual designer cannot express (yet). Everything ships in the
existing `openbuild` Nextcloud app's frontend — no new backend code, no new schemas, no
new register namespaces. Manifest CRUD continues to flow through OR REST per ADR-022.

**ADR-002 context** — spec #3 (`openbuild-versioning-model`) split the data model into
`Application` (logical, slug/name/RBAC/icon) and `ApplicationVersion` (deployable
runtime, manifest/semver/register/promotesTo). The `manifest` JSON blob now lives on
`ApplicationVersion`, not on `Application`. The save path for this editor therefore
targets `PUT /api/objects/openbuild/applicationVersion/{uuid}`. The editor always
operates on the currently-selected version (determined by `?version=<versionSlug>` query
param from the version-switcher, or the production version when no param is present,
matching the routing contract from spec `openbuild-version-routing`).

The chain dependency on `nextcloud-vue-in-memory-manifest` (chain spec #2) shapes the
design of the live-preview pane: that spec adds the
`useAppManifest(appId, manifestObject)` in-memory overload the preview pane mounts
against. Spec #2 is parallel to this one in the chain; the design assumes it MAY not be
shipped when this editor lands, and provides a degraded "save & reload" preview fallback.

## Goals / Non-Goals

**Goals**

- Replace the spec #1 textarea-as-only-editor with a visual designer that authors every
  shape declared in the canonical manifest schema's closed 9-page-type enum.
- Keep the textarea reachable as a "Raw JSON" fallback tab so power users and integrators
  are not regressed.
- Validate-as-you-type via `validateManifest`, surfacing errors both in a side panel and
  as inline marks on the offending field.
- Provide a live-preview pane when the in-memory manifest loader from chain spec #2 is
  available; gracefully degrade to "save & reload" when it is not.
- Stay strictly inside the canonical schema. The editor MUST NOT emit shapes outside the
  schema's enum/closed-set boundaries, and it MUST round-trip externally authored
  manifests losslessly.
- Stay strictly inside ADR-022 — no per-app REST wrappers, no controller additions; the
  designer reads/writes `ApplicationVersion` objects via OR REST.

**Non-Goals (deferred to chain or out of scope)**

- Versioning / draft / publish UX (covered by spec #3 `openbuild-versioning-model` and
  spec `openbuild-version-promotion`).
- Per-built-app permission management surface (chain spec #7 `openbuild-rbac`).
- Starter-template gallery (chain spec #8 `openbuild-templates-marketplace`).
- Real-app export of the manifest to a `src/manifest.json` file in a target
  Nextcloud-app repo (chain spec #9 `openbuild-export-to-real-app`).
- Editing the underlying schemas a page binds to (that is the `openbuild-schema-editor`
  spec #4 — this spec only *picks* from the registers/schemas OR already exposes).
- Real-time multi-user collaborative editing (see Open Questions).
- Undo / redo within a single editing session (see Open Questions).
- A `customComponents` registry-management surface — the custom-page sub-editor picks
  from whatever registry the running `CnAppRoot` exposes, but it does not let the user
  *create* new registry entries.

## Decisions

### Decision 1 — One Vue component per page type (vs polymorphic sub-editor)

The canonical schema declares nine page types and their `config` sub-shapes diverge
sharply (an `index` page's `config` shape is register/schema/columns/actions; a `chat`
page's is `conversationSource`/`postUrl`/`schema`; a `dashboard` page's is
`widgets`/`layout`). Reviewing these shapes side-by-side, **one component per type is
the right unit of decomposition**: the schema itself partitions cleanly by type, and any
"polymorphic" sub-editor ends up being nine `v-if` branches stitched together inside one
file — the same component count without the file boundaries.

The decomposition is exactly:

| Page type   | Component                  |
|-------------|----------------------------|
| `index`     | `IndexPageEditor.vue`      |
| `detail`    | `DetailPageEditor.vue`     |
| `dashboard` | `DashboardPageEditor.vue`  |
| `logs`      | `LogsPageEditor.vue`       |
| `settings`  | `SettingsPageEditor.vue`   |
| `chat`      | `ChatPageEditor.vue`       |
| `files`     | `FilesPageEditor.vue`      |
| `form`      | `FormPageEditor.vue`       |
| `custom`    | `CustomPageEditor.vue`     |

Each sub-editor receives `v-model="page.config"` (the in-flight config block for the
page) and emits `update:modelValue` events with the new shape. Shared `$def` sub-shapes
(columns / actions / widgets / layout items / form fields / sidebar sections / sidebar
tabs) live in a separate `src/components/page-editor/fields/` directory and mount inside
whichever sub-editors need them.

**Alternatives considered**

- *One polymorphic `PageConfigEditor.vue` with type-keyed branches*. Rejected. The
  branches are large enough that a single file becomes hard to navigate; adding a tenth
  page type means surgery in a hot file rather than a new file.
- *Code-generate the sub-editors from the JSON schema*. Deferred. The canonical schema's
  `pages[].config` description block is a free-text discriminator, not a typed `oneOf` —
  code-generation would need a separate machine-readable mapping table.

### Decision 2 — Drag-drop library reuse over hand-rolled

The menu-tree editor and the page-list editor both need drag-reorder. `@nextcloud/vue`
re-exports `vuedraggable` as part of its component set (used by `NcAppNavigation`
internally). **Reuse whatever `@conduction/nextcloud-vue` and `@nextcloud/vue` pull in
transitively** before adding a direct dependency.

The apply step's first task is `npm ls vuedraggable` to determine the current dependency
state. If it is present transitively, the editor imports from `vuedraggable` directly.
If it is absent, we add it as a direct devDep — the package is small (~10 kb minified)
and stable.

For the menu tree, the two-level nesting constraint (top-level + children, no third
level) is enforced at the editor layer, not at the drag-drop library layer —
`vuedraggable` happily supports arbitrary depth, but the canonical schema's
`menu[].children[]` shape has no further `children[]`, so we cap at depth two by
refusing to render a third-level drop zone.

### Decision 3 — Live preview depends on chain spec #2; degrade gracefully

The right-hand pane is a **sandboxed `CnAppRoot`** mount that renders the in-flight
(unsaved) manifest live as the user edits. The sandbox requires the in-memory
`useAppManifest(appId, manifestObject)` overload that chain spec #2
(`nextcloud-vue-in-memory-manifest`) ships in `@conduction/nextcloud-vue`.

**Behaviour when spec #2 is available:** The right-hand pane mounts
`<CnAppRoot :appId="openbuild-preview-{slug}" :manifest="inflightManifest"
:key="manifestHash" />`. The sandbox `appId = openbuild-preview-{slug}` so it does not
collide with the production-mounted `openbuild-{appSlug}-{versionSlug}`.

**Behaviour when spec #2 is unavailable:** `useLivePreview.js` feature-detects the
overload by inspecting `useAppManifest.length` (arity: spec #2 adds a second positional
parameter). When the overload is missing, the right-hand pane collapses to a button that
(a) saves the current manifest via the spec-1 REST path and (b) opens `/builder/:slug`
in a new browser tab. An i18n note
(`openbuild.page-designer.preview.unavailable`) explains the limitation.

### Decision 4 — Validation surface: side panel plus inline marks, debounced 300ms

The designer runs `validateManifest` against the in-flight manifest on every edit,
debounced to at most once every 300ms. Errors surface twice: in the right-hand
error-list side panel and as inline marks on the specific editor field whose JSON path
matches the error path.

Path mapping leans on `validateManifest`'s structured error output — each error carries
a JSON Pointer (`/pages/1/config/columns/0`) that the editor's path-to-field map
resolves to the offending Vue component. The map lives in `useManifestValidator.js` as a
registered set of path-prefix → field-component-ref entries; sub-editors register their
fields on mount and unregister on unmount.

300ms was chosen because (a) `validateManifest` on a typical 1–2 KB manifest completes
in ~5–20 ms and (b) anything tighter surfaces transient errors in the middle of
multi-character edits (e.g. typing "submitMethod" briefly flags "submitMetho" as
invalid). The validator MUST NOT block the editor — the run happens asynchronously.

### Decision 5 — Custom-page handling defers customComponents registry management

For this spec, `CustomPageEditor.vue` reads the registry **only at runtime** from the
sandboxed `CnAppRoot`'s injected `customComponents` prop. When the live-preview pane is
active, the picker is a dropdown over the registry's keys. When the preview pane is
unavailable, the picker degrades to free text with a warning.

The free-form `config` block for a custom page is an embedded JSON textarea because the
canonical schema explicitly allows custom pages' `config` to be "any shape the custom
component expects" — we cannot structure-edit something we don't know the shape of.

### Decision 6 — Save path targets ApplicationVersion per ADR-002

The spec #1 textarea editor saved to `Application.manifest`. Under the versioning model
(spec #3 / ADR-002), `manifest` now lives on `ApplicationVersion`. The designer's save
action therefore PUTs to
`/api/objects/openbuild/applicationVersion/{uuid}` — same REST mechanism, different
object. The editor reads the current `ApplicationVersion.uuid` from the Pinia store
(seeded by the version-switcher on view load). No new controller is required (ADR-022).

### Declarative-vs-imperative call-out (ADR-031)

The Page Designer's output **is** the manifest blob. The manifest itself is the
canonical declarative artefact for the OpenBuild ecosystem. This spec introduces **no
service class**: no `PageBuilderService`, no `ManifestComposerService`, no
`PageTypeRegistry`. The per-page-type sub-editors are dumb-form components that
read/write the matching `pages[].config` sub-shape. The save path is a single PUT to
OR's REST endpoint via the existing Pinia store action — no new service.

## Reuse Analysis

This change is a frontend-only change. The following OR abstractions and shared library
services are leveraged rather than duplicated:

| Abstraction | How consumed |
|---|---|
| **OR REST (Objects API)** | `PUT /api/objects/openbuild/applicationVersion/{uuid}` — existing path, no new controller (ADR-022) |
| **`validateManifest`** | Imported from `@conduction/nextcloud-vue`; `useManifestValidator.js` is a thin debounced wrapper |
| **`CnAppRoot`** | Sandboxed in the preview pane with in-memory manifest overload (chain spec #2) |
| **`useAppManifest`** | Feature-detected from `@conduction/nextcloud-vue`; in-memory overload path |
| **`CnJsonViewer`** | Used for the Raw JSON tab's textarea (CodeMirror-backed) |
| **Pinia `createObjectStore`** | Application/ApplicationVersion store already seeded by spec #1 and spec #3 |
| **`vuedraggable`** | Reused transitively via `@nextcloud/vue`; direct devDep if absent |

No OR backend services are duplicated. No app-local audit trail, RBAC, schema
validation, or file-management logic is introduced.

## Seed Data

This change introduces **no new OpenRegister schemas** and **no schema modifications**.
It is a purely frontend addition to the existing `openbuild` Nextcloud app. Per the
Seed Data rules in ADR-001 (data layer), seed data is not required for changes that only
modify frontend components.

The existing `hello-world` ApplicationVersion seed (shipped by the creation wizard,
spec `openbuild-app-creation-wizard`) provides the sample manifest data needed to
exercise the designer during QA and Playwright tests.

## Risks / Trade-offs

- **Risk** — *Editor drifts from the canonical schema as v1.5.x → v1.6.x lands in
  `@conduction/nextcloud-vue`.* → Mitigation: each sub-editor's allowed input shapes are
  checked against `validateManifest` on every keystroke — a schema bump surfaces
  immediately as a validation error. Pin the schema version to the
  `@conduction/nextcloud-vue` version in `package.json`; run the hello-world reference
  manifest through the editor's round-trip test on each library bump.
- **Risk** — *Round-trip-losslessness regressions on externally authored manifests.* →
  Mitigation: every sub-editor MUST keep unknown fields it does not understand. The
  editor stores the unmodified `ApplicationVersion` object in the Pinia store and
  surgical-merges its UI-controlled fields back on save. Tested by
  `manifest-roundtrip.spec.js`.
- **Risk** — *Live-preview pane re-mounts thrash on rapid edits.* → Mitigation: the
  preview's `:key` binds to a content hash, not a timestamp; identical-content edits do
  not re-mount.
- **Risk** — *Custom-page free-form JSON editor regresses into a second textarea.* →
  Acceptable trade-off for v1: the canonical schema allows any shape for custom `config`.
  When `customComponents` registry management ships (deferred spec), the registry can
  declare each component's expected `config` shape.
- **Trade-off** — *Nine sub-editor files vs one polymorphic file.* See Decision 1.
- **Trade-off** — *Live preview depends on chain spec #2.* See Decision 3.

## Migration Plan

This spec ships a frontend-only change inside the existing `openbuild` Nextcloud app.
There is no database migration, no schema change, no API change. Deployment steps:

1. Land the change on a feature branch from `development`.
2. CI runs the existing Newman suite (unchanged — no API surface change) plus the new
   Vitest suite for the designer components and a Playwright test that walks the seeded
   hello-world ApplicationVersion through the visual designer end-to-end.
3. Merge into `development`. On next deploy, the ApplicationVersion editor view opens
   with the Design tab as default; existing manifests load and render in the designer;
   the Raw JSON tab surfaces the unchanged spec-1 textarea.
4. **Rollback** — front-end rollback only; redeploy the previous build. ApplicationVersion
   objects in OR are unchanged by this spec, so there is no data to revert.
5. **Chain coordination** — when chain spec #2 lands in `@conduction/nextcloud-vue`,
   bump the library version in `package.json` and verify the live-preview pane activates.
   No editor code change should be required because the feature detection is at runtime.

## Open Questions

- **OQ-1 — Undo/redo within a single editing session.** *Provisional decision*:
  **defer**. The user can always switch to the Raw JSON tab and fix mistakes there, and
  the save-after-validate flow prevents persisting broken manifests.
- **OQ-2 — Real-time multi-user editing.** Two users editing the same
  ApplicationVersion's manifest concurrently could clobber each other's edits on save.
  *Provisional decision*: **defer to the versioning model's optimistic concurrency
  control**. An ETag guard on the PUT is the natural fix; file a follow-on issue.
- **OQ-3 — Embedded i18n key picker vs free-text label binding.** Every editor field
  that binds an i18n key currently accepts a free-text string. *Provisional decision*:
  **defer**. For v1 the editor accepts free text and surfaces a warning when the saved
  key does not resolve in the running catalogue.
- **OQ-4 — Default page type on "Add page".** *Provisional decision*: **force a pick**
  — the per-type sub-editor mounts as soon as the type is chosen and the page row's
  other fields make sense only in the context of the type.
