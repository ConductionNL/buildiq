# OpenBuild Runtime

This document describes how OpenBuild renders a virtual app at runtime — the manifest endpoint, the nested `CnAppRoot` mount, and the workaround that bridges the gap until the in-memory `useAppManifest` overload ships in `@conduction/nextcloud-vue`.

> Scope: spec #1 (`bootstrap-openbuild`) of the 9-spec OpenBuild chain. Visual editors, draft/publish lifecycle UX, per-app RBAC, marketplace, and code export live in chained follow-on specs.

## Big picture

```
[ Browser request ]
        │
        ▼
[ OpenBuild shell — outer CnAppRoot owned by openbuild/src/manifest.json ]
        │  navigate to /builder/<slug>/...
        ▼
[ src/views/BuilderHost.vue — mounts a NESTED CnAppRoot ]
        │  useAppManifest( appId='openbuild-<slug>', placeholderManifest, options )
        ▼
[ options.endpoint → GET /index.php/apps/openbuild/api/applications/<slug>/manifest ]
        │
        ▼
[ ApplicationsController::getManifest( slug ) ]
        │  via OR's ObjectService:
        ▼
[ openbuild/built-app-route  →  applicationUuid ]
[ openbuild/application[uuid].manifest ]
        │
        ▼
[ unwrapped manifest JSON → useAppManifest deep-merges with placeholder → CnAppRoot renders ]
```

## Why a nested CnAppRoot

`CnAppRoot` is router-agnostic and accepts a `manifest` prop. OpenBuild mounts a **fresh** instance per virtual app inside its own shell at `/builder/{slug}/*`. The `:key="slug"` prop forces a clean remount when the user navigates between virtual apps, so the inner manifest's router resets cleanly.

Alternatives rejected (see `openspec/changes/bootstrap-openbuild/design.md` Decision 5):

- Replacing the outer router for the duration of the virtual-app session — breaks the "where am I?" mental model.
- Opening the virtual app in a new tab — loses state, breaks the back button, forces a full Nextcloud reload.

## The manifest endpoint

| | |
|---|---|
| **URL** | `GET /index.php/apps/openbuild/api/applications/{slug}/manifest` |
| **Auth** | `#[NoAdminRequired]` + `#[NoCSRFRequired]` (auth-only for v1; scoping comes from OR's organisation field per ADR-022) |
| **Slug pattern** | `^[a-z0-9][a-z0-9-]*[a-z0-9]$`, 2–48 chars (matches the schema declaration) |
| **Lookup path** | slug → `openbuild/built-app-route` → applicationUuid → `openbuild/application` → `manifest` |
| **Response (200)** | the manifest JSON blob, **unwrapped** (no OR envelope) so `useAppManifest` consumes it directly |
| **Response (404)** | when no `BuiltAppRoute` matches the slug (i.e. no published app at that path) |
| **Response (500)** | inconsistent state (route → missing application, or application → missing manifest) — logged at warning |
| **Controller** | [lib/Controller/ApplicationsController.php](../lib/Controller/ApplicationsController.php) |

The controller is intentionally thin (~50 LOC of method body): a slug lookup, a UUID lookup, and an unwrap. All other CRUD on `Application` + `BuiltAppRoute` goes through OR's REST API directly per ADR-022.

## The workaround — bundled-mode `useAppManifest` with redirected endpoint

`@conduction/nextcloud-vue` v1.0.0-beta.30 ships `useAppManifest(appId, bundledManifest, options)` which fetches from `/index.php/apps/{appId}/api/manifest` by default — but it accepts an `options.endpoint` override to redirect the fetch.

OpenBuild uses this:

```vue
<!-- src/views/BuilderHost.vue -->
<CnAppRoot
    :key="slug"
    :app-id="`openbuild-${slug}`"
    :bundled-manifest="placeholderManifest"
    :options="{ endpoint: generateUrl(`/apps/openbuild/api/applications/${slug}/manifest`) }" />
```

- `appId = openbuild-${slug}` makes each virtual app's manifest cache key unique.
- `bundledManifest` is a minimal placeholder (`{ version: '0.0.0', menu: [], pages: [] }`) shipped at [`src/manifests/placeholder.json`](../src/manifests/placeholder.json). `useAppManifest` synchronously seeds with this then deep-merges the backend response.
- `options.endpoint` redirects the backend fetch from the default `/apps/openbuild-${slug}/api/manifest` (which would 404 — that's a different "app") to OpenBuild's per-slug endpoint.

When `nextcloud-vue` later ships an in-memory overload `useAppManifest({ manifest: object })` (chain spec #2 = `nextcloud-vue-in-memory-manifest`), `BuilderHost.vue` collapses to that call and the per-slug endpoint becomes optional. Until then, the endpoint stays on the critical path.

## The lifecycle is declarative (ADR-031)

OpenBuild does **not** ship an `ApplicationLifecycleService.php` / `ApplicationStateMachine.php` / similar service class. The state machine lives in the schema register at [lib/Settings/openbuild_register.json](../lib/Settings/openbuild_register.json) under `Application.x-openregister-lifecycle`:

| State | Transition | Action |
|---|---|---|
| `draft` → `published` | `publish` | upsert sibling `BuiltAppRoute(slug, applicationUuid)` |
| `published` → `archived` | `archive` | delete `BuiltAppRoute` with matching slug |
| `archived` → `draft` | `reopen` | — |
| `archived` → `published` | `republish` | upsert `BuiltAppRoute` |

> If OR's current lifecycle engine doesn't yet support the `on_transition.upsert_relation` / `delete_relation` actions for sibling-object upkeep, the fallback is a single PHP listener `lib/Listener/BuiltAppRouteSyncListener.php` subscribed to `ObjectLifecycleTransitionedEvent` (per design.md OQ-1). The listener is the ADR-031 §Exceptions(1) path; behaviour from the user's perspective is identical either way.

## Creating a virtual app (wizard flow)

Most operators reach the virtual app via the visual wizard at **Virtual apps →
New application**. The flow is owned by `ApplicationCreationController` +
`ApplicationCreationService` and lands in a single transactional round-trip:

1. **Submit** the wizard's three-step payload (`identity`, `versions[]`,
   `permissions`) to `POST /api/applications/wizard`.
2. The service creates the parent `Application` row, then per version:
    - one `ApplicationVersion` row carrying the manifest, register pointer, and
      semver,
    - one per-version OR register `openbuild-{appSlug}-{versionSlug}`,
    - the default schema set (currently just `hello-message`) seeded into the
      per-version register under namespaced slugs
      (`{appSlug}-{versionSlug}-{originalSlug}`).
3. The first version in the chain's `productionVersion` pointer is set on the
   parent Application.
4. The wizard rewrites the manifest's `pages[].config.register` and
   `pages[].config.schema` to the namespaced per-version values before save —
   so the runtime + insights service address the right per-tier slice (see
   `ApplicationCreationService::substituteVersionContext()`).

Rollback is best-effort: if any step fails the service tears down the registers
+ application + version rows it managed to create so the org-wide unique slug
isn't squatted.

### Empty-state landing (no auto-seed)

The legacy `lib/Repair/SeedHelloWorld.php` repair step was retired by
[`openbuild-versioning-model`](../openspec/changes/openbuild-versioning-model/).
Fresh installs land the admin on an empty Virtual apps index with a CTA
pointing at the wizard; pre-existing installs that still carry pre-spec-C
Application rows are migrated by `MigrateToVersionedModel` (destructive but
idempotent — see ADR-002).

The `hello-world` slug now ships only as part of the wizard's
`default-manifest.json`/`default-schemas.json` blueprint, so every freshly
provisioned app gets the same one-index-one-detail-one-form starter and the
`/builder/{slug}` route resolves the moment publish fires.

## File map

| Path | Role |
|------|------|
| [`appinfo/routes.php`](../appinfo/routes.php) | Registers `GET /api/applications/{slug}/manifest` |
| [`lib/Controller/ApplicationsController.php`](../lib/Controller/ApplicationsController.php) | `getManifest()` — the only app-local controller method |
| [`lib/Settings/openbuild_register.json`](../lib/Settings/openbuild_register.json) | OR schema declarations for `Application`, `BuiltAppRoute`, `HelloMessage`, plus the lifecycle metadata |
| [`lib/Repair/InitializeSettings.php`](../lib/Repair/InitializeSettings.php) | Imports the register into OR on install/upgrade |
| [`lib/Repair/SeedHelloWorld.php`](../lib/Repair/SeedHelloWorld.php) | Seeds the canonical hello-world virtual app |
| [`src/views/BuilderHost.vue`](../src/views/BuilderHost.vue) | Nested CnAppRoot mount with the redirected endpoint workaround |
| [`src/views/ApplicationEditor.vue`](../src/views/ApplicationEditor.vue) | Textarea-based JSON manifest editor (v1; visual editor lives in chain spec `openbuild-page-editor`) |
| [`src/router/index.js`](../src/router/index.js) | Outer routes including `/builder/:slug/:pathMatch(.*)?` |
| [`src/manifests/placeholder.json`](../src/manifests/placeholder.json) | Empty-skeleton manifest bundled into `useAppManifest` |

## Related ADRs

- **ADR-022** — apps consume OpenRegister abstractions (OpenBuild does not wrap OR's REST)
- **ADR-024** — app manifest standard (`CnAppRoot` + `CnAppNav` + `CnPageRenderer` + `useAppManifest`)
- **ADR-031** — schema-declarative business logic (the Application lifecycle is the canonical example)
- **ADR-032** — spec sizing (`bootstrap-openbuild` is `kind: mixed` under the thin-glue exception)
