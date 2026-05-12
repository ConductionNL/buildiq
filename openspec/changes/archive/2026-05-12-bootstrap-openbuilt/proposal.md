---
kind: mixed
depends_on: []
chain:
  - bootstrap-openbuilt                       # this spec (#1 of 9)
  - nextcloud-vue-in-memory-manifest          # #2 — lives in nextcloud-vue repo, parallel
  - openregister-runtime-schema-api           # #3 — lives in openregister repo
  - openbuilt-schema-editor                   # #4 — visual schema designer
  - openbuilt-page-editor                     # #5 — visual manifest/page designer
  - openbuilt-versioning                      # #6 — draft / publish / rollback
  - openbuilt-rbac                            # #7 — per-built-app permissions
  - openbuilt-templates-marketplace           # #8 — starter templates
  - openbuilt-export-to-real-app              # #9 — Phase-2 export to a real Nextcloud app
---

## Why

OpenBuilt is the citizen-developer app builder for the Conduction stack: it
lets non-technical users compose apps from OpenRegister schemas,
OpenConnector APIs, Procest workflows, Docudesk documents, NL Design
themes, and MyDash dashboards without scaffolding PHP for each new app.
ADR-024 explicitly anticipated this surface — `CnAppRoot`,
`CnAppNav`, `CnPageRenderer` and `useAppManifest` already exist in
`@conduction/nextcloud-vue`, and the canonical schema at
`nextcloud-vue/src/schemas/app-manifest.schema.json` (v1.4.0, 9 page
types) is the contract a builder targets. What's missing is the
**builder shell itself**: an app that stores manifests as objects, hosts
them at runtime by mounting a nested `CnAppRoot`, and lets an integrator
author one by hand to validate the architecture before any visual editor
exists. This is spec #1 of a 9-spec chain (ADR-032 mixed → split by
surface). Starting read-only-with-textarea proves the foundational
plumbing — nested mount, in-memory manifest, OR-backed Application
register, lifecycle metadata — and unblocks every editor / RBAC /
marketplace / export spec in the chain.

## What Changes

- **NEW** Nextcloud app shell `openbuilt` (Vue 2.7 + Pinia +
  `@conduction/nextcloud-vue`) with a single top-bar entry registered
  via `<navigations>` in `appinfo/info.xml`.
- **NEW** OpenRegister schema register at
  `lib/Settings/openbuilt_register.json` declaring two schemas:
  - `Application` — `{ uuid, slug, name, description, manifest (JSON
    blob), version, status }` with `x-openregister-lifecycle`
    declaring the `draft → published → archived` state machine
    (declarative-first per ADR-031 — **no** `ApplicationLifecycleService`
    PHP class).
  - `BuiltAppRoute` — `{ slug, applicationUuid }` index for fast
    slug → application lookup.
- **NEW** PHP controller method `ApplicationsController::getManifest`
  serving `GET /index.php/apps/openbuilt/api/applications/{slug}/manifest`
  → returns the stored manifest JSON for a given virtual-app slug.
  Standard CRUD on Application + BuiltAppRoute is delegated to OR's
  existing REST API (no per-controller wrappers — ADR-022).
- **NEW** Frontend OpenBuilt shell that lists applications, edits the
  manifest blob via a JSON textarea, and — when navigating to
  `/builder/{slug}/*` — mounts a **nested** `CnAppRoot` with the virtual
  app's manifest. Path segments after the slug forward to the inner
  manifest's vue-router.
- **NEW** Seeded `hello-world` Application exercising `index`,
  `detail`, and `form` page types with a companion `hello-message`
  schema seeded into the OpenBuilt register namespace (three sample
  objects), so the install is testable from minute one.
- **NEW** capability `openbuilt-application-register` (Application +
  BuiltAppRoute schemas, declarative lifecycle, OR multi-tenant
  scoping).
- **NEW** capability `openbuilt-runtime` (manifest endpoint, nested
  `CnAppRoot` host, textarea manifest editor, seeded
  `hello-world` virtual app).

### Capabilities

#### New Capabilities

- `openbuilt-application-register`: The OR-backed Application and
  BuiltAppRoute schemas. Owns the data model for a "virtual app"
  (manifest blob, lifecycle, ownership, tenant scoping) and the
  declarative state machine that drives its draft/published/archived
  lifecycle. Per ADR-031 the lifecycle is the canonical declarative
  example for this spec — no service class.
- `openbuilt-runtime`: The runtime that mounts a nested `CnAppRoot`
  inside the OpenBuilt shell, the per-slug manifest endpoint
  (`/api/applications/{slug}/manifest`), the textarea manifest editor,
  and the seeded `hello-world` Application that exercises the
  full path. Owns the "user navigates into a virtual app and sees a
  working CnAppRoot rendered from a stored manifest" experience.

#### Modified Capabilities

None. This is spec #1 of the chain; no existing OpenBuilt specs to
modify.

## Impact

- **New code** — `lib/Controller/ApplicationsController.php` (slim:
  `getManifest` only), `lib/Settings/openbuilt_register.json`,
  `appinfo/routes.php` route for the manifest endpoint, repair step
  to register the OR schemas + seed `hello-world`, frontend
  `src/views/BuilderHost.vue` mounting the nested `CnAppRoot`, the
  textarea editor in the existing OpenBuilt shell.
- **External dependency** — `@conduction/nextcloud-vue`'s
  `CnAppRoot` + `useAppManifest` (already shipped). Spec #2 in the
  chain will add an in-memory `useAppManifest` overload; until that
  ships, this spec uses the per-virtual-app `appId =
  openbuilt-{slug}` + per-slug manifest endpoint workaround (documented
  in `design.md`).
- **OpenRegister** — adds the OpenBuilt register namespace
  (`openbuilt`) with two schemas. Multi-tenancy via the existing
  `organisation` field; RBAC via OR's authorization (ADR-022).
- **No breaking changes** — this is a fresh app; nothing depends on
  it yet.
- **Foundational ADRs honoured** — ADR-022 (consume OR abstractions),
  ADR-024 (app manifest), ADR-031 (schema-declarative business logic),
  ADR-032 (kind: mixed with thin-glue exception — see `design.md`).
