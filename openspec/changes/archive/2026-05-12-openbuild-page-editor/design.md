## Context

OpenBuilt spec #1 (`bootstrap-openbuilt`) shipped a JSON textarea as
the only path to author a virtual app's manifest. The textarea
proves the runtime contract (load → validate → save → render via a
nested `CnAppRoot`) but is unusable for citizen developers — the
canonical `@conduction/nextcloud-vue/src/schemas/app-manifest.schema.json`
(v1.4.0) is a 1500+ line OpenAPI document with a closed 9-page-type
enum, seven `$defs` for the recurring sub-shapes
(`column` / `action` / `widgetDef` / `layoutItem` / `formField` /
`sidebarSection` / `sidebarTab`), and per-type config sub-shapes
that vary substantially (an `index` page's `config` looks nothing
like a `chat` page's).

This spec replaces the textarea-as-only-editor with a tabbed view
whose default tab is a visual designer. The textarea persists as the
"Raw JSON" fallback tab for integrators and for the corner cases the
visual designer cannot express (yet). Everything ships in the
existing `openbuilt` Nextcloud app's frontend — no new backend code,
no new schemas, no new register namespaces. Manifest CRUD continues
to flow through OR REST per ADR-022.

The chain dependency on `nextcloud-vue-in-memory-manifest` (chain
spec #2) shapes the design of the live-preview pane: that spec adds
the `useAppManifest(appId, manifestObject)` in-memory overload the
preview pane mounts against. Spec #2 is parallel to this one in the
9-spec chain; the design assumes it MAY not be shipped when this
editor lands, and provides a degraded "save & reload" preview
fallback.

## Goals / Non-Goals

**Goals**

- Replace the spec #1 textarea-as-only-editor with a visual designer
  that authors every shape declared in the canonical manifest
  schema's closed 9-page-type enum.
- Keep the textarea reachable as a "Raw JSON" fallback tab so power
  users and integrators are not regressed.
- Validate-as-you-type via `validateManifest`, surfacing errors both
  in a side panel and as inline marks on the offending field.
- Provide a live-preview pane when the in-memory manifest loader from
  chain spec #2 is available; gracefully degrade to "save & reload"
  when it is not.
- Stay strictly inside the canonical schema. The editor MUST NOT
  emit shapes outside the schema's enum/closed-set boundaries, and
  it MUST round-trip externally authored manifests losslessly.
- Stay strictly inside ADR-022 — no per-app REST wrappers, no
  controller additions; the designer reads/writes Application
  objects via OR REST.

**Non-Goals (deferred to chain or out of scope)**

- Versioning / draft / publish UX (chain spec #6 `openbuilt-versioning`).
- Per-built-app permission management surface (chain spec #7
  `openbuilt-rbac`).
- Starter-template gallery (chain spec #8
  `openbuilt-templates-marketplace`).
- Real-app export of the manifest to a `src/manifest.json` file in a
  target Nextcloud-app repo (chain spec #9
  `openbuilt-export-to-real-app`).
- Editing the underlying schemas a page binds to (that is the
  `openbuilt-schema-editor` spec #4 — this spec only *picks* from
  the registers/schemas OR already exposes).
- Real-time multi-user collaborative editing (see Open Questions).
- Undo / redo within a single editing session (see Open Questions).
- A `customComponents` registry-management surface — the
  custom-page sub-editor picks from whatever registry the running
  `CnAppRoot` exposes, but it does not let the user *create* new
  registry entries.

## Decisions

### Decision 1 — One Vue component per page type (vs polymorphic sub-editor)

The canonical schema declares nine page types and their `config`
sub-shapes diverge sharply (an `index` page's `config` shape is
register/schema/columns/actions; a `chat` page's is
`conversationSource`/`postUrl`/`schema`; a `dashboard` page's is
`widgets`/`layout`). Reviewing these shapes side-by-side, **one
component per type is the right unit of decomposition**: the schema
itself partitions cleanly by type, and any "polymorphic" sub-editor
ends up being nine `v-if` branches stitched together inside one
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

Each sub-editor receives `v-model="page.config"` (the in-flight
config block for the page) and emits `update:modelValue` events with
the new shape. Shared `$def` sub-shapes (columns / actions / widgets
/ layout items / form fields / sidebar sections / sidebar tabs) live
in a separate `src/components/page-editor/fields/` directory and
mount inside whichever sub-editors need them — `ColumnBuilder.vue`
mounts inside `IndexPageEditor.vue` and `LogsPageEditor.vue`,
`FormFieldBuilder.vue` mounts inside `FormPageEditor.vue` and
`SettingsPageEditor.vue` (the settings page's
`sections[].fields[]` reuses the same `formField` `$def`), and so
on.

**Alternatives considered**

- *One polymorphic `PageConfigEditor.vue` with type-keyed branches*.
  Rejected. The branches are large enough that a single file
  becomes hard to navigate, code reviews lose their per-type focus,
  and adding a tenth page type (the canonical schema is extensible
  via the `customComponents` registry, but new built-in types would
  land via a future schema bump) means surgery in a hot file rather
  than a new file.
- *Code-generate the sub-editors from the JSON schema*. Tempting,
  but the canonical schema's `pages[].config` description block is
  a free-text discriminator (one giant description string covering
  all nine types) rather than a typed `oneOf` — code-generation
  would need a separate machine-readable mapping table that is
  itself bespoke. Defer to a future spec if the schema is
  refactored into a clean `oneOf`.

### Decision 2 — Drag-drop library reuse over hand-rolled

The menu-tree editor and the page-list editor both need
drag-reorder. `@nextcloud/vue` re-exports `vue-draggable` /
`vuedraggable` as part of its component set (used by
`NcAppNavigation` internally). The decision is: **reuse whatever
`@conduction/nextcloud-vue` and `@nextcloud/vue` pull in
transitively** before adding a direct dependency.

The apply step's first task is `npm ls vuedraggable` to determine
the current dependency state. If it is present transitively, the
editor imports from `vuedraggable` directly (the transitive copy
serves both the library wrappers and our direct use). If it is
absent, we add it as a direct devDep — the package is small (~10kb
minified) and stable. We do **not** hand-roll drag-drop: every
hand-rolled DnD implementation in our codebase to date has accreted
edge cases (touch support, autoscroll, keyboard a11y).

For the menu tree, the two-level nesting constraint (top-level +
children, no third level) is enforced at the editor layer, not at
the drag-drop library layer — `vuedraggable` happily supports
arbitrary depth, but the canonical schema's `menu[].children[]`
shape has no further `children[]`, so we cap at depth two by
refusing to render a third-level drop zone.

**Alternatives considered**

- *Hand-rolled HTML5 DnD wrappers*. Rejected per the accretion
  argument above. We get a11y, keyboard handling, and autoscroll
  for free from `vuedraggable`.
- *`@nextcloud/vue`'s `NcAppNavigation` drag wrappers*. Rejected for
  the menu/page editor surface: those wrappers are designed for
  navigation entries with specific NC styling expectations; using
  them for a builder canvas fights their assumptions. Reuse the
  underlying `vuedraggable` directly instead.

### Decision 3 — Live preview depends on chain spec #2; degrade gracefully

The right-hand pane is a **sandboxed `CnAppRoot`** mount that
renders the in-flight (unsaved) manifest live as the user edits.
This is the user-facing payoff of the visual editor — without it,
the designer feels like editing forms, not building an app.

The sandbox requires the in-memory `useAppManifest(appId,
manifestObject)` overload that **chain spec #2
(`nextcloud-vue-in-memory-manifest`) ships in the `@conduction/nextcloud-vue`
library repo**. Spec #2 runs parallel to this spec in the 9-spec
chain (per ADR-032 and the `chain:` block in proposal.md), so this
spec MAY ship before spec #2.

**Behaviour when spec #2 is available:**
The right-hand pane mounts `<CnAppRoot :appId="preview-{slug}"
:manifest="inflightManifest" :key="manifestHash" />`. Each manifest
edit re-renders the preview after the debounced validator pass
clears (no flickering on invalid in-flight states). The sandbox
`appId = openbuilt-preview-{slug}` so it does not collide with the
production-mounted `openbuilt-{slug}`.

**Behaviour when spec #2 is unavailable:**
`useLivePreview.js` feature-detects the overload by inspecting
`useAppManifest.length` (arity: spec #2 adds a second positional
parameter). When the overload is missing, the right-hand pane
collapses to a button that (a) saves the current manifest via the
spec-1 REST path and (b) opens `/builder/:slug` in a new browser
tab. An i18n note
(`openbuilt.page-designer.preview.unavailable`) explains the
limitation. The fallback is intentionally one composable deep so
the rewrite when spec #2 lands is small.

**Alternatives considered**

- *Block this spec on spec #2 landing first*. Rejected. Sequential
  chains pay coordination costs without yielding architectural
  benefit; spec #5 can ship usable without spec #2 because the
  designer itself works without preview — preview is a productivity
  enhancement, not a correctness requirement.
- *Mount the sandbox via the per-slug manifest endpoint workaround
  spec #1 uses*. Rejected: that workaround requires a saved
  manifest in OR, which defeats the entire purpose of a live
  preview (rendering UNSAVED edits). The in-memory overload is the
  only viable path for live preview.

### Decision 4 — Validation surface: side panel plus inline marks, debounced 300ms

The designer runs `validateManifest` against the in-flight manifest
on every edit, debounced to at most once every 300ms. Errors
surface twice: in the right-hand error-list side panel (a
collapsible band when the live-preview pane occupies the right
column instead), and as inline marks on the specific editor field
whose JSON path matches the error path.

Path mapping leans on `validateManifest`'s structured error output —
each error carries a JSON Pointer (`/pages/1/config/columns/0`)
that the editor's path-to-field map resolves to the offending Vue
component (the page-list editor's second row, the index sub-editor's
first column row). The map lives in
`useManifestValidator.js` as a registered set of path-prefix →
field-component-ref entries; sub-editors register their fields on
mount and unregister on unmount.

300ms was chosen because (a) `validateManifest` on a typical
1-2KB manifest completes in ~5-20ms in dev measurements — fast
enough that "live" is the right framing — and (b) anything tighter
than 300ms surfaces transient errors in the middle of multi-character
edits (e.g. typing "submitMethod" briefly flags "submitMetho" as
invalid). 300ms is also the Vue community default for input
debouncing.

The validator MUST NOT block the editor: the run happens
asynchronously and the UI stays responsive. The composable
internally uses a worker-free `setTimeout` debouncer; if profiling
reveals validator cost on a large manifest (>10KB) it MAY be moved
to a Web Worker in a follow-on spec, but for the v1 surface area
(~1-5KB manifests) the synchronous path is adequate.

**Alternatives considered**

- *Validate only on save*. Rejected: defers feedback to the moment
  the user thinks they are done, which is the worst time. The
  whole point of the visual designer is real-time validity
  feedback.
- *Validate on blur*. Rejected: half of the editor's surface is
  drag-drop / select / pick, which don't have a meaningful blur
  event; "as you type" + debounce covers both keyboard and
  drag-driven edits uniformly.

### Decision 5 — Custom-page handling defers customComponents registry management

The canonical schema's `type: custom` page renders against a key in
the consuming app's `customComponents` registry. The registry is
**not** OpenBuilt's concern in this spec — registry composition is
how a builder configures *what* custom components a virtual app has
access to, and configuring that registry needs its own surface
(deferred to a follow-on spec; the candidates are a new
`openbuilt-custom-components-registry` capability or folding it into
the marketplace spec #8).

For this spec, `CustomPageEditor.vue` reads the registry **only at
runtime** from the sandboxed `CnAppRoot`'s injected `customComponents`
prop (which today is whatever the OpenBuilt host app passes —
typically an empty `{}` until a future spec wires in component
discovery). When the live-preview pane is active, the picker is a
dropdown over the registry's keys. When the preview pane is
unavailable, the picker degrades to free text with a warning that
the value cannot be validated until preview is enabled.

The free-form `config` block for a custom page is an embedded
`<NcTextArea>` JSON editor (a single-purpose mini-textarea) because
the canonical schema explicitly allows custom pages' `config` to be
"any shape the custom component expects" — we cannot structure-edit
something we don't know the shape of. This is the one part of the
designer that mirrors the Raw JSON tab.

**Alternatives considered**

- *Ship registry management in this spec*. Rejected. Registry
  composition is a sibling concern to manifest authoring, not a
  child concern — folding it in would balloon the spec scope and
  blur the line between "what does my app look like" (this spec)
  and "what custom components does my app have access to" (a
  future spec).
- *Hide the custom page type entirely from the page-type picker
  until a registry exists*. Rejected. The closed enum is closed —
  hiding a type would either lie about the canonical schema or
  break externally authored manifests that already use `type:
  custom`. Better to keep the type visible with a degraded picker.

### Declarative-vs-imperative call-out (ADR-031)

The Page Designer's output **is** the manifest blob. The manifest
itself is the canonical declarative artefact for the OpenBuilt
ecosystem (it declares pages, menus, routes, sidebars, widgets,
form fields — *what* the app is, not *how* it runs). This spec
introduces **no service class**: no `PageBuilderService`, no
`ManifestComposerService`, no `PageTypeRegistry`. The
per-page-type sub-editors are dumb-form components that
read/write the matching `pages[].config` sub-shape — they have no
internal state machine, no derived behaviour, nothing that would
trigger ADR-031's anti-pattern test.

The closest the editor comes to "logic" is `useManifestValidator.js`
(debounced wrapper around the library's `validateManifest`) and
`useLivePreview.js` (feature-detect + sandbox mount). Both are
composables-of-glue, not domain logic. Neither encodes a state
machine, aggregation, calculation, or notification. The save path
is a single PUT to OR's REST endpoint, mediated by the existing
spec-1 Pinia store action — no new service.

If during apply a future iteration finds itself reaching for a
"build the manifest from scratch" or "auto-fix validation errors"
behaviour, that behaviour SHALL land as a declarative manifest
**template** or **transform** declared as data, not as a PHP/JS
service class — and SHALL be deferred to a separate spec for
review under ADR-031 in isolation.

## Risks / Trade-offs

- **Risk** — *Editor drifts from the canonical schema as v1.5.x →
  v1.6.x lands in `@conduction/nextcloud-vue`.* → Mitigation: each
  sub-editor's allowed input shapes are checked against
  `validateManifest` on every keystroke — a schema bump that
  invalidates an editor field surfaces immediately as a validation
  error, prompting an editor patch. The editor pins to the schema
  version shipped by the `@conduction/nextcloud-vue` version listed
  in `package.json`; on a library bump, run the
  `hello-world`/decidesk reference manifests through the editor's
  round-trip test (load → re-serialise → diff) and patch any
  divergence.
- **Risk** — *Round-trip-losslessness regressions on externally
  authored manifests.* → Mitigation: every sub-editor MUST keep
  unknown fields it does not understand (the canonical schema
  declares `additionalProperties: true` on the outer `config` block
  for per-type scalars and consumer-app extension keys). The editor
  stores the unmodified Application object in the Pinia store and
  surgical-merges its UI-controlled fields back on save, rather
  than re-serialising the whole shape from UI state. Tested by
  the `manifest-roundtrip.spec.js` Vitest suite (load decidesk's
  `src/manifest.json` → mount editor → re-serialise → assert
  bytewise equivalence ignoring whitespace).
- **Risk** — *Live-preview pane re-mounts thrash on rapid edits.* →
  Mitigation: the preview's `:key` binds to a content hash, not a
  timestamp; identical-content edits do not re-mount. The
  debounced validator gates the preview-mount path so invalid
  in-flight states never trigger a sandbox render.
- **Risk** — *Custom-page free-form JSON editor regresses into a
  second textarea.* → Acceptable trade-off for v1: the canonical
  schema allows any shape for custom `config`, and we don't know
  the consuming component's expectations. When `customComponents`
  registry management ships (deferred spec), the registry can
  declare each component's expected `config` shape and the editor
  can structure-edit that block too.
- **Trade-off** — *Nine sub-editor files vs one polymorphic file.*
  See Decision 1. Nine files is the right unit.
- **Trade-off** — *Live preview depends on chain spec #2.* See
  Decision 3. The fallback path keeps this spec independently
  shippable.

## Migration Plan

This spec ships a frontend-only change inside the existing
`openbuilt` Nextcloud app. There is no database migration, no
schema change, no API change. Deployment steps:

1. Land the change on a feature branch from `development`.
2. CI runs the existing Newman suite (unchanged — no API surface
   change) plus the new Vitest suite for the designer components
   and a Playwright test that walks the seeded `hello-world`
   Application through the visual designer end-to-end.
3. Merge into `development`. On next deploy, the Application
   editor view opens with the Design tab as default; existing
   manifests load and render in the designer; the Raw JSON tab
   surfaces the unchanged spec-1 textarea.
4. **Rollback** — front-end rollback only; redeploy the previous
   build. Application objects in OR are unchanged by this spec, so
   there is no data to revert.
5. **Chain coordination** — when chain spec #2 lands in
   `@conduction/nextcloud-vue`, bump the library version in
   `package.json` and verify the live-preview pane activates. No
   editor code change should be required because the feature
   detection is at runtime.

## Open Questions

- **OQ-1 — Undo/redo within a single editing session.** The visual
  designer is a many-knob surface; users will mis-click. Do we
  ship a built-in undo/redo stack (typically a `useUndoable.js`
  composable over the Pinia store) in this spec, or defer to a
  follow-on? *Provisional decision*: **defer**. The user can
  always switch to the Raw JSON tab and fix mistakes there, and
  the save-after-validate flow prevents persisting broken
  manifests. Adding undo here doubles the scope of the spec for a
  productivity feature that doesn't affect correctness. File a
  follow-on issue.
- **OQ-2 — Real-time multi-user editing.** Two users editing the
  same Application's manifest concurrently could clobber each
  other's edits on save. Do we add optimistic concurrency control
  (an ETag header on the PUT) or accept last-write-wins for v1?
  *Provisional decision*: **defer to chain spec #6 (versioning)**.
  Versioning will introduce explicit version snapshots which
  naturally surface concurrency conflicts as "your edit is based
  on version N but the current is N+1". Until versioning ships,
  the textarea-from-spec-1 has the same last-write-wins behaviour
  and no user has hit it; the visual designer does not change the
  risk profile.
- **OQ-3 — Embedded i18n key picker vs free-text label binding.**
  Every editor field that binds an i18n key (menu label, page
  title, button label) currently accepts a free-text string the
  user must type. Should the editor pick from the consuming app's
  registered i18n key set (read at runtime from `t()` / `n()` /
  the i18n catalogue)? *Provisional decision*: **defer to a
  follow-on**. For v1 the editor accepts free text and surfaces a
  warning when the saved key does not resolve in the running
  catalogue; a registry-backed picker is a fit-and-finish
  improvement that needs its own design pass on how to expose the
  catalogue to the editor without coupling OpenBuilt to every
  consuming app's i18n loader.
- **OQ-4 — Default page type on "Add page".** When the user clicks
  "Add page", do we pre-select a page type (e.g. `index`) or
  force a pick before any other field is shown? *Provisional
  decision*: **force a pick** — the per-type sub-editor mounts as
  soon as the type is chosen and the page row's other fields make
  sense only in the context of the type. Forcing the pick keeps
  the flow consistent and avoids the "create-then-discard" pattern
  if the user actually wanted a different type.
