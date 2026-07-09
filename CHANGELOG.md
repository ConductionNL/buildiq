# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.5.40] - 2026-06-26

### Added
- Version lifecycle + switcher (version-lifecycle-and-switcher): draft versions,
  release-to-production, and a version switcher UI.
- **New draft** action — clones the production manifest and SHARES production's
  data register (manifest-only versioning; the create endpoint inherits the
  production register when none is supplied).
- **Release** action (owner-only, no admin bypass) — set-as-production + publish
  + demote the previous production, enforcing exactly one production version via
  the single-valued `productionVersion` pointer (a draft previous-production is
  demoted by the pointer move alone).
- **Open-app split button** — primary opens production; a chevron lists versions
  to view/use and edit (production marked, archived hidden).
- Click-to-open a version (`?_version=`) and per-row Edit (designer) in the
  version history; production/active markers; EN + NL i18n.

### Fixed
- Version history list was always empty — it queried a non-working OpenRegister
  objects endpoint and filtered on a non-existent `applicationUuid` field; it now
  uses `/api/applications/{slug}/versions` with the real fields.
- App-detail Register widget (and KPI register links) showed a phantom
  `openbuild-{slug}-{versionSlug}` register for shared-register versions; they now
  use the active version's real `register` field.

### Security
- Delete guard: never drop an OpenRegister register that is shared with the
  production version (a `delete-now` on a production-shared draft is downgraded to
  keep-register so production data is never destroyed).

## [0.5.7] - 2026-06-20

### Added
- Remote template store (openbuild-remote-template-store): search + install
  virtual-app templates from a remote OpenRegister-backed catalogue. Admin
  registry config (URL/register/token, token write-only), a server-side
  SSRF-guarded proxy (`RemoteTemplateStoreService`), `StoreController`
  search/install endpoints, and a store-aware Templates gallery (store primary
  when a registry is configured, built-in templates fallback otherwise). Install
  clones via the shared `installFromTemplateArray` seam. Consume-only this cut.
- DocuDesk-style dashboard: a self-contained `DashboardIndex` view (one
  `CnDashboardPage`) with a 4-KPI row (Apps / Hybrid apps / Templates /
  Published versions), a Recent apps table, and a Quick start panel.

### Fixed
- `SeedApplicationTemplates` + `PopulateApplicationPermissions` repair steps now
  write in system context (OR RBAC/multitenancy bypassed) so they no longer fail
  as the Anonymous user — the Templates KPI count is now accurate.
- Dashboard Templates KPI queried the wrong schema slug (`applicationTemplate` →
  `application-template`).

## [0.5.6] - 2026-06-20

### Added
- Unified app model (unify-apps-with-app-type): every app now carries an `appType`
  discriminator (`virtual` | `hybrid`). Hybrid apps — customizations layered over an
  installed Nextcloud fleet app — are first-class `Application` records with a
  delta-only `ApplicationVersion`, replacing the standalone `AppOverride` schema.
- `appType` + `baseRef` on the `Application` schema and `manifestDelta` + `baseRef` on
  `ApplicationVersion`.
- Virtual/Hybrid badge on app cards + the app detail header; an all/virtual/hybrid
  filter on the Apps list persisted in the `?filter=` URL query param.
- App-creation wizard gains a Virtual/Hybrid branch (hybrid = pick an installed app).
- Idempotent migration converting existing `AppOverride` rows into hybrid Applications
  (system-context writes; schema dropped only when every row migrates successfully).

### Changed
- "Virtual apps" renamed to "Apps" across the UI (menu, titles, copy); route paths
  unchanged so deep-links survive.
- `GET/PUT/DELETE /api/app-overrides/{appId}` are now compatibility shims backed by the
  hybrid Application's version (HTTP contract preserved).

### Removed
- The standalone `AppOverride` schema (folded into the unified hybrid-app model).

### Security
- Hybrid metadata-lock: a hybrid app's `slug`/`name` are read-only (mirror the installed
  app), enforced by a pre-save guard (`openbuild.hybrid_metadata.locked`).

## [0.4.0] - 2026-06-02

### Added
- Exporter GitHub delivery target: `GitHubPushService` now performs a real
  create-repo → blob/tree/commit → bootstrap-branch → pull-request sequence against
  the GitHub REST + Git Data API via Nextcloud's `IClientService` (replacing the
  Phase-1 stub). Fails fast when the target repo already exists (REQ-OBEX-007), scrubs
  PAT-shaped tokens out of error messages, and keeps the PAT method-scoped (never
  stored on the instance, never logged).
- Exporter end-to-end integration test (`tests/Integration/ExporterEndToEndTest.php`)
  asserting the resolved tree carries no unresolved `{{placeholder}}` tokens, no
  `openbuild` dependency reference (REQ-OBEX-010), and is byte-equivalent across
  re-exports (REQ-OBEX-008).
- `CleanupExpiredExportsTest` unit test (expired-ZIP purge + fresh-ZIP retention +
  idempotency).
- `docs/export-pipeline.md` (ZIP + GitHub flows, PAT contract, OQ-2/OQ-3 heuristics)
  and `docs/releasing.md` (embedded-template resnapshot procedure).
- `.github/workflows/exporter-e2e.yml` running the exporter integration + unit tests on
  every PR, parallel to the main Code Quality job.
- `openbuild-exporter` capability registered in `openspec/app-config.json`.

### Changed
- `ExportService::scratchTreeDir()` split out as a pure path resolver so the GitHub push
  target can read the generated tree; `prepareScratchDir()` owns the wipe + create.

## [0.3.12] - 2026-06-01

### Added
- Full Dutch + English translations for the visual page designer (170 strings, en↔nl parity) — the designer UI was previously untranslated (ADR-007 / `openbuild-page-designer` REQ-OBPD spec, tasks 6.1/6.2).

### Changed
- Page designer save path now targets the active `ApplicationVersion.manifest` (`PUT /api/objects/openbuild/applicationVersion/{uuid}`) per ADR-002 / Decision 6 / REQ-OBPD-009, surgical-merging the UI-controlled `manifest` field for round-trip safety; falls back to the `Application` object for apps that predate the versioned model.

### Fixed
- Removed two designer strings that leaked the internal `openbuild.page-designer.*` dotted-key prefix into the user-facing UI (live-preview unavailable note and the menu nesting-depth error).

## [0.3.11] - 2026-05-31

### Added
- Schema-declared notifications: `x-openregister-notifications` rules on the `exportJob` schema (`export-succeeded` / `export-failed`) and the `ApplicationVersion` schema (`version-published` / `version-archived`), routed to manage-ACL holders via the OpenRegister notification engine with bilingual (nl/en) subjects.
- Unit test pinning that every `transition`-trigger notification rule's `trigger.action` matches a declared lifecycle transition name (`ApplicationVersionLifecycleSchemaTest::testNotificationActionsMatchLifecycleTransitionNames`).

### Fixed
- Aligned notification rule action keys with the actual OpenRegister lifecycle transition names (`succeed`/`fail`/`publish`/`archive`) instead of destination-state names (`succeeded`/`failed`/`published`/`archived`). The engine matches the transition action name, not the state, so the previous keys would never have fired — the `exportJob` rules now dispatch end-to-end via the export pipeline's `TransitionEngine` calls.
