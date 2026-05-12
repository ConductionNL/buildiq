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

        // Manifest endpoint — returns the stored manifest JSON blob for a given virtual-app slug.
        // Per ADR-016 routes.php is the only registration path; #[NoAdminRequired] is set on the
        // controller method so auth-required-but-non-admin users can hit it (per design.md Decision 6).
        // Slug matches the kebab-case pattern declared in openbuilt_register.json on the Application
        // and BuiltAppRoute schemas (^[a-z0-9][a-z0-9-]*[a-z0-9]$, min 2 max 48 chars).
        ['name' => 'applications#getManifest', 'url' => '/api/applications/{slug}/manifest', 'verb' => 'GET', 'requirements' => ['slug' => '[a-z0-9][a-z0-9-]*[a-z0-9]']],

        // SPA catch-all — same controller as the index route; must use a distinct route name
        // (duplicate names replace the earlier route in Symfony, which breaks GET /).
        ['name' => 'dashboard#catchAll', 'url' => '/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+'], 'defaults' => ['path' => '']],
    ],
];
