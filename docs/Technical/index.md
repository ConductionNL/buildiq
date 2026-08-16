---
sidebar_position: 4
title: Technical
description: ADRs, test gates, MCP catalogue, schema vocabulary — the contracts OpenBuilt ships against.
---

# Technical

OpenBuilt is built declaratively. The contracts below — ADRs, schema annotations, test gates — are the load-bearing surfaces an integrator or auditor reads first.

## Architecture decisions (ADRs)

OpenBuilt and its sibling Conduction apps share a single set of architectural decision records in the [hydra](https://github.com/ConductionNL/hydra) repo. The directly load-bearing ADRs for OpenBuilt:

| ADR | Title | What it means for OpenBuilt |
|---|---|---|
| **ADR-001** | App assets via OpenRegister files | Icons + future blobs are stored as files attached to the Application record, referenced by `{ref: "name"}` in the manifest |
| **ADR-002** | Versioned app deployment model | Per-version registers + linear promotion chain + Application.productionVersion pointer |
| **ADR-004** | Modal isolation | Every dialog lives in its own `src/dialogs/*.vue` file |
| **ADR-007** | i18n | Apps support nl + en at minimum; keys live in `l10n/{nl,en}.json` |
| **ADR-022** | Consume OR abstractions | No app-local DB access; every persistence path goes through OpenRegister |
| **ADR-024** | App manifest | Every built app is rendered at runtime by mounting `CnAppRoot` with the app's manifest |
| **ADR-031** | Schema-declarative business logic | State machines, aggregations, calculations, notifications declared as schema metadata, not service code |
| **ADR-032** | Spec sizing | Single-purpose specs; thin-glue controllers; tests come bundled in the spec change |

The full ADR set lives at [hydra/openspec/architecture](https://github.com/ConductionNL/hydra/tree/main/openspec/architecture).

## Specs shipped today

Six OpenSpec changes archived 2026-05-17:

- `2026-05-17-openbuilt-nextcloud-nav` — top-bar nav entry + icon endpoints
- `2026-05-17-openbuilt-app-creation-wizard` — three-step wizard + atomic provisioning
- `2026-05-17-openbuilt-version-routing` — `?_version=` resolver + RBAC gate
- `2026-05-17-openbuilt-app-detail-overview` — maintainer dashboard with KPIs + activity + structural widgets
- `2026-05-17-openbuilt-versioning-model` — per-version registers + promotesTo chain + auto-bump semver
- `2026-05-17-openbuilt-version-promotion` — three strategies + lock-contention 409 + on-failure archive

Each archive carries its `tasks.md` (every requirement crossed off with verification evidence), `proposal.md` (intent + design), and the spec delta(s) merged into `openspec/specs/`.

## Test gate matrix

OpenBuilt's CI gate is the same matrix every Conduction app ships against:

| Gate | Tool | Current state |
|---|---|---|
| PHP lint | `composer lint` | ✓ clean |
| PHPCS | `composer phpcs` (PHPCS + custom sniffs) | ✓ 0 errors |
| Psalm | `composer psalm` | ✓ 88% inferred |
| PHPUnit | `composer test:unit` | ✓ 216/216 |
| Vitest | `npm test` | ✓ 521/521 |
| Newman | `npm run test:newman` (12 collections) | ✓ main + version-routing pass |
| Playwright | `npm run test:e2e` | ✓ infra clean; per-spec content assertions tracked separately |
| ADR-024 schema | `npm run check:manifest` | ✓ shell + wizard seed PASS |
| Hydra gates (14) | `bash run-hydra-gates.sh` | ✓ ALL 14 GREEN |

Each green is reproducible from a clean checkout. The gates run in CI on every PR.

## MCP tool catalogue

OpenBuilt registers eight tools on the OpenRegister MCP bus so an LLM acting on behalf of a user can author apps directly:

| Tool ID | Purpose |
|---|---|
| `openbuilt.listApps` | Enumerate Applications visible to the caller |
| `openbuilt.getAppManifest` | Read the resolved manifest for `(slug, version)` |
| `openbuilt.createApp` | Atomic wizard-equivalent: app + N versions + N registers |
| `openbuilt.promoteVersion` | Run a promotion strategy on a version edge |
| `openbuilt.upsertSchema` | Create or update a per-version schema |
| `openbuilt.upsertPage` | Add/edit a manifest page entry |
| `openbuilt.addWidget` | Add a widget to a dashboard page's config |
| `openbuilt.upsertMenuItem` | Add/edit a manifest menu entry |

Every write tool re-checks the per-app RBAC gate (owner or editor required) before mutating.

## Schema vocabulary

OpenBuilt-owned schema annotations live under the `x-openregister-*` namespace and pass through OR's vocabulary whitelist (`lib/Db/Schema.php`):

- `x-openregister-lifecycle` — state machine declaration (states, initial state, transitions). Used by OpenBuilt's ApplicationVersion schema for the `draft → published → archived → draft` cycle.
- `x-openregister-validation` — assertion list on the schema (e.g. "promotesTo cannot equal own UUID" — the self-loop guard).
- `x-openregister-aggregations`, `x-openregister-calculations`, `x-openregister-notifications`, `x-openregister-widgets` — same pattern, declarative metadata read at runtime.

Unknown `x-openregister-*` keys are dropped at save time and the dropped slug is logged so typos surface immediately.

## Source

- App repo: [`ConductionNL/openbuilt`](https://github.com/ConductionNL/openbuilt)
- Foundation: [`ConductionNL/openregister`](https://github.com/ConductionNL/openregister) (OpenBuilt depends on OR ≥ 0.2.10)
- Docusaurus preset: [`@conduction/docusaurus-preset`](https://www.npmjs.com/package/@conduction/docusaurus-preset) (this docs site)
- nc-vue shared lib: [`@conduction/nextcloud-vue`](https://www.npmjs.com/package/@conduction/nextcloud-vue) (CnAppRoot, page-type registry, widget pool)

All code EUPL-1.2 — free to copy, modify and redistribute across the public sector.
