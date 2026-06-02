# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

## [0.3.11] - 2026-05-31

### Added
- Schema-declared notifications: `x-openregister-notifications` rules on the `exportJob` schema (`export-succeeded` / `export-failed`) and the `ApplicationVersion` schema (`version-published` / `version-archived`), routed to manage-ACL holders via the OpenRegister notification engine with bilingual (nl/en) subjects.
- Unit test pinning that every `transition`-trigger notification rule's `trigger.action` matches a declared lifecycle transition name (`ApplicationVersionLifecycleSchemaTest::testNotificationActionsMatchLifecycleTransitionNames`).

### Fixed
- Aligned notification rule action keys with the actual OpenRegister lifecycle transition names (`succeed`/`fail`/`publish`/`archive`) instead of destination-state names (`succeeded`/`failed`/`published`/`archived`). The engine matches the transition action name, not the state, so the previous keys would never have fired — the `exportJob` rules now dispatch end-to-end via the export pipeline's `TransitionEngine` calls.
