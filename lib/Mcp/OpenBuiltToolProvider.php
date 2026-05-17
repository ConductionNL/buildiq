<?php

/**
 * OpenBuilt MCP Tool Provider
 *
 * Per-app implementation of OCA\OpenRegister\Mcp\IMcpToolProvider. Exposes the
 * full OpenBuilt authoring surface to an LLM via MCP: list/read apps, create
 * new apps, promote versions, and mutate a draft version's manifest (pages,
 * widgets, menu items) and per-version schemas.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenBuilt\Mcp;

use OCA\OpenRegister\Mcp\IMcpToolProvider;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * OpenBuilt MCP Tool Provider.
 *
 * Read tools:
 *   - openbuilt.listApps
 *   - openbuilt.getAppManifest
 *
 * Write tools (lifecycle):
 *   - openbuilt.createApp
 *   - openbuilt.promoteVersion
 *
 * Write tools (authoring against the draft version's manifest):
 *   - openbuilt.upsertSchema      (per-version OR schema)
 *   - openbuilt.upsertPage        (manifest.pages slot)
 *   - openbuilt.addWidget         (page.config.widgets append)
 *   - openbuilt.upsertMenuItem    (manifest.menu slot)
 *
 * Authoring tools default to the `development` version so a misfired tool
 * call cannot mutate production. To promote the change use promoteVersion.
 */
class OpenBuiltToolProvider implements IMcpToolProvider
{

    private const ITEMS_CAP = 20;

    private const REGISTER_SLUG = 'openbuilt';

    private const APP_STATUSES = ['any', 'draft', 'published', 'archived'];

    private const CREATE_PRESETS = ['single', 'dev-prod', 'dev-staging-prod'];

    private const PROMOTE_STRATEGIES = ['empty-start', 'start-with-source-data', 'migrate-existing-data'];

    private const PAGE_TYPES = ['dashboard', 'index', 'detail', 'form'];

    /**
     * Tool catalogue.
     *
     * @var array<int, array<string, mixed>>
     */
    private const TOOL_DESCRIPTORS = [
        [
            'id'          => 'openbuilt.listApps',
            'name'        => 'List virtual apps',
            'description' => 'List the virtual apps built with OpenBuilt in your organisation.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'limit'        => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20],
                    'statusFilter' => ['type' => 'string', 'enum' => ['any', 'draft', 'published', 'archived'], 'default' => 'any'],
                ],
                'required'   => [],
            ],
        ],
        [
            'id'          => 'openbuilt.getAppManifest',
            'name'        => 'Get virtual app manifest',
            'description' => 'Fetch the runtime manifest blob for one published virtual app by slug.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'slug' => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]*[a-z0-9]$', 'minLength' => 2, 'maxLength' => 48],
                ],
                'required'   => ['slug'],
            ],
        ],
        [
            'id'          => 'openbuilt.createApp',
            'name'        => 'Create a new virtual app',
            'description' => 'Create a new OpenBuilt virtual app with an initial draft ApplicationVersion. Preset chooses the version chain: "single", "dev-prod" or "dev-staging-prod".',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'slug'        => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]*[a-z0-9]$', 'minLength' => 2, 'maxLength' => 48],
                    'name'        => ['type' => 'string', 'minLength' => 2, 'maxLength' => 80],
                    'description' => ['type' => 'string', 'maxLength' => 500],
                    'preset'      => ['type' => 'string', 'enum' => ['single', 'dev-prod', 'dev-staging-prod'], 'default' => 'dev-prod'],
                ],
                'required'   => ['slug', 'name'],
            ],
        ],
        [
            'id'          => 'openbuilt.promoteVersion',
            'name'        => 'Promote a virtual app version',
            'description' => 'Promote a virtual app from one version (e.g. development) to the next (e.g. production). Strategy "empty-start" (default, safest) leaves the target empty.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'appSlug'           => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]*[a-z0-9]$', 'minLength' => 2, 'maxLength' => 48],
                    'sourceVersionSlug' => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]*[a-z0-9]$', 'minLength' => 2, 'maxLength' => 48],
                    'strategy'          => ['type' => 'string', 'enum' => ['empty-start', 'start-with-source-data', 'migrate-existing-data'], 'default' => 'empty-start'],
                ],
                'required'   => ['appSlug', 'sourceVersionSlug'],
            ],
        ],
        [
            'id'          => 'openbuilt.upsertSchema',
            'name'        => 'Create or update a schema in a virtual app',
            'description' => 'Create or update a JSON Schema in the given app version\'s per-version OR register. Slug is automatically namespaced with appSlug+versionSlug. Properties is a JSON Schema property map; required is an array of property names. Defaults versionSlug to "development".',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'appSlug'     => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]*[a-z0-9]$', 'minLength' => 2, 'maxLength' => 48],
                    'versionSlug' => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]*[a-z0-9]$', 'default' => 'development'],
                    'slug'        => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]*[a-z0-9]$', 'minLength' => 2, 'maxLength' => 48],
                    'title'       => ['type' => 'string', 'minLength' => 2, 'maxLength' => 80],
                    'description' => ['type' => 'string', 'maxLength' => 500],
                    'properties'  => ['type' => 'object'],
                    'required'    => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
                'required'   => ['appSlug', 'slug', 'title', 'properties'],
            ],
        ],
        [
            'id'          => 'openbuilt.upsertPage',
            'name'        => 'Create or update a page in a virtual app',
            'description' => 'Create or update a page in the draft manifest. pageId is the unique key; if it exists it is replaced. Type is one of dashboard, index, detail, form. config is page-type-specific (e.g. {register, schema, columns} for index pages, {widgets, layout} for dashboards). Defaults versionSlug to "development".',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'appSlug'     => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]*[a-z0-9]$', 'minLength' => 2, 'maxLength' => 48],
                    'versionSlug' => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]*[a-z0-9]$', 'default' => 'development'],
                    'pageId'      => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
                    'title'       => ['type' => 'string', 'minLength' => 1, 'maxLength' => 80],
                    'type'        => ['type' => 'string', 'enum' => ['dashboard', 'index', 'detail', 'form']],
                    'route'       => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
                    'config'      => ['type' => 'object'],
                ],
                'required'   => ['appSlug', 'pageId', 'title', 'type', 'route'],
            ],
        ],
        [
            'id'          => 'openbuilt.addWidget',
            'name'        => 'Add a widget to a page',
            'description' => 'Append a widget to a page\'s config.widgets array in the draft manifest. widgetType is e.g. "stat-counter", "chart", "list". widgetConfig is widget-type-specific. Defaults versionSlug to "development".',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'appSlug'      => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]*[a-z0-9]$', 'minLength' => 2, 'maxLength' => 48],
                    'versionSlug'  => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]*[a-z0-9]$', 'default' => 'development'],
                    'pageId'       => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
                    'widgetType'   => ['type' => 'string', 'minLength' => 1, 'maxLength' => 48],
                    'widgetConfig' => ['type' => 'object'],
                ],
                'required'   => ['appSlug', 'pageId', 'widgetType'],
            ],
        ],
        [
            'id'          => 'openbuilt.upsertMenuItem',
            'name'        => 'Create or update a menu item',
            'description' => 'Create or update a top-level menu item in the draft manifest. id is the unique key; if it exists it is replaced. route should match a page id. order controls sort. icon is an MDI/standard icon name. Defaults versionSlug to "development".',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'appSlug'     => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]*[a-z0-9]$', 'minLength' => 2, 'maxLength' => 48],
                    'versionSlug' => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]*[a-z0-9]$', 'default' => 'development'],
                    'id'          => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
                    'label'       => ['type' => 'string', 'minLength' => 1, 'maxLength' => 80],
                    'icon'        => ['type' => 'string', 'maxLength' => 80],
                    'route'       => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
                    'order'       => ['type' => 'integer', 'minimum' => 0, 'maximum' => 999],
                ],
                'required'   => ['appSlug', 'id', 'label', 'route'],
            ],
        ],
    ];

    public function __construct(
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    public function getAppId(): string
    {
        return 'openbuilt';

    }//end getAppId()

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getTools(): array
    {
        return self::TOOL_DESCRIPTORS;

    }//end getTools()

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    public function invokeTool(string $toolId, array $arguments): array
    {
        return match ($toolId) {
            'openbuilt.listApps'         => $this->handleListApps(args: $arguments),
            'openbuilt.getAppManifest'   => $this->handleGetAppManifest(args: $arguments),
            'openbuilt.createApp'        => $this->handleCreateApp(args: $arguments),
            'openbuilt.promoteVersion'   => $this->handlePromoteVersion(args: $arguments),
            'openbuilt.upsertSchema'     => $this->handleUpsertSchema(args: $arguments),
            'openbuilt.upsertPage'       => $this->handleUpsertPage(args: $arguments),
            'openbuilt.addWidget'        => $this->handleAddWidget(args: $arguments),
            'openbuilt.upsertMenuItem'   => $this->handleUpsertMenuItem(args: $arguments),
            default                      => $this->errorResult(
                error: 'unknown_tool',
                message: "Unknown tool id '{$toolId}'. Available tools: "
                    .implode(separator: ', ', array: array_column(array: self::TOOL_DESCRIPTORS, column_key: 'id')).'.',
            ),
        };

    }//end invokeTool()

    // =========================================================================
    // Read handlers
    // =========================================================================

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function handleListApps(array $args): array
    {
        $validation = $this->validateListAppsArgs(args: $args);
        if (isset($validation['error']) === true) {
            return $this->errorResult(error: 'invalid_arguments', message: $validation['error']);
        }

        if ($this->requireAuthenticatedUser() === null) {
            return $this->errorResult(error: 'forbidden', message: 'You must be signed in to list virtual apps.');
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            $filters = [];
            if ($validation['statusFilter'] !== 'any') {
                $filters['status'] = $validation['statusFilter'];
            }

            $rawApps = $objectService->searchObjectsBySlug(self::REGISTER_SLUG, 'application', $filters);
            if (is_array(value: $rawApps) === false) {
                $rawApps = [];
            }

            $rawApps = array_slice(array: $rawApps, offset: 0, length: min($validation['limit'], self::ITEMS_CAP));

            $apps    = [];
            $sources = [];
            foreach ($rawApps as $raw) {
                $app       = $this->mapApplication(raw: $raw);
                $apps[]    = $app;
                $sources[] = $this->sourceDescriptor(uuid: $app['uuid'], slug: $app['slug'], label: $app['name']);
            }

            return ['success' => true, 'apps' => $apps, 'sources' => $sources];
        } catch (\Throwable $e) {
            $this->logger->error('OpenBuilt MCP: listApps failed', ['exception' => $e->getMessage()]);
            return $this->errorResult(error: 'internal_error', message: 'Failed to retrieve virtual apps.');
        }//end try

    }//end handleListApps()

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function handleGetAppManifest(array $args): array
    {
        $slug = $args['slug'] ?? null;
        if ($slug === null || $slug === '') {
            return $this->errorResult(error: 'invalid_arguments', message: 'Required argument slug is missing.');
        }

        if ($this->isValidSlug(candidate: (string) $slug) === false) {
            return $this->errorResult(error: 'invalid_arguments', message: "Invalid slug '{$slug}'.");
        }

        if ($this->requireAuthenticatedUser() === null) {
            return $this->errorResult(error: 'forbidden', message: 'You must be signed in to read a virtual app manifest.');
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $resolved      = $this->resolveApplicationBySlug(objectService: $objectService, slug: (string) $slug);
            if (isset($resolved['error']) === true) {
                return $this->errorResult(error: $resolved['error'], message: $resolved['message']);
            }

            $application = $resolved['application'];
            $manifest    = ($application['manifest'] ?? null);
            if (is_array(value: $manifest) === false) {
                return $this->errorResult(error: 'no_manifest', message: 'Application has no manifest.');
            }

            $name = (string) ($application['name'] ?? $slug);
            return [
                'success'  => true,
                'slug'     => (string) $slug,
                'name'     => $name,
                'manifest' => $manifest,
                'sources'  => [$this->sourceDescriptor(uuid: $this->extractUuid(item: $application), slug: (string) $slug, label: $name)],
            ];
        } catch (\Throwable $e) {
            $this->logger->error('OpenBuilt MCP: getAppManifest failed', ['slug' => $slug, 'exception' => $e->getMessage()]);
            return $this->errorResult(error: 'internal_error', message: 'Failed to resolve manifest.');
        }//end try

    }//end handleGetAppManifest()

    // =========================================================================
    // Lifecycle handlers
    // =========================================================================

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function handleCreateApp(array $args): array
    {
        $slug        = (string) ($args['slug'] ?? '');
        $name        = (string) ($args['name'] ?? '');
        $description = (string) ($args['description'] ?? '');
        $preset      = (string) ($args['preset'] ?? 'dev-prod');

        if ($slug === '' || $this->isValidSlug(candidate: $slug) === false) {
            return $this->errorResult(error: 'invalid_arguments', message: "Invalid slug '{$slug}'.");
        }

        if ($name === '' || strlen($name) < 2 || strlen($name) > 80) {
            return $this->errorResult(error: 'invalid_arguments', message: 'Name must be between 2 and 80 characters.');
        }

        if (in_array(needle: $preset, haystack: self::CREATE_PRESETS, strict: true) === false) {
            return $this->errorResult(error: 'invalid_arguments', message: "Invalid preset '{$preset}'.");
        }

        if ($this->requireAuthenticatedUser() === null) {
            return $this->errorResult(error: 'forbidden', message: 'You must be signed in to create a virtual app.');
        }

        try {
            $creationService = $this->container->get('OCA\OpenBuilt\Service\ApplicationCreationService');
            $appUuid         = $creationService->createApplication(
                    [
                        'slug'        => $slug,
                        'name'        => $name,
                        'description' => $description,
                        'preset'      => $preset,
                    ]
                    );

            return [
                'success' => true,
                'created' => true,
                'app'     => ['uuid' => $appUuid, 'slug' => $slug, 'name' => $name, 'preset' => $preset],
                'sources' => [$this->sourceDescriptor(uuid: $appUuid, slug: $slug, label: $name)],
            ];
        } catch (\Throwable $e) {
            $this->logger->error('OpenBuilt MCP: createApp failed', ['slug' => $slug, 'exception' => $e->getMessage()]);
            return $this->errorResult(error: 'create_failed', message: 'Failed to create virtual app: '.$e->getMessage());
        }//end try

    }//end handleCreateApp()

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function handlePromoteVersion(array $args): array
    {
        $appSlug           = (string) ($args['appSlug'] ?? '');
        $sourceVersionSlug = (string) ($args['sourceVersionSlug'] ?? '');
        $strategy          = (string) ($args['strategy'] ?? 'empty-start');

        if ($appSlug === '' || $this->isValidSlug(candidate: $appSlug) === false) {
            return $this->errorResult(error: 'invalid_arguments', message: "Invalid appSlug '{$appSlug}'.");
        }

        if ($sourceVersionSlug === '' || $this->isValidSlug(candidate: $sourceVersionSlug) === false) {
            return $this->errorResult(error: 'invalid_arguments', message: "Invalid sourceVersionSlug '{$sourceVersionSlug}'.");
        }

        if (in_array(needle: $strategy, haystack: self::PROMOTE_STRATEGIES, strict: true) === false) {
            return $this->errorResult(error: 'invalid_arguments', message: "Invalid strategy '{$strategy}'.");
        }

        if ($this->requireAuthenticatedUser() === null) {
            return $this->errorResult(error: 'forbidden', message: 'You must be signed in to promote a virtual app version.');
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            $loaded = $this->loadVersion($objectService, $appSlug, $sourceVersionSlug);
            if (isset($loaded['error']) === true) {
                return $this->errorResult(error: $loaded['error'], message: $loaded['message']);
            }

            $source = $loaded['version'];
            if (($source['promotesTo'] ?? null) === null || $source['promotesTo'] === '') {
                return $this->errorResult(error: 'no_promote_target', message: "Version '{$sourceVersionSlug}' has no downstream target.");
            }

            $promotionService = $this->container->get('OCA\OpenBuilt\Service\VersionPromotionService');
            $updatedTarget    = $promotionService->promote($source, $strategy);
            $targetUuid       = $this->extractUuid(item: $updatedTarget);

            return [
                'success'  => true,
                'promoted' => true,
                'strategy' => $strategy,
                'from'     => ['uuid' => $this->extractUuid(item: $source), 'slug' => $sourceVersionSlug],
                'to'       => [
                    'uuid'   => $targetUuid,
                    'slug'   => (string) ($updatedTarget['slug'] ?? ''),
                    'status' => (string) ($updatedTarget['status'] ?? ''),
                ],
                'sources'  => [$this->sourceDescriptor(uuid: $loaded['appUuid'], slug: $appSlug, label: $loaded['appName'])],
            ];
        } catch (\Throwable $e) {
            $this->logger->error('OpenBuilt MCP: promoteVersion failed', ['appSlug' => $appSlug, 'source' => $sourceVersionSlug, 'exception' => $e->getMessage()]);
            return $this->errorResult(error: 'promote_failed', message: 'Failed to promote version: '.$e->getMessage());
        }//end try

    }//end handlePromoteVersion()

    // =========================================================================
    // Authoring handlers
    // =========================================================================

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function handleUpsertSchema(array $args): array
    {
        $appSlug     = (string) ($args['appSlug'] ?? '');
        $versionSlug = (string) ($args['versionSlug'] ?? 'development');
        $rawSlug     = (string) ($args['slug'] ?? '');
        $title       = (string) ($args['title'] ?? '');
        $description = (string) ($args['description'] ?? '');
        $properties  = $args['properties'] ?? [];
        $required    = $args['required'] ?? [];

        if ($appSlug === '' || $this->isValidSlug(candidate: $appSlug) === false) {
            return $this->errorResult(error: 'invalid_arguments', message: "Invalid appSlug '{$appSlug}'.");
        }

        if ($this->isValidSlug(candidate: $versionSlug) === false) {
            return $this->errorResult(error: 'invalid_arguments', message: "Invalid versionSlug '{$versionSlug}'.");
        }

        if ($rawSlug === '' || $this->isValidSlug(candidate: $rawSlug) === false) {
            return $this->errorResult(error: 'invalid_arguments', message: "Invalid schema slug '{$rawSlug}'.");
        }

        if ($title === '') {
            return $this->errorResult(error: 'invalid_arguments', message: 'title is required.');
        }

        if (is_array($properties) === false || $properties === []) {
            return $this->errorResult(error: 'invalid_arguments', message: 'properties must be a non-empty object of JSON-Schema property definitions.');
        }

        if (is_array($required) === false) {
            $required = [];
        }

        if ($this->requireAuthenticatedUser() === null) {
            return $this->errorResult(error: 'forbidden', message: 'You must be signed in to author schemas.');
        }

        try {
            $schemaMapper   = $this->container->get('OCA\OpenRegister\Db\SchemaMapper');
            $registerMapper = $this->container->get('OCA\OpenRegister\Db\RegisterMapper');

            $registerSlug   = 'openbuilt-'.$appSlug.'-'.$versionSlug;
            $namespacedSlug = $appSlug.'-'.$versionSlug.'-'.$rawSlug;

            $blob = [
                'slug'        => $namespacedSlug,
                'title'       => $title,
                'description' => $description,
                'type'        => 'object',
                'required'    => array_values(array_filter((array) $required, 'is_string')),
                'properties'  => (array) $properties,
            ];

            // findBySlug returns Schema[] (may be empty). Take the first hit.
            $existing = null;
            try {
                $matches = $schemaMapper->findBySlug($namespacedSlug);
                if (is_array($matches) === true && $matches !== []) {
                    $existing = $matches[0];
                }
            } catch (\Throwable $_e) {
                $existing = null;
            }

            if ($existing !== null) {
                $schema = $schemaMapper->updateFromArray($existing->getId(), $blob);
                $action = 'updated';
            } else {
                $schema = $schemaMapper->createFromArray($blob);
                $action = 'created';

                // Attach the new schema to the per-version register.
                try {
                    $register = $registerMapper->find($registerSlug, _multitenancy: false);
                    $current  = $register->getSchemas();
                    if (is_array($current) === false) {
                        $current = [];
                    }

                    $register->setSchemas(array_values(array_unique(array_merge($current, [$schema->getId()]))));
                    $registerMapper->update($register);
                } catch (\Throwable $e) {
                    $this->logger->warning('OpenBuilt MCP: upsertSchema attach-to-register failed', ['register' => $registerSlug, 'exception' => $e->getMessage()]);
                }
            }//end if

            return [
                'success' => true,
                'action'  => $action,
                'schema'  => [
                    'id'        => $schema->getId(),
                    'slug'      => $namespacedSlug,
                    'shortSlug' => $rawSlug,
                    'title'     => $title,
                    'register'  => $registerSlug,
                ],
            ];
        } catch (\Throwable $e) {
            $this->logger->error('OpenBuilt MCP: upsertSchema failed', ['appSlug' => $appSlug, 'slug' => $rawSlug, 'exception' => $e->getMessage()]);
            return $this->errorResult(error: 'upsert_failed', message: 'Failed to upsert schema: '.$e->getMessage());
        }//end try

    }//end handleUpsertSchema()

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function handleUpsertPage(array $args): array
    {
        $appSlug     = (string) ($args['appSlug'] ?? '');
        $versionSlug = (string) ($args['versionSlug'] ?? 'development');
        $pageId      = (string) ($args['pageId'] ?? '');
        $title       = (string) ($args['title'] ?? '');
        $type        = (string) ($args['type'] ?? '');
        $route       = (string) ($args['route'] ?? '');
        $config      = $args['config'] ?? [];

        if ($appSlug === '' || $this->isValidSlug(candidate: $appSlug) === false) {
            return $this->errorResult(error: 'invalid_arguments', message: "Invalid appSlug '{$appSlug}'.");
        }

        if ($pageId === '') {
            return $this->errorResult(error: 'invalid_arguments', message: 'pageId is required.');
        }

        if ($title === '') {
            return $this->errorResult(error: 'invalid_arguments', message: 'title is required.');
        }

        if (in_array(needle: $type, haystack: self::PAGE_TYPES, strict: true) === false) {
            return $this->errorResult(error: 'invalid_arguments', message: "Invalid page type '{$type}'.");
        }

        if ($route === '') {
            return $this->errorResult(error: 'invalid_arguments', message: 'route is required.');
        }

        if (is_array($config) === false) {
            $config = [];
        }

        if ($this->requireAuthenticatedUser() === null) {
            return $this->errorResult(error: 'forbidden', message: 'You must be signed in to author pages.');
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            $loaded = $this->loadVersion($objectService, $appSlug, $versionSlug);
            if (isset($loaded['error']) === true) {
                return $this->errorResult(error: $loaded['error'], message: $loaded['message']);
            }

            $version  = $loaded['version'];
            $manifest = (array) ($version['manifest'] ?? []);
            $pages    = (array) ($manifest['pages'] ?? []);

            $newPage = [
                'id'     => $pageId,
                'route'  => $route,
                'type'   => $type,
                'title'  => $title,
                'config' => $config,
            ];

            $replaced = false;
            foreach ($pages as $i => $existing) {
                if (is_array($existing) === true && (string) ($existing['id'] ?? '') === $pageId) {
                    $pages[$i] = $newPage;
                    $replaced  = true;
                    break;
                }
            }

            if ($replaced === false) {
                $pages[] = $newPage;
            }

            $manifest['pages'] = array_values($pages);
            $saved = $this->saveVersionManifest($objectService, $version, $manifest);

            return [
                'success'   => true,
                'action'    => $replaced ? 'updated' : 'created',
                'page'      => $newPage,
                'pageCount' => count($pages),
                'version'   => [
                    'uuid' => $this->extractUuid(item: $saved),
                    'slug' => (string) ($saved['slug'] ?? $versionSlug),
                ],
            ];
        } catch (\Throwable $e) {
            $this->logger->error('OpenBuilt MCP: upsertPage failed', ['appSlug' => $appSlug, 'pageId' => $pageId, 'exception' => $e->getMessage()]);
            return $this->errorResult(error: 'upsert_failed', message: 'Failed to upsert page: '.$e->getMessage());
        }//end try

    }//end handleUpsertPage()

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function handleAddWidget(array $args): array
    {
        $appSlug      = (string) ($args['appSlug'] ?? '');
        $versionSlug  = (string) ($args['versionSlug'] ?? 'development');
        $pageId       = (string) ($args['pageId'] ?? '');
        $widgetType   = (string) ($args['widgetType'] ?? '');
        $widgetConfig = $args['widgetConfig'] ?? [];

        if ($appSlug === '' || $this->isValidSlug(candidate: $appSlug) === false) {
            return $this->errorResult(error: 'invalid_arguments', message: "Invalid appSlug '{$appSlug}'.");
        }

        if ($pageId === '') {
            return $this->errorResult(error: 'invalid_arguments', message: 'pageId is required.');
        }

        if ($widgetType === '') {
            return $this->errorResult(error: 'invalid_arguments', message: 'widgetType is required.');
        }

        if (is_array($widgetConfig) === false) {
            $widgetConfig = [];
        }

        if ($this->requireAuthenticatedUser() === null) {
            return $this->errorResult(error: 'forbidden', message: 'You must be signed in to add widgets.');
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            $loaded = $this->loadVersion($objectService, $appSlug, $versionSlug);
            if (isset($loaded['error']) === true) {
                return $this->errorResult(error: $loaded['error'], message: $loaded['message']);
            }

            $version  = $loaded['version'];
            $manifest = (array) ($version['manifest'] ?? []);
            $pages    = (array) ($manifest['pages'] ?? []);

            $foundIdx = null;
            foreach ($pages as $i => $existing) {
                if (is_array($existing) === true && (string) ($existing['id'] ?? '') === $pageId) {
                    $foundIdx = $i;
                    break;
                }
            }

            if ($foundIdx === null) {
                return $this->errorResult(error: 'not_found', message: "Page '{$pageId}' not found in manifest.");
            }

            $page       = $pages[$foundIdx];
            $pageConfig = (array) ($page['config'] ?? []);
            $widgets    = (array) ($pageConfig['widgets'] ?? []);
            $widget     = ['type' => $widgetType, 'config' => $widgetConfig];
            $widgets[]  = $widget;
            $pageConfig['widgets'] = $widgets;
            $page['config']        = $pageConfig;
            $pages[$foundIdx]      = $page;
            $manifest['pages']     = array_values($pages);

            $saved = $this->saveVersionManifest($objectService, $version, $manifest);

            return [
                'success'     => true,
                'added'       => true,
                'widget'      => $widget,
                'pageId'      => $pageId,
                'widgetCount' => count($widgets),
                'version'     => [
                    'uuid' => $this->extractUuid(item: $saved),
                    'slug' => (string) ($saved['slug'] ?? $versionSlug),
                ],
            ];
        } catch (\Throwable $e) {
            $this->logger->error('OpenBuilt MCP: addWidget failed', ['appSlug' => $appSlug, 'pageId' => $pageId, 'exception' => $e->getMessage()]);
            return $this->errorResult(error: 'add_failed', message: 'Failed to add widget: '.$e->getMessage());
        }//end try

    }//end handleAddWidget()

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function handleUpsertMenuItem(array $args): array
    {
        $appSlug     = (string) ($args['appSlug'] ?? '');
        $versionSlug = (string) ($args['versionSlug'] ?? 'development');
        $id          = (string) ($args['id'] ?? '');
        $label       = (string) ($args['label'] ?? '');
        $icon        = (string) ($args['icon'] ?? '');
        $route       = (string) ($args['route'] ?? '');
        $order       = isset($args['order']) ? (int) $args['order'] : 100;

        if ($appSlug === '' || $this->isValidSlug(candidate: $appSlug) === false) {
            return $this->errorResult(error: 'invalid_arguments', message: "Invalid appSlug '{$appSlug}'.");
        }

        if ($id === '') {
            return $this->errorResult(error: 'invalid_arguments', message: 'id is required.');
        }

        if ($label === '') {
            return $this->errorResult(error: 'invalid_arguments', message: 'label is required.');
        }

        if ($route === '') {
            return $this->errorResult(error: 'invalid_arguments', message: 'route is required.');
        }

        if ($this->requireAuthenticatedUser() === null) {
            return $this->errorResult(error: 'forbidden', message: 'You must be signed in to author menu items.');
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            $loaded = $this->loadVersion($objectService, $appSlug, $versionSlug);
            if (isset($loaded['error']) === true) {
                return $this->errorResult(error: $loaded['error'], message: $loaded['message']);
            }

            $version  = $loaded['version'];
            $manifest = (array) ($version['manifest'] ?? []);
            $menu     = (array) ($manifest['menu'] ?? []);

            $newItem = ['id' => $id, 'label' => $label, 'icon' => $icon, 'route' => $route, 'order' => $order];

            $replaced = false;
            foreach ($menu as $i => $existing) {
                if (is_array($existing) === true && (string) ($existing['id'] ?? '') === $id) {
                    $menu[$i] = $newItem;
                    $replaced = true;
                    break;
                }
            }

            if ($replaced === false) {
                $menu[] = $newItem;
            }

            $manifest['menu'] = array_values($menu);
            $saved            = $this->saveVersionManifest($objectService, $version, $manifest);

            return [
                'success'   => true,
                'action'    => $replaced ? 'updated' : 'created',
                'menuItem'  => $newItem,
                'menuCount' => count($menu),
                'version'   => [
                    'uuid' => $this->extractUuid(item: $saved),
                    'slug' => (string) ($saved['slug'] ?? $versionSlug),
                ],
            ];
        } catch (\Throwable $e) {
            $this->logger->error('OpenBuilt MCP: upsertMenuItem failed', ['appSlug' => $appSlug, 'id' => $id, 'exception' => $e->getMessage()]);
            return $this->errorResult(error: 'upsert_failed', message: 'Failed to upsert menu item: '.$e->getMessage());
        }//end try

    }//end handleUpsertMenuItem()

    // =========================================================================
    // Shared helpers
    // =========================================================================

    /**
     * Resolve <appSlug, versionSlug> to {version, appUuid, appName}, or {error,message}.
     *
     * @return array{version?: array, appUuid?: string, appName?: string, error?: string, message?: string}
     */
    private function loadVersion(object $objectService, string $appSlug, string $versionSlug): array
    {
        $apps = $objectService->searchObjectsBySlug(self::REGISTER_SLUG, 'application', ['slug' => $appSlug]);
        if (is_array($apps) === false || $apps === []) {
            return ['error' => 'not_found', 'message' => "No virtual app found for slug '{$appSlug}'."];
        }

        $app     = $this->toArray(item: $apps[0]);
        $appUuid = $this->extractUuid(item: $app);

        $versions = $objectService->searchObjectsBySlug(
            self::REGISTER_SLUG,
            'applicationVersion',
            ['application' => $appUuid, 'slug' => $versionSlug]
        );
        if (is_array($versions) === false || $versions === []) {
            return ['error' => 'not_found', 'message' => "No version '{$versionSlug}' found for app '{$appSlug}'."];
        }

        return [
            'version' => $this->toArray(item: $versions[0]),
            'appUuid' => $appUuid,
            'appName' => (string) ($app['name'] ?? $appSlug),
        ];

    }//end loadVersion()

    /**
     * Save an ApplicationVersion with a new manifest. Retains the full payload so
     * OR's `required[]` validator does not reject a partial save.
     *
     * @param array<string, mixed> $version
     * @param array<string, mixed> $manifest
     *
     * @return array<string, mixed>
     */
    private function saveVersionManifest(object $objectService, array $version, array $manifest): array
    {
        $versionUuid = $this->extractUuid(item: $version);
        $payload     = $version;
        $payload['manifest'] = $manifest;

        // Drop OR-internal `@self` / metadata keys that some readers tack on so
        // saveObject treats the input as a clean property bag.
        unset($payload['@self'], $payload['id'], $payload['uuid']);

        $saved = $objectService->saveObject(
            object: $payload,
            register: self::REGISTER_SLUG,
            schema: 'applicationVersion',
            uuid: $versionUuid,
        );

        return $this->toArray(item: $saved);

    }//end saveVersionManifest()

    /**
     * @param array<string, mixed> $args
     *
     * @return array{limit?: int, statusFilter?: string, error?: string}
     */
    private function validateListAppsArgs(array $args): array
    {
        $limit = self::ITEMS_CAP;
        if (isset($args['limit']) === true) {
            $limit = (int) $args['limit'];
        }

        if ($limit < 1 || $limit > 50) {
            return ['error' => "Invalid limit {$limit}."];
        }

        $statusFilter = (string) ($args['statusFilter'] ?? 'any');
        if (in_array(needle: $statusFilter, haystack: self::APP_STATUSES, strict: true) === false) {
            return ['error' => "Invalid statusFilter '{$statusFilter}'."];
        }

        return ['limit' => $limit, 'statusFilter' => $statusFilter];

    }//end validateListAppsArgs()

    /**
     * @return array{application?: array<string, mixed>, error?: string, message?: string}
     */
    private function resolveApplicationBySlug(object $objectService, string $slug): array
    {
        $routeResults = $objectService->searchObjectsBySlug(self::REGISTER_SLUG, 'built-app-route', ['slug' => $slug]);
        if (is_array($routeResults) === false || $routeResults === []) {
            return ['error' => 'not_found', 'message' => "No published virtual app found for slug '{$slug}'."];
        }

        $route           = $this->toArray(item: $routeResults[0]);
        $applicationUuid = ($route['applicationUuid'] ?? null);
        if ($applicationUuid === null || $applicationUuid === '') {
            return ['error' => 'inconsistent_state', 'message' => 'Route exists but has no applicationUuid.'];
        }

        $application = $objectService->find(id: (string) $applicationUuid, register: self::REGISTER_SLUG, schema: 'application');
        if ($application === null) {
            return ['error' => 'inconsistent_state', 'message' => 'Route points to an Application that does not exist.'];
        }

        return ['application' => $this->toArray(item: $application)];

    }//end resolveApplicationBySlug()

    /**
     * @param mixed $raw
     *
     * @return array{uuid: string, slug: string, name: string, description: string, status: string, version: string}
     */
    private function mapApplication(mixed $raw): array
    {
        $app  = $this->toArray(item: $raw);
        $slug = (string) ($app['slug'] ?? '');
        return [
            'uuid'        => $this->extractUuid(item: $app),
            'slug'        => $slug,
            'name'        => (string) ($app['name'] ?? $slug),
            'description' => (string) ($app['description'] ?? ''),
            'status'      => (string) ($app['status'] ?? 'draft'),
            'version'     => (string) ($app['version'] ?? ''),
        ];

    }//end mapApplication()

    /**
     * @return array{type: string, uuid: string, url: string, label: string}
     */
    private function sourceDescriptor(string $uuid, string $slug, string $label): array
    {
        return ['type' => 'openbuilt.application', 'uuid' => $uuid, 'url' => $this->buildDeepLink(slug: $slug), 'label' => $label];

    }//end sourceDescriptor()

    /**
     * @return array{isError: true, error: string, message: string}
     */
    private function errorResult(string $error, string $message): array
    {
        return ['isError' => true, 'error' => $error, 'message' => $message];

    }//end errorResult()

    private function requireAuthenticatedUser(): ?string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return null;
        }

        $uid = $user->getUID();
        return $uid === '' ? null : $uid;

    }//end requireAuthenticatedUser()

    public function isAdmin(string $userId): bool
    {
        return $this->groupManager->isAdmin($userId);

    }//end isAdmin()

    private function isValidSlug(string $candidate): bool
    {
        if (strlen($candidate) < 2 || strlen($candidate) > 48) {
            return false;
        }

        return (bool) preg_match('/^[a-z0-9][a-z0-9-]*[a-z0-9]$/', $candidate);

    }//end isValidSlug()

    private function buildDeepLink(string $slug): string
    {
        if ($slug === '') {
            return '/apps/openbuilt';
        }

        return "/apps/openbuilt/builder/{$slug}";

    }//end buildDeepLink()

    /**
     * @return array<string, mixed>
     */
    private function toArray(mixed $item): array
    {
        if (is_array($item) === true) {
            return $item;
        }

        if (is_object($item) === true && method_exists($item, 'jsonSerialize') === true) {
            $serialised = $item->jsonSerialize();
            if (is_array($serialised) === true) {
                return $serialised;
            }
        }

        return (array) $item;

    }//end toArray()

    /**
     * @param array<string, mixed> $item
     */
    private function extractUuid(array $item): string
    {
        $uuid = $item['uuid'] ?? $item['id'] ?? ($item['@self']['uuid'] ?? ($item['@self']['id'] ?? ''));
        return (string) $uuid;

    }//end extractUuid()
}//end class
