<?php
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

// ADR-040 AppHost adoption: the canonical route set (dashboard#page,
// dashboard#catchAll, settings#index/create/load, preferences#getPreference/
// setPreference, metrics#index, health#index) is owned by the engine via
// \OCA\OpenRegister\AppHost\Routes::standard(). OpenBuild's domain routes are
// passed as $extra; Routes::standard() inserts them BEFORE the SPA catch-all so
// they keep priority over the `/{path}` fallback, and keeps the canonical
// specific-first ordering. The catch-all (dashboard#catchAll) is always emitted
// LAST so it never shadows an `/api/...` route.
//
// This file references no OpenRegister symbol at top level — Routes::standard()
// is a pure array builder — so requiring it is safe even when OR is disabled.
return \OCA\OpenRegister\AppHost\Routes::standard(
    [
        // App-creation wizard endpoint (openbuild-app-creation-wizard REQ-OBWIZ-001).
        // POST /api/applications/wizard — atomic creation of Application + N versions + N registers.
        // #[NoAdminRequired] on the controller method; RBAC is implicit (caller becomes owner).
        // Must precede the {slug} + collection routes so it does not shadow them.
        ['name' => 'applicationCreation#wizard', 'url' => '/api/applications/wizard', 'verb' => 'POST'],

        // First-time-setup contract (openbuild-first-time-setup, ADR-042) — the
        // fleet-wide CnSetupWizard endpoints. Admin-only via
        // #[AuthorizedAdminSetting] on each controller method (CSRF enforced).
        // The run-action step seeds the bundled ApplicationTemplate records
        // idempotently. Specific-first, before the SPA catch-all (ADR-016/029).
        ['name' => 'setup#status', 'url' => '/api/setup/status', 'verb' => 'GET'],
        ['name' => 'setup#saveConfig', 'url' => '/api/setup/config', 'verb' => 'POST'],
        ['name' => 'setup#runAction', 'url' => '/api/setup/action/{actionId}', 'verb' => 'POST'],

        // RBAC-filtered Application list (openbuild-rbac REQ-OBRBAC-002 / REQ-OBR-007).
        // OR's schema-level read rule is a coarse group ACL — not a row-level filter on the
        // Application's `permissions` block — so the editor list MUST go through this
        // endpoint, NOT directly through `/apps/openregister/api/objects/openbuild/application`,
        // which would leak every Application + permissions to every authed user (IDOR).
        // Listed BEFORE the {slug} route so the wildcard does not shadow it (Symfony router
        // is order-sensitive when prefix overlaps).
        ['name' => 'applications#listMine', 'url' => '/api/applications', 'verb' => 'GET'],

        // Clone-from-template action (openbuild-templates-marketplace REQ-OBTC-004 / REQ-OBTC-005).
        // POST so it does not collide with the GET {slug} routes; #[NoAdminRequired] on the
        // controller method. Creates a per-app `openbuild-{newSlug}` register, deep-copies the
        // template's companion schemas into it, rewrites manifest schema refs, and persists a new
        // Application in the shared `openbuild` register tagged with the caller's UID.
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

        // Versioning — diff endpoint (chain spec #6 openbuild-versioning, REQ-OBV-005). Returns
        // two ApplicationVersion manifest blobs in one round-trip so the client diff component
        // does not double-fetch. `from`/`to` are ApplicationVersion UUIDs OR the literal `draft`.
        // Specific route MUST precede the SPA catch-all (memory rule: Symfony specific-first).
        ['name' => 'applications#diffVersions', 'url' => '/api/applications/{slug}/versions/diff', 'verb' => 'GET', 'requirements' => ['slug' => '[a-z0-9][a-z0-9-]*[a-z0-9]']],

        // ApplicationVersion CRUD + strategy-aware delete (spec
        // `application-versions` REQ-OBV-107 / REQ-OBV-108 of
        // openbuild-versioning-model). Specific routes MUST precede the
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

        // Insights endpoint (openbuild-app-detail-overview REQ-OBAI-001 / REQ-OBAI-007).
        // GET /api/applications/{appUuid}/versions/{versionUuid}/insights?window=7d|30d|90d
        // Returns `{kpis, activity}` for a single ApplicationVersion. #[NoAdminRequired] on
        // the controller method; RBAC happens inside ApplicationInsightsService (viewer-or-
        // better for production, editor-or-better for non-production, NC admins NOT
        // auto-granted — mirrors openbuild-version-routing). UUID path params + the
        // trailing `/insights` literal disambiguate from the slug-based CRUD routes.
        ['name' => 'applicationInsights#getInsights', 'url' => '/api/applications/{appUuid}/versions/{versionUuid}/insights', 'verb' => 'GET', 'requirements' => ['appUuid' => '[a-f0-9-]{8,}', 'versionUuid' => '[a-f0-9-]{8,}']],

        // Manual promotion endpoint (openbuild-version-promotion REQ-OBVP-001).
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

        // Reserved OpenBuild designer sub-paths (openbuild-deep-links #100 fix).
        // `pages`, `schemas`, `schemas/{schemaId}` and `walkthrough` are
        // OpenBuild's OWN designer surfaces (src/manifest.json: PageDesigner,
        // SchemaDesignerList, SchemaDesigner, WalkthroughDesigner) — matched by
        // the SPA's OWN vue-router (main.js) before its BuilderHost wildcard.
        // They must keep serving the OpenBuild SPA shell (dashboard#builderDesigner
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
        // DEPLOYED virtual app's OWN manifest (openbuild-deep-links #100).
        // Direct navigation (fresh load / refresh / bookmark) previously fell
        // through to the SPA catch-all — the wrong shell, nesting the app
        // inside OpenBuild's own chrome/router instead of letting the app's
        // own client-side router (builder.js, history mode) resolve it, the
        // way clicking within the app already does. `path` allows slashes
        // (requirement '.*', same trick as the SPA catch-all's `.+`) so
        // nested app pages (e.g. /tenders/{id}) deep-link correctly too.
        ['name' => 'dashboard#builderPath', 'url' => '/builder/{slug}/{path}', 'verb' => 'GET', 'requirements' => ['slug' => '[a-z0-9][a-z0-9-]*[a-z0-9]', 'path' => '.*']],

        // Icon-serving endpoints (openbuild-nextcloud-nav REQ-OBICON-002 / REQ-OBICON-003).
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
        // All three carry #[NoAdminRequired] on the controller; multi-tenant isolation
        // is enforced server-side in RuleEngineService (a slug owned by another tenant
        // resolves to 404 — no IDOR). evaluate/test-all are POST so they cannot collide
        // with the GET SPA catch-all; the GET schema route's `/schema` suffix makes it
        // strictly more specific than `/{path}`. Slugs are kebab-case.
        ['name' => 'rules#evaluate', 'url' => '/api/rules/{ruleSetSlug}/evaluate', 'verb' => 'POST', 'requirements' => ['ruleSetSlug' => '[a-z0-9][a-z0-9-]*[a-z0-9]']],
        ['name' => 'rules#schema',   'url' => '/api/rules/{ruleSetSlug}/schema',   'verb' => 'GET',  'requirements' => ['ruleSetSlug' => '[a-z0-9][a-z0-9-]*[a-z0-9]']],
        ['name' => 'rules#testAll',  'url' => '/api/rules/{ruleSetSlug}/test-all', 'verb' => 'POST', 'requirements' => ['ruleSetSlug' => '[a-z0-9][a-z0-9-]*[a-z0-9]']],

        // Automation designer (spec automation-designer REQ-AUTD-005/006/007/008).
        // Thin, value-adding routes only — CRUD on the `automation` object itself
        // stays on OR REST per ADR-022; these five uuid-addressed routes are the
        // security boundary (AutomationsController enforces RBAC via
        // PermissionResolver before any compile side effect, no admin bypass).
        // All POST except the read-only `status` GET; uuid requirement guards
        // against a kebab-case slug accidentally matching another route.
        ['name' => 'automations#compile',  'url' => '/api/automations/{uuid}/compile',  'verb' => 'POST', 'requirements' => ['uuid' => '[a-f0-9-]{8,}']],
        ['name' => 'automations#enable',   'url' => '/api/automations/{uuid}/enable',   'verb' => 'POST', 'requirements' => ['uuid' => '[a-f0-9-]{8,}']],
        ['name' => 'automations#disable',  'url' => '/api/automations/{uuid}/disable',  'verb' => 'POST', 'requirements' => ['uuid' => '[a-f0-9-]{8,}']],
        ['name' => 'automations#dryRun',   'url' => '/api/automations/{uuid}/dry-run',  'verb' => 'POST', 'requirements' => ['uuid' => '[a-f0-9-]{8,}']],
        ['name' => 'automations#status',   'url' => '/api/automations/{uuid}/status',   'verb' => 'GET',  'requirements' => ['uuid' => '[a-f0-9-]{8,}']],

        // App-override store-and-serve (openbuild-inline-edit-persistence,
        // spec app-override-persistence). Per-instance shared manifest delta for
        // an EXISTING fleet app, keyed by `appId`. GET returns the raw stored
        // delta for client-side merge (mergeStrategy:'delta'); PUT upserts it
        // (CSRF-enforced, OpenBuild-access guard, validate-shape + non-blank);
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

        // Remote template store (openbuild-remote-template-store). Consume-only:
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

        // AI copilot / prompt-to-app (spec `ai-copilot` REQ-OBAIC-001/002/004).
        // All three #[NoAdminRequired]; per-object RBAC (existing-app owners/
        // editors, hybrid-app rejection) is enforced inside CopilotService, not
        // via a route attribute. `plan` performs zero writes; `execute` re-
        // validates and dispatches through OpenBuildToolProvider::invokeTool().
        // Specific-first, before the engine-appended SPA catch-all.
        ['name' => 'copilot#health',  'url' => '/api/copilot/health',  'verb' => 'GET'],
        ['name' => 'copilot#plan',    'url' => '/api/copilot/plan',    'verb' => 'POST'],
        ['name' => 'copilot#execute', 'url' => '/api/copilot/execute', 'verb' => 'POST'],

        // Share-token management (public-forms-runtime, public-form-access
        // "Token management UI in the page designer and app settings").
        // Authenticated, owner/editor-only — SAME RBAC posture as
        // applications#saveManifest (session/organisation, NOT a token).
        // `{slug}` is the OWNING Application's slug, never the token itself.
        // Specific-first: `/share-tokens` and `/share-tokens/{tokenUuid}` are
        // strictly more specific than the `/api/applications/{slug}/manifest`
        // route above, so ordering relative to it is immaterial, but both are
        // still declared before the engine-appended SPA catch-all.
        ['name' => 'shareToken#index',  'url' => '/api/applications/{slug}/share-tokens',             'verb' => 'GET',    'requirements' => ['slug' => '[a-z0-9][a-z0-9-]*[a-z0-9]']],
        ['name' => 'shareToken#create', 'url' => '/api/applications/{slug}/share-tokens',             'verb' => 'POST',   'requirements' => ['slug' => '[a-z0-9][a-z0-9-]*[a-z0-9]']],
        ['name' => 'shareToken#revoke', 'url' => '/api/applications/{slug}/share-tokens/{tokenUuid}', 'verb' => 'DELETE', 'requirements' => ['slug' => '[a-z0-9][a-z0-9-]*[a-z0-9]']],

        // Anonymous public render + submit (public-forms-runtime,
        // public-form-access "Public render endpoint resolves a token to
        // exactly its bound page" / "Anonymous submission writes via
        // owner-context service"). `#[PublicPage]` on both controller
        // methods — resolves SOLELY through the `{token}` path param, never
        // session/organisation (openbuild-runtime "Public manifest
        // resolution never uses session/organisation authorization"). The
        // `/submit` suffix disambiguates the two verbs on the same resource
        // without relying on verb-only dispatch. Registered before the SPA
        // catch-all; the `/api/public/...` prefix is disjoint from every
        // other route in this file so ordering relative to them is
        // immaterial.
        ['name' => 'publicForm#render', 'url' => '/api/public/forms/{token}',        'verb' => 'GET'],
        ['name' => 'publicForm#submit', 'url' => '/api/public/forms/{token}/submit', 'verb' => 'POST'],

        // Anonymous public-form SHELL page (serves the bootstrap HTML/JS —
        // see DashboardController::publicForm()). A page route (not
        // `/api/...`), deliberately outside `/builder/{slug}` — a share
        // token names an Application + page, not a slug, and the shell must
        // carry zero session assumptions (own template + own JS entry,
        // `openbuild-public-form`). `#[PublicPage]` on the controller
        // method. Specific-first, before the SPA catch-all.
        ['name' => 'dashboard#publicForm', 'url' => '/public/forms/{token}', 'verb' => 'GET'],

        // Anonymous download-link resolver for the `generateDocument`
        // automation action's `download-link` output mode
        // (automation-document-action, `GeneratedDocumentController`).
        // `#[PublicPage]` — the random token IS the authorization, mirroring
        // the ShareToken/publicForm routes above. `/api/generated-documents/`
        // is disjoint from every other route in this file so ordering
        // relative to them is immaterial; declared before the SPA catch-all.
        ['name' => 'generatedDocument#download', 'url' => '/api/generated-documents/{token}', 'verb' => 'GET'],

        // NB: the SPA catch-all (dashboard#catchAll) is appended by
        // \OCA\OpenRegister\AppHost\Routes::standard() — do NOT add it here.
    ]
);
