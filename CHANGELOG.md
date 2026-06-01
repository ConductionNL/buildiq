# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
