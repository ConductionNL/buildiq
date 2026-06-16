<?php

/**
 * OpenBuild MCP Tool Provider
 *
 * Per-app implementation of OCA\OpenRegister\Mcp\IMcpToolProvider. Exposes the
 * full OpenBuild authoring surface to an LLM via MCP: list/read apps, create
 * new apps, promote versions, and mutate a draft version's manifest (pages,
 * widgets, menu items) and per-version schemas.
 *
 * This class is a thin dispatcher: all tool logic lives in dedicated handler
 * classes under OCA\OpenBuild\Mcp\Handler\.
 *
 * @category Service
 * @package  OCA\OpenBuild\Mcp
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-8
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-42
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-50
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-59
 * @spec openspec/changes/retrofit-2026-05-24-openbuild-runtime-mcp/tasks.md#task-1
 * @spec openspec/changes/retrofit-2026-05-24-openbuild-runtime-mcp/tasks.md#task-2
 * @spec openspec/changes/retrofit-2026-05-24-openbuild-runtime-mcp/tasks.md#task-3
 * @spec openspec/changes/retrofit-2026-05-24-openbuild-runtime-mcp/tasks.md#task-4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Mcp;

use OCA\OpenBuild\Service\PermissionResolver;
use OCA\OpenRegister\Mcp\IMcpToolProvider;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * OpenBuild MCP Tool Provider — thin dispatcher to per-tool handler classes.
 *
 * Read tools:
 *   - openbuild.listApps        → ListAppsHandler
 *   - openbuild.getAppManifest  → GetAppManifestHandler
 *
 * Write tools (lifecycle):
 *   - openbuild.createApp       → CreateAppHandler
 *   - openbuild.promoteVersion  → PromoteVersionHandler
 *
 * Write tools (authoring against the draft version's manifest):
 *   - openbuild.upsertSchema    → UpsertSchemaHandler
 *   - openbuild.upsertPage      → UpsertPageHandler
 *   - openbuild.addWidget       → AddWidgetHandler
 *   - openbuild.upsertMenuItem  → UpsertMenuItemHandler
 *
 * Authoring tools default to the `development` version so a misfired tool
 * call cannot mutate production. To promote the change use promoteVersion.
 */
class OpenBuildToolProvider implements IMcpToolProvider
{

    /**
     * Tool catalogue.
     *
     * @var array<int, array<string, mixed>>
     */
    private const TOOL_DESCRIPTORS = [
        [
            'id'          => 'openbuild.listApps',
            'name'        => 'List virtual apps',
            'description' => 'List the virtual apps built with OpenBuild in your organisation.',
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
            'id'          => 'openbuild.getAppManifest',
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
            'id'          => 'openbuild.createApp',
            'name'        => 'Create a new virtual app',
            'description' => 'Create a new OpenBuild virtual app with an initial draft ApplicationVersion.'
                .' Preset chooses the version chain: "single", "dev-prod" or "dev-staging-prod".',
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
            'id'          => 'openbuild.promoteVersion',
            'name'        => 'Promote a virtual app version',
            'description' => 'Promote a virtual app from one version (e.g. development) to the next (e.g. production).'
                .' Strategy "empty-start" (default, safest) leaves the target empty.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'appSlug'           => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]*[a-z0-9]$', 'minLength' => 2, 'maxLength' => 48],
                    'sourceVersionSlug' => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]*[a-z0-9]$', 'minLength' => 2, 'maxLength' => 48],
                    'strategy'          => [
                        'type'    => 'string',
                        'enum'    => ['empty-start', 'start-with-source-data', 'migrate-existing-data'],
                        'default' => 'empty-start',
                    ],
                ],
                'required'   => ['appSlug', 'sourceVersionSlug'],
            ],
        ],
        [
            'id'          => 'openbuild.upsertSchema',
            'name'        => 'Create or update a schema in a virtual app',
            'description' => 'Create or update a JSON Schema in the given app version\'s per-version OR register.'
                .' Slug is automatically namespaced with appSlug+versionSlug.'
                .' Properties is a JSON Schema property map; required is an array of property names.'
                .' Defaults versionSlug to "development".',
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
            'id'          => 'openbuild.upsertPage',
            'name'        => 'Create or update a page in a virtual app',
            'description' => 'Create or update a page in the draft manifest.'
                .' pageId is the unique key; if it exists it is replaced.'
                .' Type is one of dashboard, index, detail, form.'
                .' config is page-type-specific (e.g. {register, schema, columns} for index pages,'
                .' {widgets, layout} for dashboards). Defaults versionSlug to "development".',
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
            'id'          => 'openbuild.addWidget',
            'name'        => 'Add a widget to a page',
            'description' => 'Append a widget to a page\'s config.widgets array in the draft manifest.'
                .' widgetType is e.g. "stat-counter", "chart", "list". widgetConfig is widget-type-specific.'
                .' Defaults versionSlug to "development".',
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
            'id'          => 'openbuild.upsertMenuItem',
            'name'        => 'Create or update a menu item',
            'description' => 'Create or update a top-level menu item in the draft manifest.'
                .' id is the unique key; if it exists it is replaced. route should match a page id.'
                .' order controls sort. icon is an MDI/standard icon name. Defaults versionSlug to "development".',
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

    /**
     * Constructor.
     *
     * @param IUserSession       $userSession        User session used to resolve the current authenticated user.
     * @param IGroupManager      $groupManager       Group manager used for admin checks.
     * @param ContainerInterface $container          DI container used to resolve OpenRegister and OpenBuild services lazily.
     * @param LoggerInterface    $logger             PSR logger used for non-fatal warnings and error logging.
     * @param PermissionResolver $permissionResolver Shared permission-grammar resolver (H1 fix).
     */
    public function __construct(
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
        private readonly ?PermissionResolver $permissionResolver=null,
    ) {
    }//end __construct()

    /**
     * Return the Nextcloud app id this provider belongs to.
     *
     * @return string
     */
    public function getAppId(): string
    {
        return 'openbuild';

    }//end getAppId()

    /**
     * Return the catalogue of MCP tools exposed by this provider.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTools(): array
    {
        return self::TOOL_DESCRIPTORS;

    }//end getTools()

    /**
     * Dispatch an MCP tool invocation to the matching handler.
     *
     * @param string               $toolId    Fully qualified tool id (e.g. "openbuild.listApps").
     * @param array<string, mixed> $arguments Raw tool arguments as supplied by the MCP client.
     *
     * @return array<string, mixed>
     */
    public function invokeTool(string $toolId, array $arguments): array
    {
        // Handler class names are referenced as strings to keep the coupling
        // count of this dispatcher class within the PHPMD threshold.
        $handlerMap = [
            'openbuild.listApps'       => 'OCA\OpenBuild\Mcp\Handler\ListAppsHandler',
            'openbuild.getAppManifest' => 'OCA\OpenBuild\Mcp\Handler\GetAppManifestHandler',
            'openbuild.createApp'      => 'OCA\OpenBuild\Mcp\Handler\CreateAppHandler',
            'openbuild.promoteVersion' => 'OCA\OpenBuild\Mcp\Handler\PromoteVersionHandler',
            'openbuild.upsertSchema'   => 'OCA\OpenBuild\Mcp\Handler\UpsertSchemaHandler',
            'openbuild.upsertPage'     => 'OCA\OpenBuild\Mcp\Handler\UpsertPageHandler',
            'openbuild.addWidget'      => 'OCA\OpenBuild\Mcp\Handler\AddWidgetHandler',
            'openbuild.upsertMenuItem' => 'OCA\OpenBuild\Mcp\Handler\UpsertMenuItemHandler',
        ];

        if (isset($handlerMap[$toolId]) === true) {
            return $this->makeHandler(class: $handlerMap[$toolId])->handle($arguments);
        }

        return [
            'isError' => true,
            'error'   => 'unknown_tool',
            'message' => "Unknown tool id '{$toolId}'. Available tools: "
                .implode(separator: ', ', array: array_column(array: self::TOOL_DESCRIPTORS, column_key: 'id')).'.',
        ];

    }//end invokeTool()

    /**
     * Instantiate a handler class, injecting the shared collaborators.
     *
     * @param class-string $class Fully qualified handler class name.
     *
     * @return \OCA\OpenBuild\Mcp\Handler\AbstractToolHandler
     */
    private function makeHandler(string $class): \OCA\OpenBuild\Mcp\Handler\AbstractToolHandler
    {
        return new $class(
            $this->userSession,
            $this->container,
            $this->logger,
            $this->groupManager,
            $this->permissionResolver,
        );

    }//end makeHandler()
}//end class
