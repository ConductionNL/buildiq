---
kind: code
depends_on: []
chain:
  - openconnector-api-sources
---

## Why

OpenBuild's app-store summary and README promise that built (virtual) apps "compose apps from registers, **connectors**, workflows, and documents" and that OpenConnector APIs are "consumed by built apps via manifest". Today only the register half is real: every page and widget in a virtual app binds exclusively to OpenRegister data (`register` + `schema` shorthand or raw GraphQL in the v2 manifest `dataSource` $def). There is **zero spec and zero change coverage** for consuming an external API through OpenConnector — the only repo mention is a passing aside in `changes/openbuild-exporter/design.md`. The 2026-06-11 feature re-evaluation flags this as the highest-severity gap: the description over-promises until this lands or is descoped.

The integration is architecturally cheap because both halves already exist:

- **OpenConnector** already owns the hard parts: Source objects hold base URLs **and credentials**, and the runtime endpoint surface `GET/POST/PUT/PATCH/DELETE /apps/openconnector/api/endpoint/{_path}` executes a configured Endpoint against its Source — authenticated by the Nextcloud session on the inside and by the Source's stored credentials on the outside.
- **The v2 manifest** already carries a declarative `dataSource` $def with `additionalProperties: true`, so a third `connector` form can ride alongside the existing `register/schema` shorthand and `graphql` forms without breaking the canonical schema.

This change adds the missing glue: a builder UI step to bind an OpenConnector endpoint as the data source of a virtual-app page or widget, a mapping editor that projects the external payload onto display fields, a runtime resolver that fetches via OpenConnector with client-side caching, and graceful degradation when OpenConnector is not installed. OpenBuild never stores or proxies credentials — auth is delegated entirely to OpenConnector.

## What Changes

- **NEW** Connector form for the manifest v2 `dataSource`: `dataSource.connector = { endpointPath, method?, query?, itemsPath?, fields }`. Carried under the $def's `additionalProperties: true` envelope; validated app-side by openbuild's manifest validation layer. Codifying the form into the canonical `app-manifest-v2.schema.json` $def is filed as a follow-up against `nextcloud-vue` (see Dependencies), not done here.
- **NEW** `src/components/pageDesigner/ConnectorSourcePicker.vue` — builder UI that lists OpenConnector endpoints (via OpenConnector's own REST API), lets the builder pick one as the data source for an index page, dashboard widget, or detail sidebar widget, and triggers a sample fetch for the mapping step.
- **NEW** `src/components/pageDesigner/ConnectorFieldMapper.vue` — schema-mapping editor: shows the sample payload as a navigable tree, lets the builder set `itemsPath` (list root) and map dot-path selectors from the external payload to named display fields (columns for index pages, value/label fields for widgets). Round-trips losslessly to/from the manifest.
- **MODIFIED** `IndexPageEditor.vue` + `DashboardPageEditor.vue` (page-designer sub-editors) — data-source origin toggle `OpenRegister | OpenConnector`; picking OpenConnector mounts the picker + mapper instead of the register/schema pickers.
- **NEW** `src/composables/useConnectorDataSource.js` — runtime resolver: detects `dataSource.connector`, fetches `GET /apps/openconnector/api/endpoint/{endpointPath}` (other verbs out of scope for v1 read-bindings), applies `itemsPath` + `fields` selectors, returns rows/values to the existing page renderers. Session-TTL cache (default 60 s, manifest-overridable via `cacheTtl`), stale-on-error fallback, explicit loading/error states.
- **NEW** Capability detection — apps with at least one connector data source get `openconnector` appended to the manifest v2 `dependencies[]` array (checked by CnAppRoot via `useAppStatus`); the designer additionally soft-checks availability so the picker degrades to a clear "OpenConnector is not installed" hint instead of an empty dropdown.
- **NO** new openbuild PHP controllers, routes, or stored credentials. The browser talks to OpenConnector's existing endpoints directly; ADR-022's no-passthrough-wrapper rule applies.

### Capabilities

#### New Capabilities

- `openconnector-api-sources`: the connector `dataSource` form, the ConnectorSourcePicker + ConnectorFieldMapper builder UI, the `useConnectorDataSource` runtime resolver with caching, credential-free auth delegation, and capability-checked graceful degradation.

#### Modified Capabilities

- `openbuild-page-designer`: index/dashboard sub-editors gain the data-source origin toggle and mount the connector picker/mapper. Existing register-bound flows are untouched; the toggle defaults to OpenRegister.

## Impact

- **New frontend code**: ~900 LOC (picker ~250, mapper ~350, resolver composable ~200, sub-editor wiring ~100) + Vitest suites.
- **No backend code**: zero new PHP. No new attack surface in openbuild; all external-API auth and SSRF posture remains OpenConnector's responsibility (its Source/Endpoint model and existing hardening).
- **Manifest**: additive only. Apps without connector sources serialize byte-identical manifests. `validateManifest` from `@conduction/nextcloud-vue` continues to pass because the `dataSource` $def allows additional properties.
- **Security**: openbuild stores **no credentials, tokens, headers, or base URLs** — only the OpenConnector endpoint path and a field mapping. A builder can only bind endpoints their NC session may already call; OpenConnector enforces its own access control on `/api/endpoint/{_path}`.
- **Dependencies / flagged follow-ups**:
  1. `nextcloud-vue` — codify the `connector` form into the canonical `app-manifest-v2.schema.json` `dataSource` $def (and optionally teach the lib's `useDataSource` to resolve it natively). Until then openbuild resolves connector sources app-side and the canonical validator tolerates the form via `additionalProperties: true`. File as a `nextcloud-vue` issue when this change is applied.
  2. `openconnector` — a stable, documented list endpoint for configured Endpoints (the `resources`-style CRUD exists; if the list route is absent or admin-only in the deployed version, the picker falls back to free-text path entry per REQ-OCAS-005). Verify against the deployed OpenConnector during apply and file an issue if the surface is missing.
- **Exporter**: out of scope — graduating a connector-bound virtual app to a real NC app keeps the same manifest form and the same runtime resolver (the exporter copies both); an exporter-specific test is included but no exporter code changes are expected.

## Open Questions

- **OQ-1**: Write-bindings (form pages POSTing through `submitEndpoint` to `/apps/openconnector/api/endpoint/{path}`) — technically already possible via the existing form-page `submitEndpoint`; specifying connector-aware UX for it is deferred to v2.
- **OQ-2**: Server-side caching of connector responses (shared across users) belongs in OpenConnector's endpoint cache config, not in openbuild — confirm with the OpenConnector roadmap; v1 caches client-side only.
