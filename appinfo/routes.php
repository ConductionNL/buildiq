<?php
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

return [
    'routes' => [
        // Dashboard + Settings.
        ['name' => 'dashboard#page', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'settings#index', 'url' => '/api/settings', 'verb' => 'GET'],
        ['name' => 'settings#create', 'url' => '/api/settings', 'verb' => 'POST'],
        ['name' => 'settings#load',  'url' => '/api/settings/load', 'verb' => 'POST'],

        // Prometheus metrics endpoint.
        ['name' => 'metrics#index', 'url' => '/api/metrics', 'verb' => 'GET'],
        // Health check endpoint.
        ['name' => 'health#index', 'url' => '/api/health', 'verb' => 'GET'],

        // App-creation wizard endpoint (openbuilt-app-creation-wizard REQ-OBWIZ-001).
        // POST /api/applications/wizard — atomic creation of Application + N versions + N registers.
        // #[NoAdminRequired] on the controller method; RBAC is implicit (caller becomes owner).
        // Must precede the {slug} + collection routes so it does not shadow them.
        ['name' => 'applicationCreation#wizard', 'url' => '/api/applications/wizard', 'verb' => 'POST'],

        // RBAC-filtered Application list (openbuilt-rbac REQ-OBRBAC-002 / REQ-OBR-007).
        // OR's schema-level read rule is a coarse group ACL — not a row-level filter on the
        // Application's `permissions` block — so the editor list MUST go through this
        // endpoint, NOT directly through `/apps/openregister/api/objects/openbuilt/application`,
        // which would leak every Application + permissions to every authed user (IDOR).
        // Listed BEFORE the {slug} route so the wildcard does not shadow it (Symfony router
        // is order-sensitive when prefix overlaps).
        ['name' => 'applications#listMine', 'url' => '/api/applications', 'verb' => 'GET'],

        // Clone-from-template action (openbuilt-templates-marketplace REQ-OBTC-004 / REQ-OBTC-005).
        // POST so it does not collide with the GET {slug} routes; #[NoAdminRequired] on the
        // controller method. Creates a per-app `openbuilt-{newSlug}` register, deep-copies the
        // template's companion schemas into it, rewrites manifest schema refs, and persists a new
        // Application in the shared `openbuilt` register tagged with the caller's UID.
        ['name' => 'applications#createFromTemplate', 'url' => '/api/applications/from-template/{templateSlug}', 'verb' => 'POST'],

        // Manifest endpoint — returns the stored manifest JSON blob for a given virtual-app slug.
        // Per ADR-016 routes.php is the only registration path; #[NoAdminRequired] is set on the
        // controller method so auth-required-but-non-admin users can hit it (per design.md Decision 6).
        // Slug matches the kebab-case pattern declared in openbuilt_register.json on the Application
        // and BuiltAppRoute schemas (^[a-z0-9][a-z0-9-]*[a-z0-9]$, min 2 max 48 chars).
        ['name' => 'applications#getManifest', 'url' => '/api/applications/{slug}/manifest', 'verb' => 'GET', 'requirements' => ['slug' => '[a-z0-9][a-z0-9-]*[a-z0-9]']],

        // Versioning — diff endpoint (chain spec #6 openbuilt-versioning, REQ-OBV-005). Returns
        // two ApplicationVersion manifest blobs in one round-trip so the client diff component
        // does not double-fetch. `from`/`to` are ApplicationVersion UUIDs OR the literal `draft`.
        // Specific route MUST precede the SPA catch-all (memory rule: Symfony specific-first).
        ['name' => 'applications#diffVersions', 'url' => '/api/applications/{slug}/versions/diff', 'verb' => 'GET', 'requirements' => ['slug' => '[a-z0-9][a-z0-9-]*[a-z0-9]']],

        // ApplicationVersion CRUD + strategy-aware delete (spec
        // `application-versions` REQ-OBV-107 / REQ-OBV-108 of
        // openbuilt-versioning-model). Specific routes MUST precede the
        // SPA catch-all to win Symfony's order-sensitive router (memory
        // rule: specific-first). The `/diff` route above stays first
        // because its URL is more specific than `{versionSlug}`.
        ['name' => 'applicationVersions#index',   'url' => '/api/applications/{slug}/versions',                'verb' => 'GET',    'requirements' => ['slug' => '[a-z0-9][a-z0-9-]*[a-z0-9]']],
        ['name' => 'applicationVersions#create',  'url' => '/api/applications/{slug}/versions',                'verb' => 'POST',   'requirements' => ['slug' => '[a-z0-9][a-z0-9-]*[a-z0-9]']],
        ['name' => 'applicationVersions#show',    'url' => '/api/applications/{slug}/versions/{versionSlug}',  'verb' => 'GET',    'requirements' => ['slug' => '[a-z0-9][a-z0-9-]*[a-z0-9]', 'versionSlug' => '[a-z0-9][a-z0-9-]*[a-z0-9]']],
        ['name' => 'applicationVersions#update',  'url' => '/api/applications/{slug}/versions/{versionSlug}',  'verb' => 'PUT',    'requirements' => ['slug' => '[a-z0-9][a-z0-9-]*[a-z0-9]', 'versionSlug' => '[a-z0-9][a-z0-9-]*[a-z0-9]']],
        ['name' => 'applicationVersions#destroy', 'url' => '/api/applications/{slug}/versions/{versionSlug}',  'verb' => 'DELETE', 'requirements' => ['slug' => '[a-z0-9][a-z0-9-]*[a-z0-9]', 'versionSlug' => '[a-z0-9][a-z0-9-]*[a-z0-9]']],

        // Manual promotion endpoint (openbuilt-version-promotion REQ-OBVP-001).
        // Spec mandates UUID path params (`{appUuid}/versions/{versionUuid}/promote`)
        // to distinguish this surface from the slug-based CRUD above. The trailing
        // `/promote` literal is sufficient to disambiguate from the `{versionSlug}`
        // routes — Symfony tries the more-specific URL first and the requirements
        // enforce the UUID shape so a kebab-case version slug cannot accidentally
        // match. #[NoAdminRequired] is set on the controller method; RBAC happens
        // inside (owners + editors only — admins NOT auto-granted, REQ-OBVP-007).
        ['name' => 'versionPromotion#promote', 'url' => '/api/applications/{appUuid}/versions/{versionUuid}/promote', 'verb' => 'POST', 'requirements' => ['appUuid' => '[a-f0-9-]{8,}', 'versionUuid' => '[a-f0-9-]{8,}']],

        // Icon-serving endpoints (openbuilt-nextcloud-nav REQ-OBICON-002 / REQ-OBICON-003).
        // Both are #[NoAdminRequired] on the controller. The dark route uses a longer
        // URL pattern ("{slug}-dark.svg") that is unambiguous — it cannot shadow the
        // light route because slugs are kebab-case [a-z0-9-] and never end in "-dark".
        // Placed before the SPA catch-all; after exports so slug patterns don't collide.
        ['name' => 'icon#iconLight', 'url' => '/icons/{slug}.svg',      'verb' => 'GET', 'requirements' => ['slug' => '[a-z0-9][a-z0-9-]*[a-z0-9]']],
        ['name' => 'icon#iconDark',  'url' => '/icons/{slug}-dark.svg', 'verb' => 'GET', 'requirements' => ['slug' => '[a-z0-9][a-z0-9-]*[a-z0-9]']],

        // Export pipeline (Phase-2 graduation).
        ['name' => 'exports#submit',   'url' => '/api/applications/{slug}/exports', 'verb' => 'POST', 'requirements' => ['slug' => '[a-z0-9][a-z0-9-]*[a-z0-9]']],
        ['name' => 'exports#download', 'url' => '/api/exports/{uuid}/download',     'verb' => 'GET'],

        // SPA catch-all — same controller as the index route; must use a distinct route name
        // (duplicate names replace the earlier route in Symfony, which breaks GET /).
        ['name' => 'dashboard#catchAll', 'url' => '/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+'], 'defaults' => ['path' => '']],
    ],
];
