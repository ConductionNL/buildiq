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

        // Icon-serving endpoints (openbuild-nextcloud-nav REQ-OBICON-002 / REQ-OBICON-003).
        // Both are #[NoAdminRequired] on the controller. The dark route uses a longer
        // URL pattern ("{slug}-dark.svg") that is unambiguous — it cannot shadow the
        // light route because slugs are kebab-case [a-z0-9-] and never end in "-dark".
        // Placed before the SPA catch-all; after exports so slug patterns don't collide.
        ['name' => 'icon#iconLight', 'url' => '/icons/{slug}.svg',      'verb' => 'GET', 'requirements' => ['slug' => '[a-z0-9][a-z0-9-]*[a-z0-9]']],
        ['name' => 'icon#iconDark',  'url' => '/icons/{slug}-dark.svg', 'verb' => 'GET', 'requirements' => ['slug' => '[a-z0-9][a-z0-9-]*[a-z0-9]']],

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

        // NB: the SPA catch-all (dashboard#catchAll) is appended by
        // \OCA\OpenRegister\AppHost\Routes::standard() — do NOT add it here.
    ]
);
