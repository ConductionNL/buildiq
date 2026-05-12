<?php

/**
 * OpenBuilt MCP Tool Provider
 *
 * Per-app implementation of OCA\OpenRegister\Mcp\IMcpToolProvider (hydra ADR-034
 * AI Chat Companion + ADR-035 MCP tool surface). Exposes two read-only MVP tools
 * so the AI Chat Companion can surface OpenBuilt's virtual-app catalogue to an LLM:
 * listing the virtual apps in the caller's organisation and reading a single
 * published app's manifest by slug.
 *
 * @category Mcp
 * @package  OCA\OpenBuilt\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
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
 * Implements IMcpToolProvider (from openregister PR #1466,
 * change ai-chat-companion-orchestrator) exposing two read-only tools to the
 * AI Chat Companion. The full catalogue is always returned by getTools();
 * per-object authorisation runs inside invokeTool().
 *
 * Auth design (OWASP A01:2021 / ADR-005):
 * - Per-object authorisation runs inside invokeTool(), AFTER argument validation
 *   but BEFORE business logic. The helper invoked MUST actually run.
 * - requireAuthenticatedUser() returns string|null — it does NOT return a
 *   non-empty value unconditionally and is NOT wrapped in catch(\Throwable).
 * - Object-level scoping (organisation) is enforced by OpenRegister's
 *   ObjectService multitenancy filter on every query; admin (IGroupManager::isAdmin)
 *   is exposed via isAdmin() for symmetry with sibling providers and future ACLs.
 *
 * Both tools are read-only; there are no state-changing tools in this MVP.
 */
class OpenBuiltToolProvider implements IMcpToolProvider
{

    /**
     * Maximum number of items (and source descriptors) per list result.
     *
     * @var int
     */
    private const ITEMS_CAP = 20;

    /**
     * The OpenRegister register slug OpenBuilt stores its objects in.
     *
     * @var string
     */
    private const REGISTER_SLUG = 'openbuilt';

    /**
     * Allowed values for the listApps statusFilter argument.
     *
     * @var array<int, string>
     */
    private const APP_STATUSES = ['any', 'draft', 'published', 'archived'];

    /**
     * Tool catalogue.
     *
     * Hard-coded as a constant so unit tests can assert it as a fixture.
     *
     * @var array<int, array<string, mixed>>
     */
    private const TOOL_DESCRIPTORS = [
        [
            'id'          => 'openbuilt.listApps',
            'name'        => 'List virtual apps',
            'description' => 'List the virtual apps built with OpenBuilt in your organisation, with slug, name, status and version.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'limit'        => [
                        'type'    => 'integer',
                        'minimum' => 1,
                        'maximum' => 50,
                        'default' => 20,
                    ],
                    'statusFilter' => [
                        'type'    => 'string',
                        'enum'    => ['any', 'draft', 'published', 'archived'],
                        'default' => 'any',
                    ],
                ],
                'required'   => [],
            ],
        ],
        [
            'id'          => 'openbuilt.getAppManifest',
            'name'        => 'Get virtual app manifest',
            'description' => 'Fetch the runtime manifest blob for one published virtual app, addressed by its kebab-case slug.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'slug' => [
                        'type'      => 'string',
                        'pattern'   => '^[a-z0-9][a-z0-9-]*[a-z0-9]$',
                        'minLength' => 2,
                        'maxLength' => 48,
                    ],
                ],
                'required'   => ['slug'],
            ],
        ],
    ];

    /**
     * Constructor for OpenBuiltToolProvider.
     *
     * @param IUserSession       $userSession  The current user session
     * @param IGroupManager      $groupManager The group manager (for admin checks)
     * @param ContainerInterface $container    The DI container (for ObjectService)
     * @param LoggerInterface    $logger       The PSR-3 logger
     *
     * @return void
     */
    public function __construct(
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Returns the app ID that namespaces every tool id.
     *
     * @return string "openbuilt"
     */
    public function getAppId(): string
    {
        return 'openbuilt';

    }//end getAppId()

    /**
     * Returns the full tool catalogue (2 tools, always).
     *
     * The full catalogue is always returned regardless of caller permissions.
     * Per-object authorisation runs in invokeTool().
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTools(): array
    {
        return self::TOOL_DESCRIPTORS;

    }//end getTools()

    /**
     * Dispatch a tool call by id.
     *
     * Argument validation runs BEFORE authorisation (cheap before expensive),
     * which runs BEFORE business logic. Unknown tool ids return a structured
     * error; no exception is thrown.
     *
     * @param string               $toolId    The tool id (e.g. "openbuilt.listApps")
     * @param array<string, mixed> $arguments Tool arguments from the LLM call
     *
     * @return array<string, mixed>
     */
    public function invokeTool(string $toolId, array $arguments): array
    {
        return match ($toolId) {
            'openbuilt.listApps'       => $this->handleListApps(args: $arguments),
            'openbuilt.getAppManifest' => $this->handleGetAppManifest(args: $arguments),
            default                    => $this->errorResult(
                error: 'unknown_tool',
                message: "Unknown tool id '{$toolId}'. Available tools: "
                    .implode(separator: ', ', array: array_column(array: self::TOOL_DESCRIPTORS, column_key: 'id')).'.',
            ),
        };

    }//end invokeTool()

    // =========================================================================
    // Private tool handlers
    // =========================================================================

    /**
     * Handle openbuilt.listApps.
     *
     * Returns the virtual apps in the caller's organisation. Scoping is enforced
     * by OpenRegister's ObjectService multitenancy filter; the caller must be
     * authenticated.
     *
     * @param array<string, mixed> $args Tool arguments
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

            $query = ['@self' => ['register' => self::REGISTER_SLUG, 'schema' => 'application']];
            if ($validation['statusFilter'] !== 'any') {
                $query['status'] = $validation['statusFilter'];
            }

            $rawApps = $objectService->searchObjects(query: $query);
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
            return $this->errorResult(error: 'internal_error', message: 'Failed to retrieve virtual apps. See server log for details.');
        }//end try

    }//end handleListApps()

    /**
     * Handle openbuilt.getAppManifest.
     *
     * Resolves a slug → BuiltAppRoute → applicationUuid → Application → manifest,
     * mirroring the lookup performed by ApplicationsController::getManifest().
     *
     * @param array<string, mixed> $args Tool arguments
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
            return $this->errorResult(error: 'invalid_arguments', message: "Invalid slug '{$slug}'. Expected kebab-case, 2-48 chars.");
        }

        if ($this->requireAuthenticatedUser() === null) {
            return $this->errorResult(error: 'forbidden', message: 'You must be signed in to read a virtual app manifest.');
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            $resolved = $this->resolveApplicationBySlug(objectService: $objectService, slug: (string) $slug);
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
                'version'  => (string) ($application['version'] ?? ''),
                'status'   => (string) ($application['status'] ?? ''),
                'manifest' => $manifest,
                'sources'  => [$this->sourceDescriptor(uuid: $this->extractUuid(item: $application), slug: (string) $slug, label: $name)],
            ];
        } catch (\Throwable $e) {
            $this->logger->error('OpenBuilt MCP: getAppManifest failed', ['slug' => $slug, 'exception' => $e->getMessage()]);
            return $this->errorResult(error: 'internal_error', message: 'Failed to resolve manifest. See server log for details.');
        }//end try

    }//end handleGetAppManifest()

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Validate the listApps arguments.
     *
     * @param array<string, mixed> $args Tool arguments.
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
            return ['error' => "Invalid limit {$limit}. Must be between 1 and 50."];
        }

        $statusFilter = (string) ($args['statusFilter'] ?? 'any');
        if (in_array(needle: $statusFilter, haystack: self::APP_STATUSES, strict: true) === false) {
            return ['error' => "Invalid statusFilter '{$statusFilter}'. Allowed: ".implode(separator: ', ', array: self::APP_STATUSES).'.'];
        }

        return ['limit' => $limit, 'statusFilter' => $statusFilter];

    }//end validateListAppsArgs()

    /**
     * Resolve a slug to its published Application object via the BuiltAppRoute index.
     *
     * @param object $objectService The OpenRegister ObjectService.
     * @param string $slug          The virtual-app slug.
     *
     * @return array{application?: array<string, mixed>, error?: string, message?: string}
     */
    private function resolveApplicationBySlug(object $objectService, string $slug): array
    {
        $routeResults = $objectService->searchObjects(
            query: ['@self' => ['register' => self::REGISTER_SLUG, 'schema' => 'built-app-route'], 'slug' => $slug]
        );

        if (is_array(value: $routeResults) === false || empty($routeResults) === true) {
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
     * Map a raw OpenRegister Application object to the trimmed list shape.
     *
     * @param mixed $raw Raw item from ObjectService.
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
     * Build a source descriptor for an OpenBuilt Application.
     *
     * @param string $uuid  The Application UUID.
     * @param string $slug  The Application slug.
     * @param string $label The human-readable label.
     *
     * @return array{type: string, uuid: string, url: string, label: string}
     */
    private function sourceDescriptor(string $uuid, string $slug, string $label): array
    {
        return [
            'type'  => 'openbuilt.application',
            'uuid'  => $uuid,
            'url'   => $this->buildDeepLink(slug: $slug),
            'label' => $label,
        ];

    }//end sourceDescriptor()

    /**
     * Build a structured error envelope.
     *
     * @param string $error   The machine-readable error code.
     * @param string $message The human-readable message.
     *
     * @return array{isError: true, error: string, message: string}
     */
    private function errorResult(string $error, string $message): array
    {
        return ['isError' => true, 'error' => $error, 'message' => $message];

    }//end errorResult()

    /**
     * Resolve the calling user's id, or null when no user is signed in.
     *
     * Auth design (OWASP A01:2021 / ADR-005): this helper MUST actually run —
     * it does not return a non-empty value unconditionally. It is the per-object
     * gate for both tools; OpenRegister's ObjectService multitenancy filter then
     * scopes results to the caller's organisation.
     *
     * @return string|null The Nextcloud user id, or null when unauthenticated.
     */
    private function requireAuthenticatedUser(): ?string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return null;
        }

        $uid = $user->getUID();
        if ($uid === '') {
            return null;
        }

        return $uid;

    }//end requireAuthenticatedUser()

    /**
     * Check whether the user is a Nextcloud system administrator.
     *
     * Exposed for symmetry with sibling MCP providers and future per-app ACLs;
     * the MVP tools are read-only and org-scoped, so admin is not required.
     *
     * @param string $userId The Nextcloud user id.
     *
     * @return bool True when the user is a system admin.
     */
    public function isAdmin(string $userId): bool
    {
        return $this->groupManager->isAdmin($userId);

    }//end isAdmin()

    /**
     * Validate that a string is an OpenBuilt virtual-app slug (kebab-case, 2-48 chars).
     *
     * @param string $candidate The candidate string to validate.
     *
     * @return bool True when the string is slug-shaped.
     */
    private function isValidSlug(string $candidate): bool
    {
        if (strlen($candidate) < 2 || strlen($candidate) > 48) {
            return false;
        }

        return (bool) preg_match('/^[a-z0-9][a-z0-9-]*[a-z0-9]$/', $candidate);

    }//end isValidSlug()

    /**
     * Build a deep link path for an OpenBuilt virtual app.
     *
     * @param string $slug The virtual-app slug.
     *
     * @return string The deep link path, e.g. /apps/openbuilt/builder/<slug>.
     */
    private function buildDeepLink(string $slug): string
    {
        if ($slug === '') {
            return '/apps/openbuilt';
        }

        return "/apps/openbuilt/builder/{$slug}";

    }//end buildDeepLink()

    /**
     * Normalise an OpenRegister object/result entry to a plain PHP array.
     *
     * @param mixed $item Raw item from ObjectService.
     *
     * @return array<string, mixed>
     */
    private function toArray(mixed $item): array
    {
        if (is_array(value: $item) === true) {
            return $item;
        }

        if (is_object(value: $item) === true && method_exists($item, 'jsonSerialize') === true) {
            $serialised = $item->jsonSerialize();
            if (is_array(value: $serialised) === true) {
                return $serialised;
            }
        }

        return (array) $item;

    }//end toArray()

    /**
     * Extract the UUID from a normalised object array.
     *
     * Checks multiple common field names to handle different OR object shapes.
     *
     * @param array<string, mixed> $item The normalised object array.
     *
     * @return string The UUID, or empty string when not found.
     */
    private function extractUuid(array $item): string
    {
        $uuid = $item['uuid'] ?? $item['id'] ?? ($item['@self']['uuid'] ?? ($item['@self']['id'] ?? ''));
        return (string) $uuid;

    }//end extractUuid()
}//end class
