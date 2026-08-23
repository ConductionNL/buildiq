<?php
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

// ADR-040 AppHost adoption: the canonical route set (dashboard#page,
// dashboard#catchAll, settings#index/create/load, preferences#getPreference/
// setPreference, metrics#index, health#index) is owned by the engine via
// \OCA\OpenRegister\AppHost\Routes::standard(). Buildiq's domain routes are
// passed as $extra; Routes::standard() inserts them BEFORE the SPA catch-all so
// they keep priority over the `/{path}` fallback, and keeps the canonical
// specific-first ordering. The catch-all (dashboard#catchAll) is always emitted
// LAST so it never shadows an `/api/...` route.
//
// This file references no OpenRegister symbol at top level — Routes::standard()
// is a pure array builder — so requiring it is safe even when OR is disabled.
return \OCA\OpenRegister\AppHost\Routes::standard(
    [
        // App-creation wizard endpoint (buildiq-app-creation-wizard REQ-OBWIZ-001).
        // POST /api/applications/wizard — atomic creation of Application + N versions + N registers.
        // ADMIN-ONLY (issue #157): #[AuthorizedAdminSetting(AdminSettings::class)] on the controller
        // method so NC's middleware refuses before dispatch, plus an in-body isAdmin() gate as
        // defence in depth. The caller becomes the sole owner of the new Application.
        // Must precede the {slug} + collection routes so it does not shadow them.
        ['name' => 'applicationCreation#wizard', 'url' => '/api/applications/wizard', 'verb' => 'POST'],

        // First-time-setup contract (buildiq-first-time-setup, ADR-042) — the
        // fleet-wide CnSetupWizard endpoints. Admin-only via
        // #[AuthorizedAdminSetting] on each controller method (CSRF enforced).
        // The run-action step seeds the bundled ApplicationTemplate records
        // idempotently. Specific-first, before the SPA catch-all (ADR-016/029).
        ['name' => 'setup#status', 'url' => '/api/setup/status', 'verb' => 'GET'],
        ['name' => 'setup#saveConfig', 'url' => '/api/setup/config', 'verb' => 'POST'],
        ['name' => 'setup#runAction', 'url' => '/api/setup/action/{actionId}', 'verb' => 'POST'],

        // RBAC-filtered Application list (buildiq-rbac REQ-OBRBAC-002 / REQ-OBR-007).
        // OR's schema-level read rule is a coarse group ACL — not a row-level filter on the
        // Application's `permissions` block — so the editor list MUST go through this
        // endpoint, NOT directly through `/apps/openregister/api/objects/openbuild/application`,
        // which would leak every Application + permissions to every authed user (IDOR).
        // Listed BEFORE the {slug} route so the wildcard does not shadow it (Symfony router
        // is order-sensitive when prefix overlaps).
        ['name' => 'applications#listMine', 'url' => '/api/applications', 'verb' => 'GET'],

        // Clone-from-template action (buildiq-templates-marketplace REQ-OBTC-004 / REQ-OBTC-005).
        // POST so it does not collide with the GET {slug} routes; #[NoAdminRequired] on the
        // controller method. Creates a per-app `buildiq-{newSlug}` register, deep-copies the
        // template's companion schemas into it, rewrites manifest schema refs, and persists a new
        // Application in the shared `buildiq` register tagged with the caller's UID.
        ['name' => 'applications#createFromTemplate', 'url' => '/api/applications/from-template/{templateSlug}', 'verb' => 'POST'],

        // Manifest endpoint — returns the stored manifest JSON blob for a given virtual-app slug.
        // Per ADR-016 routes.php is the only registration path; #[NoAdminRequired] is set on the
        // controller method so auth-required-but-non-admin users can hit it (per design.md Decision 6).
        // Slug matches the kebab-case pattern declared in openbuild_register.json on the Application
        // and BuiltAppRoute schemas (^[a-z0-9][a-z0-9-]*[a-z0-9]$, min 2 max 48 chars).
        ['name' => 'applications#getManifest', 'url' => '/api/applications/{slug}/manifest', 'verb' => 'GET', 'requirements' => ['slug' => '[a-z0-9][a-z0-9-]*[a-z0-9]']],

        // Symmetric write counterpart — the standalone runtime's in-app edit shell
        // (ADR-041) PUTs the full edited manifest here on Save. Owner/editor RBAC +
        // audited admin bypass live in the controller. Without this, in-app edits
        // (pages/menu/settings/sidebar/actions) were computed but never persisted.
        ['name' => 'applications#saveManifest', 'url' => '/api/applications/{slug}/manifest', 'verb' => 'PUT', 'requirements' => ['slug' => '[a-z0-9][a-z0-9-]*[a-z0-9]']],

        // Versioning — diff endpoint (chain spec #6 buildiq-versioning, REQ-OBV-005). Returns
        // two ApplicationVersion manifest blobs in one round-trip so the client diff component
        // does not double-fetch. `from`/`to` are ApplicationVersion UUIDs OR the literal `draft`.
        // Specific route MUST precede the SPA catch-all (memory rule: Symfony specific-first).
        ['name' => 'applications#diffVersions', 'url' => '/api/applications/{slug}/versions/diff', 'verb' => 'GET', 'requirements' => ['slug' => '[a-z0-9][a-z0-9-]*[a-z0-9]']],

        // ApplicationVersion CRUD + strategy-aware delete (spec
        // `application-versions` REQ-OBV-107 / REQ-OBV-108 of
        // buildiq-versioning-model). Specific routes MUST precede the
        // SPA catch-all to win Symfony's order-sensitive router (memory
        // rule: specific-first). The `/diff` route above stays first
        // because its URL is more specific than `{versionSlug}`.
        // NOTE: the parent-application placeholder is `{appSlug}`, NOT `{slug}`.
        // The POST/PUT bodies carry a `slug` field (the *version* slug), and
        // Nextcloud merges JSON body params into the same bag it resolves
        // controller arguments from — a `{slug}` route placeholder would be
        // shadowed by the body's `slug`, so `create()` would look up the
        // application by the version slug (e.g. "production") and 404. Using a
        // distinct placeholder name avoids that body/route collision (NC32).
        ['name' => 'applicationVersions#index',   'url' => '/api/applications/{appSlug}/versions',                'verb' => 'GET',    'requirements' => ['appSlug' => '[a-z0-9][a-z0-9-]*[a-z0-9]']],
        ['name' => 'applicationVersions#create',  'url' => '/api/applications/{appSlug}/versions',                'verb' => 'POST',   'requirements' => ['appSlug' => '[a-z0-9][a-z0-9-]*[a-z0-9]']],
        ['name' => 'applicationVersions#show',    'url' => '/api/applications/{appSlug}/versions/{versionSlug}',  'verb' => 'GET',    'requirements' => ['appSlug' => '[a-z0-9][a-z0-9-]*[a-z0-9]', 'versionSlug' => '[a-z0-9][a-z0-9-]*[a-z0-9]']],
        ['name' => 'applicationVersions#update',  'url' => '/api/applications/{appSlug}/versions/{versionSlug}',  'verb' => 'PUT',    'requirements' => ['appSlug' => '[a-z0-9][a-z0-9-]*[a-z0-9]', 'versionSlug' => '[a-z0-9][a-z0-9-]*[a-z0-9]']],
        ['name' => 'applicationVersions#destroy', 'url' => '/api/applications/{appSlug}/versions/{versionSlug}',  'verb' => 'DELETE', 'requirements' => ['appSlug' => '[a-z0-9][a-z0-9-]*[a-z0-9]', 'versionSlug' => '[a-z0-9][a-z0-9-]*[a-z0-9]']],
        // Owner-only release: set-as-production + publish + demote previous production
        // (`application-versions` REQ-OBV-110). Single-production invariant; NO admin bypass.
        ['name' => 'applicationVersions#release',  'url' => '/api/applications/{appSlug}/versions/{versionSlug}/release', 'verb' => 'POST', 'requirements' => ['appSlug' => '[a-z0-9][a-z0-9-]*[a-z0-9]', 'versionSlug' => '[a-z0-9][a-z0-9-]*[a-z0-9]']],

        // Insights endpoint (buildiq-app-detail-overview REQ-OBAI-001 / REQ-OBAI-007).
        // GET /api/applications/{appUuid}/versions/{versionUuid}/insights?window=7d|30d|90d
        // Returns `{kpis, activity}` for a single ApplicationVersion. #[NoAdminRequired] on
        // the controller method; RBAC happens inside ApplicationInsightsService (viewer-or-
        // better for production, editor-or-better for non-production, NC admins NOT
        // auto-granted — mirrors buildiq-version-routing). UUID path params + the
        // trailing `/insights` literal disambiguate from the slug-based CRUD routes.
        ['name' => 'applicationInsights#getInsights', 'url' => '/api/applications/{appUuid}/versions/{versionUuid}/insights', 'verb' => 'GET', 'requirements' => ['appUuid' => '[a-f0-9-]{8,}', 'versionUuid' => '[a-f0-9-]{8,}']],

        // Manual promotion endpoint (buildiq-version-promotion REQ-OBVP-001).
        // Spec mandates UUID path params (`{appUuid}/versions/{versionUuid}/promote`)
        // to distinguish this surface from the slug-based CRUD above. The trailing
        // `/promote` literal is sufficient to disambiguate from the `{versionSlug}`
        // routes — Symfony tries the more-specific URL first and the requirements
        // enforce the UUID shape so a kebab-case version slug cannot accidentally
        // match. #[NoAdminRequired] is set on the controller method; RBAC happens
        // inside (owners + editors only — admins NOT auto-granted, REQ-OBVP-007).
        ['name' => 'versionPromotion#promote', 'url' => '/api/applications/{appUuid}/versions/{versionUuid}/promote', 'verb' => 'POST', 'requirements' => ['appUuid' => '[a-f0-9-]{8,}', 'versionUuid' => '[a-f0-9-]{8,}']],

        // Explicit publish / unpublish — flips Application.status draft<->published.
        // Only a `published` Application is listed in the app menu (AppNavigationService).
        // #[NoAdminRequired] on the controller; RBAC inside (owners only + admin bypass).
        // UUID path param + trailing literal disambiguate from the slug-based CRUD above.
        ['name' => 'applicationPublish#publish', 'url' => '/api/applications/{appUuid}/publish', 'verb' => 'POST', 'requirements' => ['appUuid' => '[a-f0-9-]{8,}']],
        ['name' => 'applicationPublish#unpublish', 'url' => '/api/applications/{appUuid}/unpublish', 'verb' => 'POST', 'requirements' => ['appUuid' => '[a-f0-9-]{8,}']],
        // Owner-only full delete (Application + versions + per-version registers + routes).
        ['name' => 'applicationPublish#destroy', 'url' => '/api/applications/{appUuid}', 'verb' => 'DELETE', 'requirements' => ['appUuid' => '[a-f0-9-]{8,}']],

        // Standalone runtime page for a published virtual app — the bare
        // /builder/{slug} (no sub-path). Placed before the catch-all
        // (Routes::standard appends it) so this specific page wins.
        ['name' => 'dashboard#builder', 'url' => '/builder/{slug}', 'verb' => 'GET', 'requirements' => ['slug' => '[a-z0-9][a-z0-9-]*[a-z0-9]']],

        // Same page with a trailing slash — browsers and pasted links often add one.
        // A BARE trailing slash must still serve the standalone runtime, NOT fall to
        // the SPA catch-all the way the real designer sub-routes (/builder/{slug}/pages)
        // do. MUST use a DISTINCT route name (dashboard#builderSlash, a thin alias of
        // builder()) — the AppHost Routes::standard() guard throws on duplicate names.
        ['name' => 'dashboard#builderSlash', 'url' => '/builder/{slug}/', 'verb' => 'GET', 'requirements' => ['slug' => '[a-z0-9][a-z0-9-]*[a-z0-9]']],

        // Reserved Buildiq designer sub-paths (buildiq-deep-links #100 fix).
        // `pages`, `schemas`, `schemas/{schemaId}` and `walkthrough` are
        // Buildiq's OWN designer surfaces (src/manifest.json: PageDesigner,
        // SchemaDesignerList, SchemaDesigner, WalkthroughDesigner) — matched by
        // the SPA's OWN vue-router (main.js) before its BuilderHost wildcard.
        // They must keep serving the Buildiq SPA shell (dashboard#builderDesigner
        // renders the same page as catchAll()), NOT the standalone virtual-app
        // runtime that dashboard#builderPath now serves below. MUST precede
        // builderPath so this more-specific literal alternation wins
        // (NC/Symfony route matching is order-sensitive, first-match-wins).
        //
        // MAINTENANCE: this alternation duplicates the /builder/:slug/* designer
        // pages declared in src/manifest.json. When you add a designer surface
        // there, extend this `designerPath` requirement too, or the new URL
        // silently falls through to the runtime (builderPath). RoutesTest
        // (testManifestDesignerRoutesAllResolveToTheDesigner) derives the list
        // from src/manifest.json and FAILS if the two drift, so the omission is
        // caught in CI rather than by a user report.
        ['name' => 'dashboard#builderDesigner', 'url' => '/builder/{slug}/{designerPath}', 'verb' => 'GET', 'requirements' => ['slug' => '[a-z0-9][a-z0-9-]*[a-z0-9]', 'designerPath' => 'pages|schemas|schemas/[^/]+|walkthrough']],

        // ANY OTHER /builder/{slug}/... sub-path is a page defined by the
        // DEPLOYED virtual app's OWN manifest (buildiq-deep-links #100).
        // Direct navigation (fresh load / refresh / bookmark) previously fell
        // through to the SPA catch-all — the wrong shell, nesting the app
        // inside Buildiq's own chrome/router instead of letting the app's
        // own client-side router (builder.js, history mode) resolve it, the
        // way clicking within the app already does. `path` allows slashes
        // (requirement '.*', same trick as the SPA catch-all's `.+`) so
        // nested app pages (e.g. /tenders/{id}) deep-link correctly too.
        ['name' => 'dashboard#builderPath', 'url' => '/builder/{slug}/{path}', 'verb' => 'GET', 'requirements' => ['slug' => '[a-z0-9][a-z0-9-]*[a-z0-9]', 'path' => '.*']],

        // Icon-serving endpoints (buildiq-nextcloud-nav REQ-OBICON-002 / REQ-OBICON-003).
        // Both are #[NoAdminRequired] on the controller. ORDER MATTERS: iconDark's
        // pattern ("{slug}-dark.svg") is a SUBSET of iconLight's ("{slug}.svg") because
        // {slug} matches hyphens — so "foo-dark.svg" matches BOTH (iconDark with
        // slug="foo", OR iconLight with slug="foo-dark"). Routes match in registration
        // order (first wins), so iconDark MUST come first; otherwise every dark-icon
        // request is captured by the light route (slug="foo-dark" → no such app → light
        // default) and dark icons never resolve. Light requests ("foo.svg") lack the
        // "-dark.svg" suffix so they never match iconDark — dark-first is safe (assumes
        // no app slug itself ends in "-dark"). Placed before the SPA catch-all; after
        // exports so slug patterns don't collide.
        ['name' => 'icon#iconDark',  'url' => '/icons/{slug}-dark.svg', 'verb' => 'GET', 'requirements' => ['slug' => '[a-z0-9][a-z0-9-]*[a-z0-9]']],
        ['name' => 'icon#iconLight', 'url' => '/icons/{slug}.svg',      'verb' => 'GET', 'requirements' => ['slug' => '[a-z0-9][a-z0-9-]*[a-z0-9]']],

        // Export pipeline (Phase-2 graduation).
        ['name' => 'exports#submit',   'url' => '/api/applications/{slug}/exports', 'verb' => 'POST', 'requirements' => ['slug' => '[a-z0-9][a-z0-9-]*[a-z0-9]']],
        ['name' => 'exports#download', 'url' => '/api/exports/{uuid}/download',     'verb' => 'GET'],

        // Business-rules engine (spec business-rules-engine REQ-BRE-006 / REQ-BRE-004).
        // All three carry #[NoAdminRequired] on the controller; resolution goes
        // through searchObjectsBySlug (schema RBAC applied). `buildiq` is a
        // system-wide register, so this is NOT per-owner/per-org read isolation
        // (writes stay admin-gated at the schema). evaluate/test-all are POST so they cannot collide
        // with the GET SPA catch-all; the GET schema route's `/schema` suffix makes it
        // strictly more specific than `/{path}`. Slugs are kebab-case.
        ['name' => 'rules#evaluate', 'url' => '/api/rules/{ruleSetSlug}/evaluate', 'verb' => 'POST', 'requirements' => ['ruleSetSlug' => '[a-z0-9][a-z0-9-]*[a-z0-9]']],
        ['name' => 'rules#schema',   'url' => '/api/rules/{ruleSetSlug}/schema',   'verb' => 'GET',  'requirements' => ['ruleSetSlug' => '[a-z0-9][a-z0-9-]*[a-z0-9]']],
        ['name' => 'rules#testAll',  'url' => '/api/rules/{ruleSetSlug}/test-all', 'verb' => 'POST', 'requirements' => ['ruleSetSlug' => '[a-z0-9][a-z0-9-]*[a-z0-9]']],

        // Automation designer (spec automation-designer REQ-AUTD-005/006/007/008).
        //
        // ⚠️ CRUD ON `automation` LIVES HERE, NOT ON OR REST — and that is a
        // deliberate departure from the ADR-022 "consume OR abstractions"
        // default, scoped to this one schema. Conduction/buildiq#173.
        //
        // ADR-022 holds wherever OR's own authorization can express the
        // requirement. For `automation` it cannot. OR gates writes with a
        // COARSE, schema-level group ACL — `lib/Settings/register.d/40-automations.json`
        // declares `authorization.create/update/delete: ["admin"]` — while
        // REQ-AUTD-008 needs a FINE-GRAINED, per-object rule: "an editor of
        // THIS Application, or an owner when the version is production".
        // Those are not the same shape, and the two ways of reconciling them
        // are not equivalent:
        //
        //   (a) widen the schema to `create/update: ["authenticated"]` — then
        //       ANY authenticated user could rewrite ANY automation on ANY
        //       application straight over OR REST, with no per-application
        //       filter anywhere. That is a real regression.
        //   (b) route the writes through this controller, which authorises per
        //       application and then writes in system context, leaving the OR
        //       schema gate SHUT for direct callers.
        //
        // (b) is what these routes are. The schema stays admin-only on
        // purpose: it is the backstop that makes the check below the only way
        // in for a non-admin, so the authorization boundary is one place
        // instead of two.
        //
        // Every route is `#[NoAdminRequired]` and every one runs
        // PermissionResolver::matchesCaller() with `allowAdminBypass: false`
        // BEFORE any write or compile side effect. The uuid requirement guards
        // against a kebab-case slug accidentally matching another route.
        ['name' => 'automations#create',   'url' => '/api/automations',                'verb' => 'POST'],
        ['name' => 'automations#update',   'url' => '/api/automations/{uuid}',         'verb' => 'PUT',    'requirements' => ['uuid' => '[a-f0-9-]{8,}']],
        ['name' => 'automations#destroy',  'url' => '/api/automations/{uuid}',         'verb' => 'DELETE', 'requirements' => ['uuid' => '[a-f0-9-]{8,}']],
        ['name' => 'automations#compile',  'url' => '/api/automations/{uuid}/compile',  'verb' => 'POST', 'requirements' => ['uuid' => '[a-f0-9-]{8,}']],
        ['name' => 'automations#enable',   'url' => '/api/automations/{uuid}/enable',   'verb' => 'POST', 'requirements' => ['uuid' => '[a-f0-9-]{8,}']],
        ['name' => 'automations#disable',  'url' => '/api/automations/{uuid}/disable',  'verb' => 'POST', 'requirements' => ['uuid' => '[a-f0-9-]{8,}']],
        ['name' => 'automations#dryRun',   'url' => '/api/automations/{uuid}/dry-run',  'verb' => 'POST', 'requirements' => ['uuid' => '[a-f0-9-]{8,}']],
        ['name' => 'automations#status',   'url' => '/api/automations/{uuid}/status',   'verb' => 'GET',  'requirements' => ['uuid' => '[a-f0-9-]{8,}']],

        // App-override store-and-serve (buildiq-inline-edit-persistence,
        // spec app-override-persistence). Per-instance shared manifest delta for
        // an EXISTING fleet app, keyed by `appId`. GET returns the raw stored
        // delta for client-side merge (mergeStrategy:'delta'); PUT upserts it
        // (CSRF-enforced, Buildiq-access guard, validate-shape + non-blank);
        // DELETE clears it (idempotent). Specific-first so the `{appId}` routes
        // are not shadowed by the engine-appended SPA `/{path}` catch-all;
        // `appId` carries the kebab-case NC-app-id requirement.
        // Per-user delta layer (layered-versioned-app-deltas): GET reads the
        // caller's OWN user delta for editing; PUT upserts it (gated on the app's
        // allowUserOverrides flag); DELETE clears it. Owner = the session UID
        // always (no-admin-idor). Declared specific-first so the extra `/user`
        // segment is matched before the generic `{appId}` routes below.
        // Maintainer view: list ALL users' overrides for an app (owner/editor or
        // admin only). Declared before the {appId}/user routes (extra segment).
        ['name' => 'appOverride#listUserOverrides', 'url' => '/api/app-overrides/{appId}/user-deltas', 'verb' => 'GET', 'requirements' => ['appId' => '[a-z0-9][a-z0-9-]*[a-z0-9]']],

        ['name' => 'appOverride#getUser',   'url' => '/api/app-overrides/{appId}/user', 'verb' => 'GET',    'requirements' => ['appId' => '[a-z0-9][a-z0-9-]*[a-z0-9]']],
        ['name' => 'appOverride#saveUser',  'url' => '/api/app-overrides/{appId}/user', 'verb' => 'PUT',    'requirements' => ['appId' => '[a-z0-9][a-z0-9-]*[a-z0-9]']],
        ['name' => 'appOverride#clearUser', 'url' => '/api/app-overrides/{appId}/user', 'verb' => 'DELETE', 'requirements' => ['appId' => '[a-z0-9][a-z0-9-]*[a-z0-9]']],

        ['name' => 'appOverride#get',   'url' => '/api/app-overrides/{appId}', 'verb' => 'GET',    'requirements' => ['appId' => '[a-z0-9][a-z0-9-]*[a-z0-9]']],
        ['name' => 'appOverride#save',  'url' => '/api/app-overrides/{appId}', 'verb' => 'PUT',    'requirements' => ['appId' => '[a-z0-9][a-z0-9-]*[a-z0-9]']],
        ['name' => 'appOverride#clear', 'url' => '/api/app-overrides/{appId}', 'verb' => 'DELETE', 'requirements' => ['appId' => '[a-z0-9][a-z0-9-]*[a-z0-9]']],

        // Remote template store (buildiq-remote-template-store). Consume-only:
        // search proxies the configured remote OpenRegister catalogue server-side;
        // install resolves a remote template by slug and clones it locally via the
        // shared ApplicationsController install seam.
        ['name' => 'store#search',  'url' => '/api/store/templates',                  'verb' => 'GET'],
        ['name' => 'store#install', 'url' => '/api/store/templates/{slug}/install',   'verb' => 'POST', 'requirements' => ['slug' => '[a-z0-9][a-z0-9-]*[a-z0-9]']],

        // GitHub shop source (github-shop-catalogue REQ-GHSC-005 / REQ-GHSC-006).
        // Both #[NoAdminRequired] with an in-body 401 guard; search is an
        // instance-shared read, install parses the repo via AppRepoParser then
        // reuses ApplicationsController::installFromTemplateArray. Specific-first,
        // before the engine-appended SPA catch-all.
        ['name' => 'shop#githubSearch',  'url' => '/api/shop/github/search',  'verb' => 'GET'],
        ['name' => 'shop#githubInstall', 'url' => '/api/shop/github/install', 'verb' => 'POST'],

        // GitHub owner round-trip (github-app-sync REQ-GHAS-001..004). All four
        // #[NoAdminRequired] with a per-object owner guard (status viewer-readable).
        // The trailing `/github/{action}` literal disambiguates from the slug-based
        // CRUD + versions routes above; `{slug}` carries the kebab-case constraint.
        // Registered specific-first before the SPA catch-all.
        ['name' => 'gitHubSync#link',   'url' => '/api/applications/{slug}/github/link',   'verb' => 'POST', 'requirements' => ['slug' => '[a-z0-9][a-z0-9-]*[a-z0-9]']],
        ['name' => 'gitHubSync#push',   'url' => '/api/applications/{slug}/github/push',   'verb' => 'POST', 'requirements' => ['slug' => '[a-z0-9][a-z0-9-]*[a-z0-9]']],
        ['name' => 'gitHubSync#pull',   'url' => '/api/applications/{slug}/github/pull',   'verb' => 'POST', 'requirements' => ['slug' => '[a-z0-9][a-z0-9-]*[a-z0-9]']],
        ['name' => 'gitHubSync#status', 'url' => '/api/applications/{slug}/github/status', 'verb' => 'GET',  'requirements' => ['slug' => '[a-z0-9][a-z0-9-]*[a-z0-9]']],

        // AI copilot / prompt-to-app (spec `ai-copilot` REQ-OBAIC-001/002/004),
        // extended with optional agent-scoping (spec `agent-workspace`). All
        // four #[NoAdminRequired]; per-object RBAC (existing-app owners/
        // editors, hybrid-app rejection, agent resolution) is enforced inside
        // CopilotService, not via a route attribute. `plan` performs zero
        // builder writes; `execute` re-validates and dispatches through
        // BuildiqToolProvider::invokeTool(); `discard` only ever runs for
        // the agent-scoped chat surface (logs a discarded AgentRun).
        // Specific-first, before the engine-appended SPA catch-all.
        ['name' => 'copilot#health',  'url' => '/api/copilot/health',  'verb' => 'GET'],
        ['name' => 'copilot#plan',    'url' => '/api/copilot/plan',    'verb' => 'POST'],
        ['name' => 'copilot#execute', 'url' => '/api/copilot/execute', 'verb' => 'POST'],
        ['name' => 'copilot#discard', 'url' => '/api/copilot/discard', 'verb' => 'POST'],

        // Agent run-history (spec `agent-workspace`). #[NoAdminRequired] with a
        // per-object owners/editors guard enforced inside AgentsController —
        // AgentRun rows are NEVER served through the generic OpenRegister REST
        // surface (no row-level RBAC there; see AgentsController docblock).
        // Agent CRUD itself rides OR's generic REST surface (ADR-022), mirroring
        // AutomationsController's posture for the `automation` object.
        ['name' => 'agents#runs', 'url' => '/api/agents/{uuid}/runs', 'verb' => 'GET'],

        // Anonymous download-link resolver for the `generateDocument`
        // automation action's `download-link` output mode
        // (automation-document-action, `GeneratedDocumentController`).
        // `#[PublicPage]` — the random token IS the authorization.
        // `/api/generated-documents/` is disjoint from every other route in
        // this file so ordering relative to them is immaterial; declared
        // before the SPA catch-all.
        ['name' => 'generatedDocument#download', 'url' => '/api/generated-documents/{token}', 'verb' => 'GET'],

        // NB: the SPA catch-all (dashboard#catchAll) is appended by
        // \OCA\OpenRegister\AppHost\Routes::standard() — do NOT add it here.
    ]
);
