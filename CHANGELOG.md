# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.10.0] - 2026-08-05

### Added
- Published `buildiq-*` app repositories (app-repo-format-v2) now carry an
  application's bound OpenRegister flows and the agents that point at it —
  `flows/<uuid>.json` and `agents/<uuid>.json`, alongside the existing
  data-registers/connectors/automations/skills channels. Export reuses
  `FlowAndAgentExportBundler` (openbuild-exporter) unmodified via a new
  adapter, `FlowAgentChannelCollector`. Import creates each flow through
  `FlowService::save()` and rebinds it onto the local application's
  `flows[]` with the published uuid tracked as `sourceUuid` (a new OPTIONAL
  schema property, declared before it is written), and writes each agent at
  its published uuid with `applicationSlug` always overwritten to the local
  application's own slug. Both channels skip-if-already-applied on a repeat
  pull, matching every other v2 channel.

### Fixed
- `GitHubCatalogService::fetchChannelFiles()`'s fetch-side channel-prefix
  allowlist was never extended when the flows/agents channels were added to
  `AppRepoParser`, so `github/pull` (and the shop-install path, which shares
  this method) silently never downloaded either channel from a published
  repository — the exact "parser can read it, fetch never downloaded it"
  defect this method's own docblock already warned about once, for
  data-registers/connectors/automations/skills. Found live during round-trip
  verification: a repository publishing both channels pulled back with both
  declared `0` until the two missing prefixes were added.
- **Every app OpenBuild has ever generated was born declaring the wrong licence.**
  The embedded template snapshot's `appinfo/info.xml` hardcoded
  `<licence>agpl</licence>` while the very same file's description read "Free and
  open source under the EUPL-1.2 license". It now reads `<licence>{{license}}</licence>`,
  so the `license` value the export already carried end to end
  (`ExportJobService` → `RunExportJob` → `PlaceholderResolver`, defaulting to
  `EUPL-1.2` at all three layers) finally reaches the file that declares it, and
  a caller who picks a different licence gets the licence they picked. Verified
  against the Nextcloud appstore schema
  (`https://apps.nextcloud.com/schema/apps/info.xsd`): the `licence` enumeration
  **does** include `EUPL-1.2`. It does **not** include `eupl`.

### Added
- `ExporterEndToEndTest::testGeneratedAppDeclaresTheRequestedLicence()` — asserts
  a really-exported app's `appinfo/info.xml` declares `EUPL-1.2` and does not
  declare `agpl`, and that `src/manifest.json` and `composer.json` agree. Shown
  to fail against the pre-fix snapshot before it was made to pass. Nothing in the
  suite covered the generated app's licence declaration until now: the existing
  unresolved-placeholder assertion matches `/\{\{[a-zA-Z]+\}\}/`, and a hardcoded
  wrong value contains no placeholder.

### Changed
- `lib/Resources/template/.snapshot-meta.json` and `docs/releasing.md` now record
  that the embedded template is a **fork**, not a snapshot, and that the
  documented `rsync -a --delete` refresh is unsafe to run as written — it would
  revert OpenBuild-only fixes (including "the generated app could not be built at
  all", #39) and swap OpenBuild's `{{token}}` placeholder dialect for upstream's
  `{APP_NAME}` dialect, which `PlaceholderResolver` does not resolve and no test
  would catch. `docs/releasing.md` also now records that the "CI drift check" it
  describes **does not exist**.
- `lib/Resources/template/.path-manifest.txt` no longer lists `.snapshot-meta.json`;
  the regeneration command in `docs/releasing.md` excludes it, so the checked-in
  manifest disagreed with its own generator.

## [0.8.0] - 2026-07-25

### Changed
- **Theme picker now consumes nldesign's published catalogue**
  (theme-picker-consumes-nldesign) — bumps `@conduction/nextcloud-vue` to
  `^1.0.0-beta.221`, which ships `useScopedTheme()` and wires `CnAppRoot` to
  self-apply `manifest.runtime.theme`. `ThemePickerDialog.vue` collapses its
  old three-tier admin/probe/free-text catalogue fallback to a single
  `useScopedTheme().listTokenSets()` call against nldesign's real non-admin
  `GET /api/token-sets` endpoint, and adds a warn-only WCAG contrast preview
  via `evaluateContrast()` that never blocks Save. Live theme preview now
  retargets the page-designer's sandboxed live-preview-pane `CnAppRoot`
  instance instead of a separate OpenBuild-owned applier.

### Removed
- `src/composables/useAppTheme.js` — OpenBuild's own scoped-CSS
  `:root`-rewriter and injector; `CnAppRoot`'s own `useScopedTheme` watcher
  now owns runtime theme application end-to-end, with zero OpenBuild-side
  wiring in `BuilderHost.vue` or `PageDesignerHost.vue`.
- `src/services/manifestValidation/theme.js` — OpenBuild's own
  `runtime.theme` shape validator; `@conduction/nextcloud-vue`'s
  `validateManifest()` (schema 2.21.0, `$defs/runtimeTheme`) is now the
  single source for this validation.

## [0.7.7] - 2026-07-24

### Added
- **Runtime group-scoped access** (runtime-group-scoped-access) — a manifest
  `menu[]`/`pages[]` entry may declare a `permission: "group:<gid>"`; the
  runtime resolves the caller's Nextcloud group context server-side and
  `ManifestResolverService::filterManifestForCaller()` strips any entry the
  caller does not hold the permission for from the manifest response BEFORE
  it leaves the server — the authoritative gate, not client-side hiding.
  Admins and callers with an owner/editor role on the Application see the
  manifest unfiltered. A group-scoped dashboard page is promoted to the
  landing position for members who satisfy it, falling back to the default
  dashboard otherwise. `PermissionGroupField.vue` adds a group picker to the
  menu-item and page editors. Client-side `CnAppNav` filtering mirrors the
  server decision as defense in depth, not the only defense. Documented
  boundary: this hides navigation only — object-level access for the
  underlying data remains OpenRegister schema `authorization`'s job.

- **Agent workspace** (agent-workspace) — named, tool-scoped AI agents
  layered on the existing `ai-copilot` plan/execute engine (ADR-022
  consume-not-rebuild): an `Agent` (instructions, an explicit subset of the
  eight `OpenBuildToolProvider` tools, `maxActionsPerRun`) is never a wider
  capability surface than the bare copilot — enforced server-side as a
  narrowed intersection of the existing eight-tool catalogue on every
  plan/execute request, never trusted from the client.
- Transparent per-run log (`AgentRun`): every plan+execute/discard turn
  persists the prompt, plan, every tool call's arguments and result, and the
  outcome (`applied`/`rolled-back`/`discarded`/`plan-rejected`) — the Retool
  tool-chip transparency pattern, addressing the market-wide "trust gap"
  evidence directly.
- `AgentsPage.vue` (CRUD list), `AgentEditDialog.vue`, and a run-history
  view (`AgentRunHistory.vue`) restricted server-side to owners/editors of
  the agent's parent Application; `CopilotPanel.vue` gains optional
  `agentId`/`name`/`instructions`/`enabledTools` props, fully
  backwards-compatible with the existing bare-copilot surfaces.
- No autonomous/automation-triggered agent runs in v1 — an agent acts only
  inside a human-initiated chat turn.
- **Component blocks** (component-blocks) — capture a configured widget, or
  a selected multi-widget page section, from the page designer into a
  named, reusable `ComponentBlock` (new `componentBlock` OR schema,
  `lib/Settings/register.d/60-component-blocks.json`).
- Block-library panel (`NcAppSidebar`) in the page designer listing every
  org-wide block, filterable by category, with insert support.
- Insert deep-copies the fragment and mints fresh widget ids, so repeated
  insertions never collide and editing the source block never affects an
  already-inserted copy.
- Schema-dependency remap prompt (`BlockRemapDialog.vue`) on a cross-app
  insert whose schemas don't exact-match — never a silent guess, never a
  silently dropped binding.
- Blocks export/import as standalone JSON.
- Template-catalogue gallery gains a "Blocks" filter alongside full-app
  templates.

## [0.7.5] - 2026-07-24

### Added
- **Document-generation automation action** (automation-document-action) — a
  new `generateDocument` action kind on `object-created`/`object-updated`/
  `object-deleted`/`lifecycle-transition` triggers, compiling to no
  compile-time artifact (Docudesk's `correspondence/generate` route is
  stateless) and dispatching at trigger-fire time through a new
  `DocumentGenerationListener` → `DocumentGenerationService`.
- `DocumentGenerationService` calls Docudesk's existing, Newman-pinned
  `POST /apps/docudesk/api/correspondence/generate` route — never a
  `OCA\DocuDesk\*` PHP class import — impersonating the owning
  Application's owner (via the existing `JobOwnerImpersonator`) for the
  duration of exactly one internal call, authenticated with a short-lived
  Nextcloud login token minted through `OC\Authentication\Token\IProvider`
  and invalidated immediately after use.
- Three output modes: `attach` (writes the generated document to Nextcloud
  Files and sets a `{ "ref": "<fileId>" }` reference on the triggering
  object's `generatedDocument` field), `download-link` (a short-lived,
  ~24h signed URL served by the new `GeneratedDocumentController` from
  OpenBuild's own app-private storage — never the user's Files tree), and
  `notify` (reuses the existing `RuleActionDispatcher` send-notification
  path; must be paired with `attach` and/or `download-link`).
- `AutomationEditDialog` gains the `generateDocument` action editor: a
  Docudesk template picker (via the new shared `useDocudeskTemplates.js`
  composable, also adopted by `DocumentTemplateAttachmentDialog` so the
  template-list fetch has exactly one implementation) and an output-mode
  multi-select, disabled with a missing-app hint when Docudesk is absent.
- Compile-time validation (`AutomationCompilerService`): `templateId`
  required, `output` a known non-empty set, `notify` never alone, and a
  fail-closed `UnsupportedAutomationCombinationException` naming the
  missing `docudesk` dependency when Docudesk is not installed.

## [0.7.4] - 2026-07-23

### Added
- **Approval automation action** (automation-approval-steps) — a new `approval`
  action kind on `object-created`/`object-updated`/`object-deleted`/
  `lifecycle-transition` triggers, group-only assignee, compiling to an
  OpenRegister `ApprovalChain` instantiated against the trigger object
  (consume-not-rebuild, ADR-022 — no new approval engine in OpenBuild).
- On-approve/on-reject follow-up actions, composed from the same typed-action
  vocabulary (send-notification/object-op/webhook), dispatched by a typed
  listener on OR's `ApprovalStepApprovedEvent`/`ApprovalStepRejectedEvent`.
- **"My approvals" runtime widget** — lists the viewer's pending approval
  steps (filtered client-side by NC group membership) with approve/reject
  actions calling OpenRegister's `/api/approval-steps` endpoints directly.
- `AutomationsController::status()` and the dry-run test panel now report
  `approvalState: none|pending|approved|rejected` for automations carrying an
  `approval` action.

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
