# Coverage Report — openbuilt

Generated: 2026-05-24 00:00 UTC
Branch: development
Scanner: opsx-coverage-scan v1

## Summary

| Bucket | Count | Next action |
|---|---|---|
| annotated | 0 | — (no `@spec openspec/changes/` tags in the tree) |
| plumbing | 29 | — (never tagged) |
| 1 — REQ matched | 185 | `/opsx-annotate openbuilt` |
| 2a — existing capability, no REQ | 17 (2 clusters) | `/opsx-reverse-spec openbuilt --extend <cap>` |
| 2b — no capability owner | 1 (1 cluster) | `/opsx-reverse-spec openbuilt --cluster deep-link-registration` |
| 3a — REQ broken (code removed) | 0 | — |
| 3b — REQ never implemented (or out-of-scope here) | 22 | Mark deferred or confirm frontend coverage |
| 4 — ADR conformance | 12 findings across 4 rules | Follow-up issue |

## Bucket 1 — Ready to annotate (via ghost change `retrofit-2026-05-24-annotate-openbuilt`)

Note: the suggested annotation tag is `@spec openspec/changes/retrofit-2026-05-24-annotate-openbuilt/tasks.md#task-N` where the ghost change's tasks.md will carry one task per (capability, REQ) pair.

### capability: openbuilt-runtime

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| lib/Controller/ApplicationsController.php | getManifest | REQ-OBR-001 (+ rbac-clone REQ-OBR-006) | 0.95 | file docblock + routes.php |
| lib/Controller/ApplicationsController.php | resolveApplicationBySlug | REQ-OBR-001 | 0.80 | Pass B inherit, multi-caller |
| lib/Controller/DashboardController.php | page | REQ-OBR-009 | 0.90 | file docblock cites REQ-OBR-009 |
| lib/Controller/DashboardController.php | catchAll | REQ-OBR-009 | 0.80 | SPA fall-through twin |
| lib/Controller/DashboardController.php | publishCurrentUserGroups | REQ-OBR-009 | 0.95 | name = REQ verb |
| lib/Mcp/OpenBuiltToolProvider.php | handleGetAppManifest | REQ-OBR-001 | 0.80 | MCP equivalent |

### capability: version-routing

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| lib/Controller/ApplicationsController.php | resolveVersionedManifestResponse | REQ-OBVR-001 / REQ-OBVR-002 | 0.90 | dispatches to ManifestResolverService |
| lib/Service/ManifestResolverService.php | resolve | REQ-OBVR-002 / REQ-OBVR-003 | 0.95 | file docblock |
| lib/Service/ManifestResolverService.php | checkNonProductionAccess | REQ-OBVR-003 | 0.90 | Pass B inherit |
| lib/Service/ManifestResolverService.php | findApplicationBySlug | REQ-OBVR-002 | 0.80 | step 1 of two-step lookup |
| lib/Service/ManifestResolverService.php | resolveProductionManifest | REQ-OBVR-002 | 0.85 | Pass B inherit |
| lib/Service/ManifestResolverService.php | findVersionBySlug | REQ-OBVR-002 | 0.85 | step 2 |
| lib/Service/ManifestResolverService.php | findVersionBySlugFallback | REQ-OBVR-002 | 0.75 NEEDS-REVIEW | Pass B inherit |
| lib/Service/ManifestResolverService.php | findVersionByUuid | REQ-OBVR-002 | 0.75 NEEDS-REVIEW | Pass B inherit |
| lib/Service/ManifestResolverService.php | isCallerAuthorised | REQ-OBVR-003 | 0.80 | Pass B inherit |
| lib/Service/ManifestResolverService.php | bucketContainsUid | REQ-OBVR-003 | 0.70 NEEDS-REVIEW | Pass B inherit |

### capability: openbuilt-version-snapshots

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| lib/Controller/ApplicationsController.php | diffVersions | REQ-OBV-005 | 0.95 | routes.php + file docblock |
| lib/Controller/ApplicationsController.php | resolveVersionBlob | REQ-OBV-005 | 0.80 | Pass B inherit |

### capability: openbuilt-rbac

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| lib/Controller/ApplicationsController.php | listMine | REQ-OBRBAC-002 / REQ-OBRBAC-003 | 0.95 | file docblock + routes.php |
| lib/Controller/ApplicationsController.php | filterApplicationsByRole | REQ-OBRBAC-003 | 0.85 | name matches REQ scenario |
| lib/Controller/ApplicationsController.php | requirePermission | REQ-OBRBAC-002 / REQ-OBR-006-clone | 0.85 | Pass B inherit |
| lib/Controller/ApplicationsController.php | recordAdminBypass | REQ-OBRBAC-007 | 0.90 | EVENT_ADMIN_BYPASS audit |
| lib/Controller/ApplicationsController.php | getUserGroupIds | REQ-OBRBAC-002 | 0.75 NEEDS-REVIEW | Pass B inherit |
| lib/Controller/ApplicationsController.php | collectAuthorisedGroups | REQ-OBRBAC-002 | 0.80 | Pass B inherit |
| lib/Controller/ApplicationsController.php | classifyPrincipal | REQ-OBRBAC-002 | 0.75 NEEDS-REVIEW | Pass B inherit |
| lib/Controller/ApplicationVersionsController.php | requireRole | REQ-OBRBAC-002 | 0.80 | role-gate on CRUD |
| lib/Controller/ApplicationVersionsController.php | collectAuthorisedPrincipals | REQ-OBRBAC-002 | 0.75 NEEDS-REVIEW | Pass B inherit |
| lib/Controller/ApplicationVersionsController.php | absorbPrincipalBucket | REQ-OBRBAC-002 | 0.70 NEEDS-REVIEW | Pass B inherit |
| lib/Controller/ApplicationVersionsController.php | getUserGroupIds | REQ-OBRBAC-002 | 0.70 NEEDS-REVIEW | Pass B inherit |
| lib/Controller/ExportsController.php | isAuthorisedForApplication | REQ-OBRBAC-002 | 0.80 | ADR-005 IDOR guard |
| lib/Controller/ExportsController.php | fallbackAuthoriseViaOrLookup | REQ-OBRBAC-002 | 0.70 NEEDS-REVIEW | Pass B inherit |
| lib/Mcp/OpenBuiltToolProvider.php | handleListApps | REQ-OBRBAC-003 | 0.70 NEEDS-REVIEW | MCP equivalent of listMine |

### capability: openbuilt-template-catalogue

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| lib/Controller/ApplicationsController.php | createFromTemplate | REQ-OBTC-004 / REQ-OBTC-005 | 0.95 | file docblock + routes.php |
| lib/Controller/ApplicationsController.php | resolveSharedContext | REQ-OBTC-004 | 0.75 NEEDS-REVIEW | Pass B inherit |
| lib/Controller/ApplicationsController.php | buildClonedManifest | REQ-OBTC-005 | 0.85 | Pass B inherit |
| lib/Controller/ApplicationsController.php | provisionPerAppArtifacts | REQ-OBTC-005 | 0.85 | Pass B inherit |
| lib/Controller/ApplicationsController.php | persistApplication | REQ-OBTC-004 | 0.80 | Pass B inherit |
| lib/Controller/ApplicationsController.php | validateCloneRequest | REQ-OBTC-004 | 0.75 NEEDS-REVIEW | Pass B inherit |
| lib/Controller/ApplicationsController.php | extractCompanionSchemas | REQ-OBTC-005 | 0.85 | Pass B inherit |
| lib/Controller/ApplicationsController.php | buildRewriteMap | REQ-OBTC-005 | 0.85 | Pass B inherit |
| lib/Controller/ApplicationsController.php | provisionPerAppRegister | REQ-OBTC-005 | 0.90 | maps to spec scenario |
| lib/Controller/ApplicationsController.php | findOrCreateRegister | REQ-OBTC-005 | 0.75 NEEDS-REVIEW | Pass B inherit |
| lib/Controller/ApplicationsController.php | extractRegisterOwner | REQ-OBTC-005 | 0.70 NEEDS-REVIEW | Pass B inherit |
| lib/Controller/ApplicationsController.php | cloneCompanionSchemas | REQ-OBTC-005 | 0.90 | deep-copy per spec |
| lib/Controller/ApplicationsController.php | rewriteSchemaRefs | REQ-OBTC-005 | 0.85 | Pass B inherit |
| lib/Controller/ApplicationsController.php | lookupOne | REQ-OBTC-005 | 0.70 NEEDS-REVIEW | Pass B inherit |
| lib/Repair/SeedApplicationTemplates.php | run | REQ-OBTC-002 | 0.95 | file docblock |
| lib/Repair/SeedApplicationTemplates.php | validateFixture | REQ-OBTC-009 | 0.90 | file docblock |
| lib/Repair/SeedApplicationTemplates.php | findBySlug | REQ-OBTC-002 | 0.75 NEEDS-REVIEW | idempotency guard |

### capability: application-versions

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| lib/Controller/ApplicationVersionsController.php | index | REQ-OBV-107 | 0.95 | file docblock |
| lib/Controller/ApplicationVersionsController.php | show | REQ-OBV-107 | 0.95 | file docblock |
| lib/Controller/ApplicationVersionsController.php | create | REQ-OBV-107 | 0.95 | file docblock |
| lib/Controller/ApplicationVersionsController.php | update | REQ-OBV-107 | 0.95 | file docblock |
| lib/Controller/ApplicationVersionsController.php | destroy | REQ-OBV-108 | 0.95 | file docblock — strategy-aware delete |
| lib/Controller/ApplicationVersionsController.php | loadApplication | REQ-OBV-107 | 0.75 NEEDS-REVIEW | Pass B inherit |
| lib/Controller/ApplicationVersionsController.php | findVersionForApplication | REQ-OBV-107 | 0.80 | Pass B inherit |
| lib/Service/ApplicationVersionService.php | canonicaliseManifest | REQ-OBV-103 | 0.90 | SHA-256 diff source |
| lib/Service/ApplicationVersionService.php | hashManifest | REQ-OBV-103 | 0.90 | SHA-256 diff source |
| lib/Service/ApplicationVersionService.php | bumpPatch | REQ-OBV-103 | 0.95 | name = REQ wording |
| lib/Service/ApplicationVersionService.php | onSave | REQ-OBV-103 | 0.85 | auto-bump hook |
| lib/Service/ApplicationVersionService.php | guardNoCycle | REQ-OBV-104 | 0.95 | name = REQ wording |
| lib/Service/ApplicationVersionService.php | deleteVersion | REQ-OBV-108 | 0.95 | strategy-aware delete |
| lib/Service/ApplicationVersionService.php | assertValidStrategy | REQ-OBV-108 | 0.85 | Pass B inherit |
| lib/Service/ApplicationVersionService.php | assertNotProductionVersion | REQ-OBV-108 | 0.85 | Pass B inherit |
| lib/Service/ApplicationVersionService.php | dropPerVersionRegister | REQ-OBV-108 | 0.85 | delete-now strategy |
| lib/Service/ApplicationVersionService.php | flagRegisterOrphaned | REQ-OBV-108 | 0.80 | orphan-grace strategy |
| lib/Service/ApplicationVersionService.php | resolveNextPromotesTo | REQ-OBV-108 | 0.75 NEEDS-REVIEW | Pass B inherit |

### capability: openbuilt-application-register

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| lib/Service/ApplicationVersionService.php | guardProductionVersionOwnership | REQ-OBA-008 | 0.90 | listener docblock |
| lib/Listener/ProductionVersionGuardListener.php | handle | REQ-OBA-008 / REQ-OBV-105 | 0.95 | file docblock |
| lib/Listener/ProductionVersionGuardListener.php | extractSchemaSlug | REQ-OBA-008 | 0.75 NEEDS-REVIEW | Pass B inherit |
| lib/Listener/ProductionVersionGuardListener.php | extractObjectData | REQ-OBA-008 | 0.70 NEEDS-REVIEW | Pass B inherit |
| lib/Listener/ProductionVersionGuardListener.php | extractUuid | REQ-OBA-008 | 0.65 NEEDS-REVIEW | Pass B inherit |
| lib/Repair/PopulateApplicationPermissions.php | run | REQ-OBA-007 | 0.95 | file docblock |
| lib/Repair/PopulateApplicationPermissions.php | needsMigration | REQ-OBA-007 | 0.80 | Pass B inherit |

### capability: application-creation-wizard

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| lib/Controller/ApplicationCreationController.php | wizard | REQ-OBWIZ-001 / 007 / 010 | 0.95 | file docblock |
| lib/Controller/ApplicationCreationController.php | collectPayload | REQ-OBWIZ-001 | 0.75 NEEDS-REVIEW | Pass B inherit |
| lib/Service/ApplicationCreationService.php | createApplication | REQ-OBWIZ-007/008/009/010 | 0.95 | file docblock + flow comment |
| lib/Service/ApplicationCreationService.php | validatePayload | REQ-OBWIZ-005 / 006 | 0.90 | slug + chain validation |
| lib/Service/ApplicationCreationService.php | resolveVersionChain | REQ-OBWIZ-002 / 006 | 0.85 | REQ scenario noun match |
| lib/Service/ApplicationCreationService.php | appSlugExists | REQ-OBWIZ-005 | 0.80 | Pass B inherit |
| lib/Service/ApplicationCreationService.php | provisionRegister | REQ-OBWIZ-008 | 0.90 | per-version register |
| lib/Service/ApplicationCreationService.php | deleteRegister | REQ-OBWIZ-007 | 0.80 | rollback helper |
| lib/Service/ApplicationCreationService.php | rollback | REQ-OBWIZ-007 | 0.95 | name = REQ wording |
| lib/Service/ApplicationCreationService.php | loadDefaultManifest | REQ-OBWIZ-009 | 0.80 | initial manifest |
| lib/Service/ApplicationCreationService.php | loadDefaultSchemas | REQ-OBWIZ-008 | 0.80 | seed schema set |
| lib/Service/ApplicationCreationService.php | substituteRegisterSlug | REQ-OBWIZ-008 | 0.80 | Pass B inherit |
| lib/Service/ApplicationCreationService.php | substituteVersionContext | REQ-OBWIZ-009 | 0.80 | Pass B inherit |
| lib/Service/SlugValidator.php | validateAppSlug | REQ-OBWIZ-005 | 0.95 | file docblock |
| lib/Service/SlugValidator.php | validateVersionSlug | REQ-OBWIZ-005 | 0.95 | file docblock |
| lib/Service/SlugValidator.php | validateChainSlugs | REQ-OBWIZ-006 | 0.95 | file docblock |
| lib/Mcp/OpenBuiltToolProvider.php | handleCreateApp | REQ-OBWIZ-001 | 0.80 | MCP surface |

### capability: application-insights

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| lib/Controller/ApplicationInsightsController.php | getInsights | REQ-OBAI-001 / 006 / 002 | 0.95 | file + method docblock |
| lib/Service/ApplicationInsightsService.php | requireAuthorisedCaller | REQ-OBAI-002 | 0.95 | file docblock |
| lib/Service/ApplicationInsightsService.php | computeInsights | REQ-OBAI-001 / 004 / 005 | 0.95 | file docblock |
| lib/Service/ApplicationInsightsService.php | deriveSchemaIds | REQ-OBAI-003 | 0.95 | file docblock |
| lib/Service/ApplicationInsightsService.php | extractSchemaIdForRegister | REQ-OBAI-003 | 0.80 | Pass B inherit |
| lib/Service/ApplicationInsightsService.php | isAuthorised | REQ-OBAI-002 | 0.80 | Pass B inherit |
| lib/Service/ApplicationInsightsService.php | callerInAnyRole | REQ-OBAI-002 | 0.75 NEEDS-REVIEW | Pass B inherit |
| lib/Service/ApplicationInsightsService.php | safeDistinctActorCount | REQ-OBAI-004 | 0.85 | Active-users KPI |
| lib/Service/ApplicationInsightsService.php | countObjects | REQ-OBAI-004 | 0.85 | Object-count KPI |
| lib/Service/ApplicationInsightsService.php | countAttachedFiles | REQ-OBAI-004 | 0.85 | Files KPI |
| lib/Service/ApplicationInsightsService.php | countAuditEvents | REQ-OBAI-004 | 0.85 | Audit-events KPI |
| lib/Service/ApplicationInsightsService.php | buildActivityTimeline | REQ-OBAI-005 | 0.90 | Activity payload |
| lib/Service/ApplicationInsightsService.php | sumChartSeries | REQ-OBAI-005 | 0.75 NEEDS-REVIEW | Pass B inherit |
| lib/Service/ApplicationInsightsService.php | mergeChartIntoBuckets | REQ-OBAI-005 | 0.75 NEEDS-REVIEW | Pass B inherit |
| lib/Service/ApplicationInsightsService.php | mergeSeriesData | REQ-OBAI-005 | 0.75 NEEDS-REVIEW | Pass B inherit |

### capability: version-promotion

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| lib/Controller/VersionPromotionController.php | promote | REQ-OBVP-001 / 007 | 0.95 | file docblock |
| lib/Controller/VersionPromotionController.php | mapExceptionToResponse | REQ-OBVP-006 / 009 | 0.80 | Pass B inherit |
| lib/Controller/VersionPromotionController.php | buildLockedResponse | REQ-OBVP-006 | 0.85 | 409 lock-contention |
| lib/Controller/VersionPromotionController.php | loadApplication | REQ-OBVP-001 | 0.75 NEEDS-REVIEW | Pass B inherit |
| lib/Controller/VersionPromotionController.php | loadVersion | REQ-OBVP-001 | 0.75 NEEDS-REVIEW | Pass B inherit |
| lib/Controller/VersionPromotionController.php | assertEditorOrOwner | REQ-OBVP-007 | 0.90 | name = REQ wording |
| lib/Service/VersionPromotionService.php | defaultStrategyFor | REQ-OBVP-011 | 0.95 | name = REQ |
| lib/Service/VersionPromotionService.php | promote | REQ-OBVP-001 | 0.95 | top-level entry |
| lib/Service/VersionPromotionService.php | runStrategy | REQ-OBVP-002 / 003 / 004 | 0.90 | Pass B inherit |
| lib/Service/VersionPromotionService.php | runStartWithSourceData | REQ-OBVP-002 | 0.95 | name = REQ |
| lib/Service/VersionPromotionService.php | runMigrateExistingData | REQ-OBVP-003 | 0.95 | name = REQ |
| lib/Service/VersionPromotionService.php | runEmptyStart | REQ-OBVP-004 | 0.95 | name = REQ |
| lib/Service/VersionPromotionService.php | forwardSchemaSetToOR | REQ-OBVP-005 | 0.90 | schema-diff deferred to OR |
| lib/Service/VersionPromotionService.php | wipeTargetRegister | REQ-OBVP-002 / 004 | 0.85 | Pass B inherit |
| lib/Service/VersionPromotionService.php | copyRowsFromSource | REQ-OBVP-002 | 0.85 | Pass B inherit |
| lib/Service/VersionPromotionService.php | applyManifestAndSemver | REQ-OBVP-008 | 0.95 | name = REQ wording |
| lib/Service/VersionPromotionService.php | handlePromotionFailure | REQ-OBVP-009 | 0.95 | archive flip per REQ |
| lib/Service/VersionPromotionService.php | acquireLock | REQ-OBVP-006 | 0.95 | OR object lock |
| lib/Service/VersionPromotionService.php | releaseLock | REQ-OBVP-006 | 0.90 | Pass B inherit |
| lib/Service/VersionPromotionService.php | callGetLockInfo | REQ-OBVP-006 | 0.80 | Pass B inherit |
| lib/Mcp/OpenBuiltToolProvider.php | handlePromoteVersion | REQ-OBVP-001 | 0.80 | MCP surface |

### capability: app-icon-management

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| lib/Controller/IconController.php | iconLight | REQ-OBICON-002 | 0.95 | docblocks + routes.php |
| lib/Controller/IconController.php | iconDark | REQ-OBICON-003 | 0.95 | docblocks + routes.php |
| lib/Controller/IconController.php | buildIconResponse | REQ-OBICON-002 / 003 | 0.85 | Pass B inherit |
| lib/Service/IconService.php | getIconStream | REQ-OBICON-002 / 003 | 0.95 | file docblock |
| lib/Service/IconService.php | fetchApplication | REQ-OBICON-001 | 0.80 | Pass B inherit |
| lib/Service/IconService.php | resolveIconLight | REQ-OBICON-002 | 0.85 | Pass B inherit |
| lib/Service/IconService.php | resolveIconDark | REQ-OBICON-003 | 0.85 | Pass B inherit |
| lib/Service/IconService.php | streamForIconField | REQ-OBICON-001 / 002 | 0.75 NEEDS-REVIEW | Pass B inherit |
| lib/Service/IconService.php | fetchAttachedFileStream | REQ-OBICON-001 | 0.75 NEEDS-REVIEW | Pass B inherit |
| lib/Service/IconService.php | fallbackStream | REQ-OBICON-002 | 0.75 NEEDS-REVIEW | Pass B inherit |

### capability: app-nav-entries

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| lib/Service/AppNavigationService.php | registerNavEntries | REQ-OBNAV-001 | 0.95 | name = REQ verb |
| lib/Service/AppNavigationService.php | isVisibleForCurrentUser | REQ-OBNAV-002 / 003 | 0.95 | docblock lists four rules |
| lib/Service/AppNavigationService.php | flattenPermissions | REQ-OBNAV-002 | 0.80 | Pass B inherit |
| lib/Service/AppNavigationService.php | principalsMatchGroups | REQ-OBNAV-002 | 0.80 | Pass B inherit |
| lib/Service/AppNavigationService.php | getPublishedApplications | REQ-OBNAV-001 / 004 | 0.85 | per-request fetch |

### capability: openbuilt-exporter

| File | Method | REQ (by title) | Confidence | Signal |
|---|---|---|---|---|
| lib/Controller/ExportsController.php | submit | "Export is asynchronous via Nextcloud's IJob" | 0.90 | route shape matches spec |
| lib/Controller/ExportsController.php | download | "Export target — ZIP archive" | 0.90 | route shape matches spec |
| lib/Controller/ExportsController.php | isAuthorisedForJob | "ExportJob schema declaration" | 0.70 NEEDS-REVIEW | per-job auth |
| lib/Controller/ExportsController.php | validateSubmitBody | "ExportJob schema declaration" | 0.80 | body validation per schema |
| lib/Controller/ExportsController.php | validateGithubFields | "Export target — GitHub repository" | 0.85 | GitHub-specific fields |
| lib/Service/ExportService.php | generateAppZip | "Exported tree shape conforms to the nextcloud-app-template baseline" | 0.90 | ZIP generation |
| lib/Service/ExportService.php | packageZip | "Export target — ZIP archive" | 0.90 | Pass B inherit |
| lib/Service/ExportService.php | listFilesSorted | "Re-exports are idempotent" | 0.80 | byte-equivalence support |
| lib/Service/ExportService.php | resolvePlaceholders | "Exported tree shape conforms to the nextcloud-app-template baseline" | 0.90 | matches spec scenario |
| lib/Service/ExportService.php | isBinary | "Exported tree shape ... baseline" | 0.70 NEEDS-REVIEW | binary-skip helper |
| lib/Service/ExportService.php | copyTemplate | "Exported tree shape ... baseline" | 0.85 | copies lib/Resources/template |
| lib/Service/ExportService.php | prepareScratchDir | "Export target — ZIP archive" | 0.75 NEEDS-REVIEW | Pass B inherit |
| lib/Service/ExportService.php | getOrCreateAppDataDir | "Export target — ZIP archive" | 0.80 | appdata storage |
| lib/Service/ExportService.php | rrmdir | "Export target — ZIP archive" | 0.70 NEEDS-REVIEW | scratch cleanup |
| lib/Service/ExportJobService.php | queue | "Export is asynchronous via Nextcloud's IJob" | 0.95 | IJobList |
| lib/Service/ExportJobService.php | persistJob | "ExportJob schema declaration" | 0.85 | OR record |
| lib/Service/ExportJobService.php | transitionJob | "ExportJob schema declaration (lifecycle)" | 0.90 | queued→running→succeeded\|failed |
| lib/Service/ExportJobService.php | mergeJobFields | "ExportJob schema declaration" | 0.80 | Pass B inherit |
| lib/Service/ExportJobService.php | resolveDownload | "Export target — ZIP archive" | 0.90 | downloadUrl + expiry |
| lib/Service/ExportJobService.php | fetchPat | "Export target — GitHub repository" | 0.95 | ICredentialsManager |
| lib/Service/ExportJobService.php | clearPat | "Export target — GitHub repository" | 0.95 | PAT wipe on terminal |
| lib/Service/ExportJobService.php | credentialKey | "Export target — GitHub repository" | 0.80 | Pass B inherit |
| lib/Service/ExportJobService.php | uuid4 | "ExportJob schema declaration" | 0.65 NEEDS-REVIEW | UUID helper |
| lib/Service/GitHubPushService.php | push | "Export target — GitHub repository" | 0.95 | file docblock; STUB (Bucket 4) |
| lib/Service/GitHubPushService.php | resolveDefaultBranch | "Export target — GitHub repository" | 0.85 | PR default-branch |
| lib/Service/PlaceholderResolver.php | buildMap | "Exported tree shape ... baseline" | 0.85 | substitution map |
| lib/Service/PlaceholderResolver.php | resolve | "Exported tree shape ... baseline" | 0.85 | string resolver |
| lib/Service/PlaceholderResolver.php | slug | "Exported tree shape ... baseline" | 0.70 NEEDS-REVIEW | Pass B inherit |
| lib/Service/PlaceholderResolver.php | pascalCase | "Exported tree shape ... baseline" | 0.70 NEEDS-REVIEW | namespace casing |
| lib/BackgroundJob/RunExportJob.php | run | "Export is asynchronous via Nextcloud's IJob" | 0.95 | implements IJob |
| lib/BackgroundJob/RunExportJob.php | extractJobUuid | "Export is asynchronous via Nextcloud's IJob" | 0.70 NEEDS-REVIEW | Pass B inherit |
| lib/BackgroundJob/RunExportJob.php | executePipeline | "Export is asynchronous via Nextcloud's IJob" | 0.85 | pipeline drive |
| lib/BackgroundJob/RunExportJob.php | maybePush | "Export target — GitHub repository" | 0.85 | invokes GitHubPushService |
| lib/BackgroundJob/RunExportJob.php | buildSuccessFields | "ExportJob schema declaration" | 0.75 NEEDS-REVIEW | Pass B inherit |
| lib/BackgroundJob/CleanupExpiredExports.php | run | "Export target — ZIP archive (24h expiry + purge)" | 0.95 | file docblock |

### capability: green-field-migration

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| lib/Repair/MigrateToVersionedModel.php | run | REQ-OBGFM-001 | 0.95 | @destructive + docblock |
| lib/Repair/MigrateToVersionedModel.php | isAlreadyVersioned | REQ-OBGFM-002 | 0.95 | docblock cites REQ |
| lib/Repair/MigrateToVersionedModel.php | enumerateApplications | REQ-OBGFM-001 | 0.80 | Pass B inherit |
| lib/Repair/MigrateToVersionedModel.php | migrateOne | REQ-OBGFM-003 / 004 | 0.85 | per-app log + register-delete |

### capability: openbuilt-schema-designer / openbuilt-page-designer (MCP surface)

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| lib/Mcp/OpenBuiltToolProvider.php | handleUpsertSchema | REQ-OBSD-006 | 0.75 NEEDS-REVIEW | MCP surface for schema save |
| lib/Mcp/OpenBuiltToolProvider.php | handleUpsertPage | REQ-OBPD-009 | 0.75 NEEDS-REVIEW | MCP surface for page save |
| lib/Mcp/OpenBuiltToolProvider.php | handleAddWidget | REQ-OBPD-004 | 0.70 NEEDS-REVIEW | widget add path |
| lib/Mcp/OpenBuiltToolProvider.php | handleUpsertMenuItem | REQ-OBPD-001 | 0.80 | menu-tree editor |

## Bucket 2a — Existing capability, no REQ (reverse-spec --extend)

### cluster: openbuilt-runtime (16 methods)

The MCP tool provider exposes the OpenBuilt authoring surface to LLMs (per ADR-019 integration registry). None of the openbuilt-runtime REQs mention an MCP tool surface — they describe the HTTP / Vue runtime. Recommend `/opsx-reverse-spec openbuilt --extend openbuilt-runtime` to add 1–2 REQs covering "MCP tool surface exposing CRUD + promotion + manifest mutations".

- lib/Mcp/OpenBuiltToolProvider.php::getAppId / getTools / invokeTool — MCP catalogue + dispatcher
- lib/Mcp/OpenBuiltToolProvider.php::loadVersion / saveVersionManifest — internal version IO
- lib/Mcp/OpenBuiltToolProvider.php::validateListAppsArgs / resolveApplicationBySlug / mapApplication / sourceDescriptor / errorResult — MCP-internal plumbing
- lib/Mcp/OpenBuiltToolProvider.php::requireAuthenticatedUser / isAdmin / isValidSlug / buildDeepLink / toArray / extractUuid — guards + helpers

### cluster: app-icon-management (1 method)

- lib/Service/IconService.php::extractUuid — observed: UUID accessor on Application row, used internally for OR-file lookup. No scenario coverage in current REQs.

## Bucket 2b — No capability owner (reverse-spec --cluster)

### cluster: deep-link-registration (1 method)

- lib/Listener/DeepLinkRegistrationListener.php::handle — observed: registers OpenBuilt deep-link URL patterns with OpenRegister's search provider on the `DeepLinkRegistrationEvent`. No openbuilt spec covers integration-registry / deep-link patterns. Closest concept is ADR-019, but no REQ names this concrete surface.

Recommended: `/opsx-reverse-spec openbuilt --cluster deep-link-registration` to author a small spec (1–2 REQs) for the deep-link contract.

## Bucket 3 — Surfaced for human triage

### 3a — possibly broken (0)

None. The reverse-pass keyword scan against `/tmp/removed-lines-openbuilt.txt` (5,249 lines) did not surface any REQ whose keywords appear ≥ 2 times in removed lines with strong specificity. Code historically removed (e.g. SeedHelloWorld) is intentionally superseded.

### 3b — never implemented OR out-of-scope (22)

The current pass is PHP-only. Many "unimplemented" REQs are in fact frontend implementations — listed here for visibility, but they are NOT genuinely missing.

Genuinely declarative / no PHP method expected:
- openbuilt-application-register REQ-OBA-001, REQ-OBA-002, REQ-OBA-003, REQ-OBA-004, REQ-OBA-005, REQ-OBA-006 — schema / lifecycle declarations in `lib/Settings/openbuilt_register.json`
- application-versions REQ-OBV-101, REQ-OBV-102, REQ-OBV-106 — schema declarations + OR-side lifecycle
- openbuilt-rbac REQ-OBRBAC-001 — declarative schema (PopulateApplicationPermissions covers migration only)

Frontend implementations (out of scope for this pass):
- openbuilt-runtime REQ-OBR-002, 003, 005, 007 (Schemas menu), 008 (VersionHistory.vue), 009 (Rollback), 010 (ManifestDiff.vue), 013 (ApplicationCard)
- openbuilt-page-designer REQ-OBPD-001 through REQ-OBPD-011
- openbuilt-schema-designer REQ-OBSD-001 through REQ-OBSD-008
- application-detail-overview REQ-OBADO-001 through REQ-OBADO-012
- version-routing REQ-OBVR-004 through REQ-OBVR-009
- openbuilt-version-snapshots REQ-OBV-002 (lifecycle), REQ-OBV-003 (rollback frontend)
- openbuilt-template-catalogue REQ-OBTC-001, 003, 006, 007, 008, 010
- openbuilt-rbac REQ-OBRBAC-004 (useRole composable)
- openbuilt-runtime REQ-OBR-007 (draft-published indicator)

Possibly absent / superseded:
- openbuilt-runtime REQ-OBR-004 — seeded hello-world Application. Superseded by green-field-migration + the creation wizard; no SeedHelloWorld repair step in the live tree.
- openbuilt-runtime REQ-OBR-006 (Application editor exposes a Publish action) — frontend; if not in src/views, treat as missing.
- openbuilt-rbac REQ-OBRBAC-005 (Transfer-ownership flow) — no PHP controller endpoint found; verify whether frontend PUTs directly via OR REST.
- openbuilt-rbac REQ-OBRBAC-006 (Global `openbuilt.use` nav-entry permission) — the `group:*` sentinel handling lives in AppNavigationService (REQ-OBNAV-003); standalone REQ-OBRBAC-006 wording is not separately implemented. Likely the same concern under two REQ headings.

## Bucket 4 — ADR conformance findings

### missing-spec-in-file-docblock (8 files)

Every PHP file under lib/ should carry `@spec openspec/changes/{change}/tasks.md#task-N` per ADR-003 §Spec traceability. None currently do.

- lib/Repair/InitializeSettings.php
- lib/Listener/DeepLinkRegistrationListener.php
- lib/Controller/SettingsController.php
- lib/Controller/HealthController.php
- lib/Controller/MetricsController.php
- lib/Controller/DashboardController.php
- lib/Sections/SettingsSection.php
- lib/Settings/AdminSettings.php

The other ~35 PHP files cite REQ IDs in their file docblocks but as free-text ("REQ-OBV-107"), not in the `@spec openspec/changes/...` form required by ADR-003. The `/opsx-annotate` step will add the canonical `@spec` tags pointing at the ghost change's tasks.md.

### missing-spdx-in-file-docblock (2 files)

- lib/Repair/InitializeSettings.php — stale 2024 Conduction copyright, no SPDX
- lib/Listener/DeepLinkRegistrationListener.php — stale 2024 Conduction copyright, no SPDX

Both are pre-template files. Fix: copy the canonical SPDX block (already used by the other 41 PHP files) and bump copyright to 2026.

### stub-implementation (1 file)

- lib/Service/GitHubPushService.php — file docblock states "Phase-1 implementation: stubbed. The wire-protocol contract is locked in (signatures + PAT handling); the live HTTP calls land in a follow-up PR once `knplabs/github-api` is on the lockfile." Affects exporter REQ "Export target — GitHub repository" — the GitHub path will not actually push to GitHub at runtime. Suggest filing a tracking issue for the live-wire follow-up.

### spec-purpose-stubbed (17 specs)

Every spec file's `## Purpose` section reads "TBD - created by archiving change <name>. Update Purpose after archive." — the archive step didn't update Purpose on any of the 17 specs.

- openspec/specs/app-icon-management/spec.md
- openspec/specs/app-nav-entries/spec.md
- openspec/specs/application-creation-wizard/spec.md
- openspec/specs/application-detail-overview/spec.md
- openspec/specs/application-insights/spec.md
- openspec/specs/application-versions/spec.md
- openspec/specs/green-field-migration/spec.md
- openspec/specs/openbuilt-application-register/spec.md
- openspec/specs/openbuilt-exporter/spec.md
- openspec/specs/openbuilt-page-designer/spec.md
- openspec/specs/openbuilt-rbac/spec.md
- openspec/specs/openbuilt-runtime/spec.md
- openspec/specs/openbuilt-schema-designer/spec.md
- openspec/specs/openbuilt-template-catalogue/spec.md
- openspec/specs/openbuilt-version-snapshots/spec.md
- openspec/specs/version-promotion/spec.md
- openspec/specs/version-routing/spec.md

## Notes for the human reviewer

- **Spec heading format mismatch.** The skill's documented REQ-ID pattern is `[A-Z]{2,4}-[0-9]+[a-z]*` (e.g. `REQ-001`, `ZRC-005b`). openbuilt specs use `### Requirement: REQ-OBxxx-NNN <Title>` (ID embedded in colon-prefixed title), and `openbuilt-exporter` specs use `### Requirement: <Title>` only (no REQ-ID). Both work as REQ headings but the annotate skill needs to handle title-only form (use `cap#title-slug` as a stable identifier).
- **Duplicate REQ-IDs in openbuilt-runtime.** REQ-OBR-006, REQ-OBR-007, REQ-OBR-008, REQ-OBR-009 each appear two or three times in the spec — each archived change reused the same numeric prefix and the archive merge concatenated without de-duping. The JSON annotates the clones with capability-prefix suffixes (e.g. `REQ-OBR-006(rbac-clone)`); a real fix is to renumber + redirect.
- **Frontend (src/) is not classified.** ~88 Vue/JS files implement large parts of openbuilt-page-designer, openbuilt-schema-designer, application-detail-overview, openbuilt-rbac (useRole), version-routing client helpers, and most of openbuilt-runtime. Bucket 3b is inflated by this — the next pass should add JS/Vue support to the scanner or run a separate frontend pass.
- **High overall confidence.** Because controllers + services already cite REQ IDs in their file docblocks, ~75% of Bucket 1 lands at ≥ 0.85 confidence. The 0.70–0.80 NEEDS-REVIEW entries are almost all private helpers inherited via Pass B; they're usually safe to annotate but worth a human eyeball.
- **Bucket 1 size (185 methods).** Above the skill's 150-method threshold. Recommend running `/opsx-annotate openbuilt --capability <cap>` one capability at a time when that flag lands; otherwise the annotate ghost change PR will be very wide.
- **GitHub push is a stub.** The exporter looks complete in shape, but the GitHub-target branch will not push live in this build. The wire-protocol contract is locked; the lockfile bump + live HTTP wiring is a real follow-up PR.
- **SeedHelloWorld is gone.** The openbuilt-runtime REQ-OBR-004 (seeded hello-world Application) was superseded by green-field-migration + the creation wizard. Either deprecate the REQ or rewrite it to describe SeedApplicationTemplates' role.
- **Reverse-pass cache.** `/tmp/removed-lines-openbuilt.txt` written; 5,249 lines covering full git history of lib/ + src/. No Bucket 3a hits — no REQs found with strong evidence of historical implementation followed by removal.
