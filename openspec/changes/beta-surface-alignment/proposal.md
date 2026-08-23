---
kind: docs
depends_on: []
---

## Why

Buildiq is the fleet's citizen-developer app builder ("Compose Nextcloud
apps from the Technical Core", per `conduction-website/src/pages/connext.mdx`).
Before beta release, its four public-facing surfaces — `appinfo/info.xml`,
`src/manifest.json` nav, the `conduction.nl/apps/buildiq` product page
(EN+NL), and the `buildiq.conduction.nl` docs — must agree on feature
vocabulary, version, license, and dependency declarations. They did not:

- `appinfo/info.xml` declared `<licence>agpl</licence>` while `composer.json`
  says `"license": "EUPL-1.2"`, the description body itself says "Free and
  open source under the EUPL-1.2 license", and every source file's SPDX
  header reads `EUPL-1.2` — a hard beta blocker (wrong license shown in the
  Nextcloud app store).
- `info.xml`'s description (EN+NL), `openspec/app-config.json`, and
  `openspec/README.md` all named **"LaunchPad dashboards"** as a composable
  source. No `LaunchPad`/`launchpad` integration exists anywhere in `lib/` or
  `src/` — the only hit is an unrelated input placeholder string
  (`src/components/page-editor/CustomPageEditor.vue`: `"e.g. LaunchPadboard"`).
  Fabricated claim.
- The product page (EN+NL) and `docs/intro.md` claimed **"n8n workflows"** as
  a data-wiring source. `lib/` has zero n8n integration code — the only two
  hits are code comments in `ConditionActionExecutor.php` and `FeelParser.php`
  noting that n8n workflows are an *external, undelegated* concern, not
  something Buildiq implements. Procest workflow attachments
  (`WorkflowAttachmentsSection.vue`, `useProcestCase.js`, `ProcestCaseStatusPanel.vue`)
  are the real, implemented workflow integration.
- `docs/intro.md`'s frontmatter description named **"Pipelinq"** as a
  data-wiring source — Pipelinq is an unrelated CRM app with no code
  connection to Buildiq; copy-paste error for "Procest".
- Product page version was stale (`v0.3`) against `info.xml`'s `0.5.40`.
- NL product page's `secondaryCta` pointed at the dead
  `docs.conduction.nl/buildiq` — the real docs deploy topology
  (`docs/docusaurus.config.js`) serves at `buildiq.conduction.nl`.
- `info.xml` declared no `<app>` dependency despite `src/manifest.json`
  declaring `"dependencies": ["openregister"]` as a hard requirement — every
  virtual-app manifest, schema, and object Buildiq manages is an
  OpenRegister row (ADR-024).
- "Config-over-code" and "fork-free customisation" — the positioning already
  used for Buildiq on `connext.mdx` — were not reflected in the product
  page/docs copy even though the capability is real (`AppOverrideService`:
  delta-only manifest overrides on top of a fleet app's bundled base, so
  customisation survives upgrades) nor was GitHub export mentioned as an
  alternative to the ZIP-only wording.

## What Changes

Verified every connector/feature/license claim against `lib/` and `src/` at
HEAD. No code was added; only metadata, product copy, and docs text were
corrected.

### Verified as real (kept / clarified in copy)

| Claim | Evidence |
|---|---|
| Citizen-developer app builder, compose apps without PHP scaffolding | `lib/Controller/ApplicationCreationController.php`, `lib/Service/ApplicationCreationService.php` (wizard: `single \| dev-prod \| dev-staging-prod \| custom` presets) |
| Compose from the Technical Core: OpenRegister schemas, OpenConnector APIs, Procest workflows, DocuDesk documents, NL Design themes | OpenRegister — every `Application`/`ApplicationVersion`/schema is an OR row (ADR-024); OpenConnector — 15 files under `lib/`/`src/` reference it; Procest — `WorkflowAttachmentsSection.vue`, `useProcestCase.js`, `ProcestCaseStatusPanel.vue`, `procestLinks.js`; DocuDesk — `useDocudeskDocument.js`, `DocumentAttachmentsSection.vue`, `DocumentTemplateAttachmentDialog.vue`; NL Design — `ThemePickerDialog.vue`, `useAppTheme.js`, `ThemeSection.vue` |
| Config-over-code / declarative business logic (ADR-031): state machines, aggregations, calculations, notifications as schema metadata, no per-app PHP service | `lib/Service/RuleEngineService.php`, `DecisionTableEvaluator.php`, `FeelParser.php`, `ConditionActionExecutor.php` |
| Fork-free customisation — manifest overrides survive upgrades | `lib/Service/AppOverrideService.php` — delta-only `ApplicationVersion` (`manifestDelta`) merged client-side over a fleet app's own bundled base at load time; base-app upgrades don't wipe the customisation |
| Schema designer | `src/views/SchemaDesigner.vue`, spec `buildiq-schema-designer` |
| Page designer (list/detail/form/dashboard pages, widgets) | `src/views/PageDesigner.vue`, `PageDesignerHost.vue` |
| Manifest editing / hybrid override layer | `src/views/ManifestLayersDetail.vue`, `lib/Controller/AppOverrideController.php`, `lib/Service/ManifestResolverService.php` |
| Template store — curated starters + remote template store | `src/views/TemplateGallery.vue`, `lib/Controller/StoreController.php` (`search()`/`install()`, spec `buildiq-remote-template-store`), `lib/Repair/SeedApplicationTemplates.php` |
| Dev/staging/production version tiers, promotion, rollback | `lib/Controller/VersionPromotionController.php`, `lib/Service/ApplicationVersionService.php`, `VersionPromotionService.php` |
| Export to ZIP | `lib/Controller/ExportsController.php`, `lib/Service/ExportService.php` (`ZipArchive`-based `packageZip()`) |
| Export to git/GitHub (Phase-2 real-app export) | `lib/Service/GitHubPushService.php` — pushes generated app tree to a new GitHub repo via `IClientService` against the GitHub REST + Git Data API; PAT method-scoped, never persisted (Decision 3) |
| First-time-setup / onboarding walkthrough tours | `src/manifest.json` `walkthrough` block (`trigger: "first-visit"`, `buildiq:getting-started` tour), `src/views/WalkthroughDesignerHost.vue`, `WalkthroughDesigner.vue`, `WalkthroughRecorder.vue` |
| RBAC — per-app + per-record via OpenRegister | `lib/Service/PermissionResolver.php`, `lib/Repair/PopulateApplicationPermissions.php` |
| 8 MCP chat tools (listApps, getAppManifest, createApp, promoteVersion, upsertSchema, upsertPage, addWidget, upsertMenuItem) | `lib/Mcp/BuildiqToolProvider.php` + one handler class per tool under `lib/Mcp/Handler/` — IDs/names match the product page's `McpToolShelf` exactly |

### Corrected / removed (unverified or fabricated)

| Removed/corrected claim | Why |
|---|---|
| `<licence>agpl</licence>` | Contradicts `composer.json` (`EUPL-1.2`), the description body's own "EUPL-1.2 license" sentence, and every file's SPDX header. Corrected to `<licence>EUPL-1.2</licence>`. |
| "LaunchPad dashboards" as a composable source (`info.xml` EN+NL description) | Zero `LaunchPad`/`launchpad` integration anywhere in `lib/`/`src/`; only an unrelated placeholder string in an input hint. Removed from `info.xml`. (`openspec/app-config.json` and `openspec/README.md` carry the same stale sentence but are internal project docs, not one of the four public surfaces in scope — flagged for a follow-up, not changed here.) |
| "n8n workflows" (product page EN+NL, `docs/intro.md`) | No n8n integration code exists; only comments noting it's an *external* concern. Replaced with "Procest workflows", which is real. |
| "Pipelinq" (`docs/intro.md` frontmatter) | Copy-paste error — Pipelinq is an unrelated app. Replaced with "Procest". |
| Stale product-page version `v0.3` | `info.xml` (source of truth) is `0.5.40`. Bumped product page (EN+NL) to `v0.5`. |
| NL page's dead `docs.conduction.nl/buildiq` link | Real docs deploy topology serves at `buildiq.conduction.nl` (`docs/docusaurus.config.js` `url:`). Corrected to match the EN page. |
| Missing `<app>openregister</app>` dependency | `src/manifest.json` already declares `"dependencies": ["openregister"]` as hard. Added the `<app>` element (precedent: `openconnector`, `portaliq` `info.xml`). |
| "Config-over-code" / GitHub export not mentioned in copy | Both capabilities are real (see table above) but were absent from the product-page intro and docs bullet list. Added. |

## Impact

- Affected files: `buildiq/appinfo/info.xml`;
  `conduction-website/src/pages/apps/buildiq.mdx`;
  `conduction-website/i18n/nl/docusaurus-plugin-content-pages/apps/buildiq.mdx`;
  `buildiq/docs/intro.md`.
- No code changes. No behavior changes. No new dependencies (the
  `<app>openregister</app>` element documents an existing runtime dependency,
  it does not add one).
- `src/manifest.json` nav/menu labels (Dashboard, Apps, Store, Documentation,
  Features & roadmap) were read as the canonical feature-name source and were
  already accurate — no edit needed there.
- Still misaligned, needs a decision: `openspec/app-config.json` and
  `openspec/README.md` both still carry the fabricated "LaunchPad dashboards"
  sentence (out of scope for this change — internal docs, not a public
  surface) — recommend a follow-up cleanup pass.
