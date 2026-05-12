## Context

OpenBuilt is the citizen-developer app builder spec #1 of a 9-spec
chain (per ADR-032). The Conduction stack already has the pieces a
builder needs:

- `@conduction/nextcloud-vue` ships `CnAppRoot`, `CnAppNav`,
  `CnPageRenderer`, `useAppManifest`, and the canonical
  `app-manifest.schema.json` (v1.4.0, 9 page types). Decidesk runs as
  Tier-4 reference at
  `decidesk/src/manifest.json`.
- OpenRegister provides multi-tenant register/schema/object storage
  with RBAC, audit trail, declarative lifecycle metadata
  (`x-openregister-lifecycle`), and an automatic REST + GraphQL +
  CloudEvents + MCP surface (ADR-022, ADR-031).
- `openregister/lib/Service/...` already implements the declarative
  engines that drive the lifecycle / aggregation / calculation /
  notification metadata declared in any app's
  `lib/Settings/{app}_register.json`.

What's missing is the **builder shell itself** — a Nextcloud app that
stores manifests as objects and renders them at runtime. This spec
ships the minimal viable shell so that everything in the rest of the
chain (visual schema editor #4, page editor #5, versioning #6, RBAC
#7, marketplace #8, real-app export #9) has a foundation to build
against.

The architectural commitment is that **every built app is a record
in OpenBuilt's OR namespace**, not a file on disk. Phase 1 (this
spec + chain specs #2-#8) renders these as "virtual" apps inside the
OpenBuilt shell via a nested `CnAppRoot` mount. Phase 2 (chain spec
#9) generates a real Nextcloud app from a virtual one by writing
`src/manifest.json` and a register JSON file to a target repo.

## Goals / Non-Goals

**Goals**

- Stand up the `openbuilt` Nextcloud app skeleton with a single
  top-bar entry (already declared in `appinfo/info.xml`).
- Declare two OR schemas — `Application` and `BuiltAppRoute` — in
  `lib/Settings/openbuilt_register.json`, including the
  `x-openregister-lifecycle` block that drives the
  `draft → published → archived` state machine.
- Ship a single PHP controller method — `ApplicationsController::getManifest` —
  serving `GET /api/applications/{slug}/manifest`. All other CRUD on
  Application + BuiltAppRoute uses OR's REST directly (ADR-022).
- Ship a frontend host (`BuilderHost.vue`) that mounts a **nested**
  `CnAppRoot` per virtual app, with path segments after the slug
  forwarded to the inner router.
- Ship a textarea-based JSON manifest editor (no visual editor —
  that's chain spec #5) with client-side `validateManifest`.
- Seed a `hello-world` Application that exercises `index`,
  `detail`, and `form` page types, plus its companion
  `hello-message` schema with three sample objects, so the install is
  testable on minute one.

**Non-Goals (deferred to chain)**

- Visual schema designer (chain spec #4 / `openbuilt-schema-editor`).
- Visual page / manifest designer (chain spec #5 /
  `openbuilt-page-editor`).
- Runtime schema-creation API in OR (chain spec #3 /
  `openregister-runtime-schema-api`). The two schemas declared by
  this spec are static JSON in `lib/Settings/openbuilt_register.json`.
- Draft / publish UX, version snapshots, rollback (chain spec #6 /
  `openbuilt-versioning`). The lifecycle states exist on the schema;
  the UI to exercise them is minimal here.
- Per-built-app RBAC (chain spec #7 / `openbuilt-rbac`). All
  read/write authorisation in this spec uses OR's existing
  organisation-scoped authorization.
- Marketplace / starter templates (chain spec #8 /
  `openbuilt-templates-marketplace`).
- Export-to-real-app code generator (chain spec #9 /
  `openbuilt-export-to-real-app`).
- Nested-nested mounts (a virtual app embedding another virtual
  app). Out of scope; revisit if a use case surfaces.

## Decisions

### Decision 1 — Mixed-spec rationale (ADR-032)

This spec is declared `kind: mixed` per ADR-032 because it touches
**both** declarative JSON (the `lib/Settings/openbuilt_register.json`
schema declarations, including `x-openregister-lifecycle`) and code
(the `ApplicationsController::getManifest` method + the
`BuilderHost.vue` nested-mount glue). ADR-032 normally rejects
`mixed`, but admits a **thin-glue exception** when the code change is
≤20 LOC across ≤2 files and is tightly coupled to the config.

The code surface this spec ships qualifies for the exception:

- **File 1: `lib/Controller/ApplicationsController.php`** — adds a
  single method `getManifest(string $slug): JSONResponse` that
  looks up the slug → Application via OR's existing ObjectService
  and returns the stored `manifest` blob. ~15 LOC.
- **File 2: `src/views/BuilderHost.vue`** — mounts a nested
  `CnAppRoot` with `appId = openbuilt-{slug}` and a bundled-placeholder
  manifest, forwarding `$route.params.pathMatch` to the inner router.
  ~20 LOC of `<script>` glue (most of the file is template; the
  template itself is ~5 LOC of `<CnAppRoot>`).
- The textarea editor view (`src/views/ApplicationEditor.vue`) and
  the seed repair-step (`lib/Repair/SeedHelloWorld.php`) are
  legitimately code, but neither is "glue between config and code" —
  the editor is config-shaped UI (a textarea, validation, PUT to OR),
  and the seed step is config-shaped data loading. Both follow the
  spirit of the thin-glue exception: their purpose is to make the
  declarative side reachable from a user's hands.

If, during apply, the implementer finds that the controller +
`BuilderHost.vue` glue exceeds ~50 LOC combined, this spec MUST be
split into a chain — `bootstrap-openbuilt-schemas` (config only) +
`bootstrap-openbuilt-host` (code only). At that point this design.md
becomes the parent record for the split.

**Alternatives considered**

- *Pre-emptive split into config + code chain*. Rejected for v1: the
  glue here is genuinely thin, and a chain pays the
  coordination cost without removing any review surface — the OR
  schema register and the manifest endpoint are tightly coupled (one
  reads the other), and reviewing them in two PRs duplicates context.
  If the apply phase grows the surface, we split *then*.
- *Mark `kind: config` and elide the controller*. Rejected: without
  the per-slug manifest endpoint, the nested `CnAppRoot` mount can't
  load anything (see Decision 4 below). The endpoint is on the
  critical path.

### Decision 2 — Declarative-vs-imperative decision (ADR-031)

The Application lifecycle (`draft → published → archived`) is the
canonical declarative behaviour for this spec. Per ADR-031, it
SHALL be declared as `x-openregister-lifecycle` metadata in
`lib/Settings/openbuilt_register.json` — **not** as a PHP service
class.

| Candidate behaviour | Path |
|---|---|
| Application lifecycle (`draft → published → archived`) | **Declarative** — `x-openregister-lifecycle` on the Application schema. Transitions emit audit events, expose CloudEvents, and surface in GraphQL automatically. |
| BuiltAppRoute creation on publish | **Declarative** — `x-openregister-lifecycle.on_published` action declared on the Application schema, calling OR's relation-engine to upsert the corresponding `BuiltAppRoute` row. If the lifecycle engine does not yet expose an `on_<state>` hook that can create a sibling object, fall back to a single `BuiltAppRouteSyncListener` PHP class subscribed to OR's `ObjectLifecycleTransitionedEvent` — this is an exception per ADR-031 §"Exceptions" (1) and SHALL be documented in this design as a tracked OR-extension gap. |
| Manifest validation | **Declarative** — relies on the canonical JSON Schema referenced from the Application schema's `manifest` property. No bespoke validator service. |
| Slug uniqueness per organisation | **Declarative** — `x-openregister-relations` / index declaration on `BuiltAppRoute.slug` scoped to `organisation`. If OR's current declaration set does not yet express a per-org unique index, fall back to a `UniqueSlugConstraint` declared on the schema; otherwise OR's existing organisation scoping plus a list-and-check in `ApplicationsController::publish` (still thin glue) is the interim path. |
| Multi-tenant scoping | **Declarative** — inherited from OR's standard `organisation` field. No app-local RBAC. |

**Anti-pattern explicitly avoided.** This spec ships no
`ApplicationLifecycleService.transitionTo()` /
`ApplicationService.publish()` / `ApplicationStateMachine` class.
Anything that looks like a state-machine service is an ADR-031
review-block on the apply PR.

**Alternatives considered**

- *Write `ApplicationLifecycleService.php` with `transitionTo()`*. Rejected.
  ADR-031 explicitly names this anti-pattern in its examples list.
- *Hold the lifecycle in the manifest itself rather than on the
  Application schema*. Rejected. The lifecycle is about the
  **container record** ("is this app published?"), not about the
  content of the manifest. Conflating the two makes versioning (chain
  spec #6) harder.

### Decision 3 — Two schemas, not one (`Application` + `BuiltAppRoute`)

Splitting `slug → applicationUuid` into a separate `BuiltAppRoute`
schema (rather than a calculated index over the `Application`
collection) keeps the runtime lookup to a single OR object fetch.
At the volumes we expect early on it would be fine to scan, but the
extra schema is cheap and it future-proofs the marketplace spec
(chain #8) which will need to deep-link by slug across organisations
where Application-list scanning isn't acceptable.

**Alternatives considered**

- *Single schema, scan on lookup*. Rejected for the reason above.
- *Use OR's relations engine instead of a separate schema*.
  Possible, but `BuiltAppRoute` is conceptually different from a
  relation — it's a flattened slug → uuid index, not an edge between
  two domain objects. Keep it explicit.

### Decision 4 — Runtime contract: workaround for missing in-memory manifest loader

`@conduction/nextcloud-vue`'s current `useAppManifest(appId,
bundledManifest)` accepts a bundled-only manifest and deep-merges an
optional backend override fetched from
`/index.php/apps/{appId}/api/manifest`. It does **not** yet accept an
in-memory manifest object directly. Spec #2 of the chain
(`nextcloud-vue-in-memory-manifest`, lives in the nextcloud-vue
repo) will add that overload.

Until spec #2 lands, this spec uses a workaround:

1. Each virtual app gets a unique `appId = openbuilt-{slug}`.
2. The bundled-manifest argument is a placeholder skeleton
   `{ version: '0.0.0', menu: [], pages: [] }` shipped in
   `src/manifests/placeholder.json`.
3. The library's backend-merge call lands on
   `/index.php/apps/openbuilt-{slug}/api/manifest` by default. We
   intercept this in `BuilderHost.vue` by passing
   `options.fetcher` (a documented `useAppManifest` option) that
   redirects the call to the real endpoint
   `/index.php/apps/openbuilt/api/applications/{slug}/manifest`.
4. The library validates the merged result via `validateManifest`
   (re-exported from `@conduction/nextcloud-vue`) and mounts
   `CnAppRoot` against it.

When spec #2 ships, `BuilderHost.vue` collapses to
`useAppManifest(slug, manifestObject)` — the per-slug endpoint stays
useful for the export pipeline (chain spec #9), but the runtime stops
depending on it.

**Alternatives considered**

- *Wait for spec #2 before shipping this spec*. Rejected: the
  workaround is a few lines of `options.fetcher` config, and we want
  to validate the nested-mount architecture independently of the
  library change. Coupling them double-blocks the chain.
- *Patch `useAppManifest` in-place from `BuilderHost.vue`*.
  Rejected: forks the library contract. The `options.fetcher` path
  is a documented extension point.
- *Skip the library, mount `CnAppRoot` directly with a manually
  composed manifest object*. Rejected for v1: bypasses the
  validation guarantee `useAppManifest` provides at boot.

### Decision 5 — Nested mount, not router replacement

The OpenBuilt outer shell keeps its own `CnAppNav` + chrome; the
virtual app mounts a **nested** `CnAppRoot` inside the page area.
This preserves the OpenBuilt navigation entry, lets the user
exit a virtual app via the outer breadcrumb, and aligns with how
opencatalogi mounts a per-catalog detail surface inside its
top-level shell.

**Alternatives considered**

- *Replace the outer router for the duration of the virtual-app
  session.* Rejected: breaks the "where am I?" mental model and
  makes the exit path awkward. Also fights ADR-024's contract,
  which assumes `CnAppRoot` owns the chrome.
- *Open the virtual app in a new browser tab.* Rejected: loses
  state, breaks back-button, and forces a full Nextcloud reload.

### Decision 6 — Frontend uses OR REST directly for CRUD

Per ADR-022, OpenBuilt does **not** wrap OR's REST API in app-local
controllers. The textarea editor reads / writes Application objects
via OR's REST (`/index.php/apps/openregister/api/objects/openbuilt/application/...`).
The single exception is `getManifest`, which serves the manifest
blob unwrapped (without OR's envelope metadata) so that
`useAppManifest` consumes it cleanly. This keeps the OpenBuilt
backend almost empty and lets every OR improvement (audit, RBAC,
GraphQL) flow through without per-app wrapper changes.

## Risks / Trade-offs

- **Risk** — *The `options.fetcher` workaround drifts from the
  in-memory loader contract spec #2 will ship.* → Mitigation: when
  spec #2 lands, the chain blocker on this spec lifts and
  `BuilderHost.vue` collapses. Track this as the spec's open question
  below. The workaround is intentionally one file deep so the rewrite
  is small.
- **Risk** — *The nested `CnAppRoot` mount races the outer shell's
  router on path changes.* → Mitigation: route the
  `/builder/:slug/*` segment with `pathMatch` and force the inner
  router to remount on slug change via a `:key="slug"` on
  `<CnAppRoot>`. Covered in REQ-OBR-002 and REQ-OBR-003 scenarios.
- **Risk** — *The seeded `hello-world` Application diverges from the
  canonical manifest schema as v1.4.x → v1.5.x lands.* → Mitigation:
  the seed loads the bundled manifest from a check-in version of the
  schema; the repair step re-runs idempotently on every install and
  surfaces a validation error if the seed drifts from the schema
  version pinned in `package.json`.
- **Trade-off** — *Two schemas vs one with a calculated index.* See
  Decision 3. Cheap to ship two; future-proofs chain spec #8.
- **Trade-off** — *Textarea-only manifest editing is brutal UX.*
  Acceptable for spec #1; the visual editors are chain specs #4 / #5.
  Document the textarea editor as "integrator-only" in the in-app
  help string (i18n key `openbuilt.editor.help`).

## Migration Plan

This is the foundational spec. Nothing depends on OpenBuilt yet, so
there is no production data to migrate. Deployment steps:

1. Land the change on a feature branch from `development`.
2. CI runs PHPUnit + Newman + Playwright. The seeded `hello-world`
   integration test is the canonical green-light signal.
3. Merge into `development`. The migration runs on next deploy via
   the repair step; the `openbuilt` register + `Application` +
   `BuiltAppRoute` schemas + the seeded `hello-world` Application
   appear on every install.
4. **Rollback** — disable the `openbuilt` app via `occ app:disable
   openbuilt`. The OR objects remain in the database (no harm; they
   are no longer reachable through the openbuilt UI). To fully
   rollback, additionally remove the `openbuilt` register namespace
   via OR's admin UI. No other Conduction app reads from the
   `openbuilt` register at this point in the chain, so removal is
   safe.

## Seed Data

Per ADR-001, every schema-introducing change ships seed data.

**Hello World Application** (slug `hello-world`):

- One `Application` object in the `openbuilt/application` namespace
  with `status: published`, `version: 0.1.0`, `name: "Hello World"`,
  and a `manifest` blob declaring:
  - `version: 1.0.0`, `dependencies: ["openregister"]`
  - One menu item `{ id: "Messages", label: "openbuilt.helloworld.menu.messages", icon: "icon-comment", route: "Messages" }`
  - Three pages:
    - `{ id: "Messages", route: "/", type: "index", title: "openbuilt.helloworld.title.messages", config: { register: "openbuilt", schema: "hello-message", columns: ["title", "body", "@self.created"] } }`
    - `{ id: "MessageDetail", route: "/messages/:id", type: "detail", title: "openbuilt.helloworld.title.message", config: { register: "openbuilt", schema: "hello-message" } }`
    - `{ id: "MessageCreate", route: "/messages/new", type: "form", title: "openbuilt.helloworld.title.create", config: { register: "openbuilt", schema: "hello-message", mode: "create", submitEndpoint: "/index.php/apps/openregister/api/objects/openbuilt/hello-message" } }`
- One `BuiltAppRoute` object with `slug: hello-world`,
  `applicationUuid: <uuid of the Application above>`.

**Companion `hello-message` schema** (in the `openbuilt` register
namespace, alongside `Application` and `BuiltAppRoute`):

- Properties: `uuid` (string, UUID-format), `title` (string,
  required), `body` (string, optional).

**Sample `hello-message` objects** (three, seeded):

- `{ title: "Welcome to OpenBuilt", body: "This message is rendered by your first virtual app." }`
- `{ title: "Edit me", body: "Open the OpenBuilt shell and edit the hello-world manifest to change what you see here." }`
- `{ title: "Built from a manifest", body: "Everything you see came from a JSON blob stored in OpenRegister." }`

The apply agent SHALL generate
`lib/Settings/openbuilt_register.json` entries from this section and
ship the seed objects via `lib/Repair/SeedHelloWorld.php`. The
repair step SHALL guard on existing-slug to stay idempotent.

## Open Questions

- **OQ-1 — On_state lifecycle hooks vs PHP listener for BuiltAppRoute upkeep.**
  Does the current `x-openregister-lifecycle` engine support an
  `on_published` action that creates / updates a sibling object? If
  yes, declare it on the Application schema. If no, ship the thin
  `BuiltAppRouteSyncListener` PHP class as the ADR-031 exception
  documented in Decision 2 above, and file an OR-side issue to add
  the hook. *Provisional decision*: ship the listener if the apply
  phase finds the hook missing; either path keeps the public
  behaviour identical from the user's perspective.
- **OQ-2 — Permission key for the OpenBuilt top-bar entry.** Should
  the OpenBuilt navigation entry require an `openbuilt.use`
  permission, or is mere authentication enough at this stage? Chain
  spec #7 (RBAC) will land per-built-app permissions; for spec #1 we
  ship with auth-only and let admins narrow via NC's group
  restrictions at the app level. *Provisional decision*: auth-only.
- **OQ-3 — Schema versioning of the `hello-world` manifest.** The
  manifest's `version` field starts at `1.0.0`. If a future change
  to the canonical app-manifest schema (v1.5.x, v1.6.x …) would break
  the seeded manifest, do we re-seed at install or surface a
  validation warning? *Provisional decision*: surface a validation
  warning in the OpenBuilt shell ("seeded hello-world manifest is on
  schema v1.4.0; current canonical is vX.Y.Z — re-seed?") and let
  the admin opt in. Avoids silent data overwrite.
- **OQ-4 — App identifier reserved prefix `openbuilt-{slug}`.** Are
  there NC-level constraints on the `appId` string a virtual app
  uses (e.g. length limits, reserved prefixes, conflicts with the
  app-status capability lookup)? *Provisional decision*: enforce the
  `openbuilt-` prefix and a kebab-case slug max-length of 48
  characters so the total fits comfortably under typical NC appId
  caps. Verify during apply that `useAppStatus(openbuilt-{slug})`
  degrades gracefully when no such NC app is installed (it should
  return `{ installed: false }`, not throw).
