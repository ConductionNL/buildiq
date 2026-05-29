# OpenBuild

## Overview

OpenBuild is a citizen-developer app builder for Nextcloud. It composes apps from the Conduction ecosystem (OpenRegister schemas, OpenConnector APIs, Procest workflows, Docudesk documents, NL Design themes, MyDash dashboards) without scaffolding PHP for each new app.

Per ADR-024 each built app is rendered at runtime by mounting `CnAppRoot` with the app's manifest, which lives as a JSON blob in OpenBuild's own OpenRegister namespace. Per ADR-031 behaviour (state machines, aggregations, calculations, notifications) is declared as schema metadata in the register file instead of service code. Built apps are virtual at first (records in OpenBuild's register, rendered inside the OpenBuild shell at `/apps/openbuild/{slug}`); a Phase-2 export generates a real Nextcloud app from a virtual app.

## Architecture

- **Type**: Nextcloud App (PHP backend + Vue 2 frontend)
- **Data layer**: OpenRegister (`Application` and `BuiltAppRoute` schemas plus per-virtual-app schemas — all OR-backed, ADR-022)
- **Pattern**: Thin client + dynamic-render host — OpenBuild owns no DB tables of its own; virtual apps live as OR objects, rendered through a nested `CnAppRoot` mount
- **License**: EUPL-1.2

## Tech Stack

| Layer | Technology |
|-------|------------|
| Backend | PHP 8.1+, Nextcloud AppFramework, minimal controller surface (manifest endpoint only — rest of CRUD via OR REST) |
| Frontend | Vue 2.7, Pinia, `@conduction/nextcloud-vue` (`CnAppRoot`, `CnPageRenderer`, `useAppManifest`) |
| Data | OpenRegister (JSON object store + `x-openregister-lifecycle` declarative state machines) |
| Testing | PHPUnit (unit + integration), Newman (API), Playwright (E2E nested-mount tests) |
| Quality | PHPCS, PHPMD, Psalm, PHPStan, ESLint, Stylelint |

## Key Files

| File | Purpose |
|------|---------|
| `lib/AppInfo/Application.php` | App bootstrap |
| `lib/Controller/ApplicationsController.php` | Manifest endpoint (`getManifest`) — the only app-local controller surface (per ADR-022) |
| `lib/Service/SettingsService.php` | Repair-step OpenRegister integration |
| `lib/Listener/DeepLinkRegistrationListener.php` | Registers deep link patterns with OR search |
| `lib/Repair/InitializeSettings.php` | Imports the OpenBuild register schemas on install/upgrade |
| `lib/Repair/SeedHelloWorld.php` | Seeds the canonical `hello-world` virtual app (ADR-001) |
| `lib/Settings/openbuild_register.json` | OpenAPI 3.0 register schema declaring `Application`, `BuiltAppRoute`, `hello-message` |
| `src/App.vue` | App shell (navigation + routing) |
| `src/views/BuilderHost.vue` | Mounts a nested `CnAppRoot` per virtual app at `/builder/{slug}/*` |
| `src/views/ApplicationEditor.vue` | JSON textarea manifest editor (visual editor lives in chain spec #5) |
| `openspec/config.yaml` | OpenSpec project configuration |
| `openspec/changes/bootstrap-openbuild/` | Spec #1 of the 9-spec chain (this app's foundation) |

## Foundational ADRs

- ADR-022 — apps consume OpenRegister abstractions
- ADR-024 — app manifest standard (CnAppRoot, CnPageRenderer, useAppManifest)
- ADR-031 — schema-declarative business logic (no `*Service` for lifecycle / aggregation / calculation / notification)
- ADR-032 — spec sizing and chained-spec routing

## Development Setup

See the workspace-level `.claude/docs/` for:
- `commands.md` — available Claude commands
- `testing.md` — testing workflows
- `app-lifecycle.md` — full development lifecycle

## Standards

This app follows all [Conduction app standards](../hydra/openspec/architecture/).
