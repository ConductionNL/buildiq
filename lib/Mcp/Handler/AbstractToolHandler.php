<?php

/**
 * Abstract base class for OpenBuilt MCP tool handlers.
 *
 * Provides the shared utilities (slug validation, auth check, error envelope,
 * toArray/extractUuid coercion, deep-link builder) that every concrete handler
 * needs, eliminating duplication across the handler family.
 *
 * @category Service
 * @package  OCA\OpenBuilt\Mcp\Handler
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenBuilt\Mcp\Handler;

use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Abstract base for all OpenBuilt MCP tool handler classes.
 *
 * Concrete handlers extend this and implement handle(array $args): array.
 */
abstract class AbstractToolHandler
{

    protected const REGISTER_SLUG = 'openbuilt';

    /**
     * Constructor.
     *
     * @param IUserSession       $userSession User session used to resolve the current authenticated user.
     * @param ContainerInterface $container   DI container used to resolve OpenRegister services lazily.
     * @param LoggerInterface    $logger      PSR logger used for non-fatal warnings and error logging.
     */
    public function __construct(
        protected readonly IUserSession $userSession,
        protected readonly ContainerInterface $container,
        protected readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Execute the tool logic for the given arguments.
     *
     * @param array<string, mixed> $args Raw MCP tool arguments.
     *
     * @return array<string, mixed>
     */
    abstract public function handle(array $args): array;

    /**
     * Build a uniform MCP error envelope.
     *
     * @param string $error   Machine-readable error code.
     * @param string $message Human-readable, end-user-safe error message.
     *
     * @return array{isError: true, error: string, message: string}
     */
    protected function errorResult(string $error, string $message): array
    {
        return ['isError' => true, 'error' => $error, 'message' => $message];

    }//end errorResult()

    /**
     * Return the authenticated user's UID, or null if there is no session user.
     *
     * @return string|null
     */
    protected function requireAuthenticatedUser(): ?string
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
     * Validate that a candidate string matches the OpenBuilt slug shape.
     *
     * @param string $candidate Candidate slug to validate.
     *
     * @return bool
     */
    protected function isValidSlug(string $candidate): bool
    {
        if (strlen($candidate) < 2 || strlen($candidate) > 48) {
            return false;
        }

        return (bool) preg_match('/^[a-z0-9][a-z0-9-]*[a-z0-9]$/', $candidate);

    }//end isValidSlug()

    /**
     * Build a Nextcloud deep link into the OpenBuilt builder for the given application slug.
     *
     * @param string $slug Application slug (empty falls back to the app root).
     *
     * @return string
     */
    protected function buildDeepLink(string $slug): string
    {
        if ($slug === '') {
            return '/apps/openbuilt';
        }

        return "/apps/openbuilt/builder/{$slug}";

    }//end buildDeepLink()

    /**
     * Build an MCP "source" descriptor pointing at the OpenBuilt app deep link.
     *
     * @param string $uuid  Application UUID.
     * @param string $slug  Application slug used to build the deep link.
     * @param string $label Human-readable label for the source descriptor.
     *
     * @return array{type: string, uuid: string, url: string, label: string}
     */
    protected function sourceDescriptor(string $uuid, string $slug, string $label): array
    {
        return ['type' => 'openbuilt.application', 'uuid' => $uuid, 'url' => $this->buildDeepLink(slug: $slug), 'label' => $label];

    }//end sourceDescriptor()

    /**
     * Coerce an OR entity, array, or generic value into an associative array.
     *
     * @param mixed $item Value to coerce.
     *
     * @return array<string, mixed>
     */
    protected function toArray(mixed $item): array
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
     * Extract a UUID from a normalised OR object array.
     *
     * @param array<string, mixed> $item Normalised OR object as an associative array.
     *
     * @return string
     */
    protected function extractUuid(array $item): string
    {
        $uuid = $item['uuid'] ?? $item['id'] ?? ($item['@self']['uuid'] ?? ($item['@self']['id'] ?? ''));
        return (string) $uuid;

    }//end extractUuid()

    /**
     * Resolve <appSlug, versionSlug> to {version, appUuid, appName}, or {error, message}.
     *
     * @param object $objectService OpenRegister ObjectService instance.
     * @param string $appSlug       Application slug to resolve.
     * @param string $versionSlug   ApplicationVersion slug to resolve.
     *
     * @return array{version?: array, appUuid?: string, appName?: string, error?: string, message?: string}
     */
    protected function loadVersion(object $objectService, string $appSlug, string $versionSlug): array
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
     * Save an ApplicationVersion with a new manifest.
     *
     * @param object               $objectService OpenRegister ObjectService instance.
     * @param array<string, mixed> $version       The existing ApplicationVersion as an associative array.
     * @param array<string, mixed> $manifest      The new manifest blob to write onto the version.
     *
     * @return array<string, mixed>
     */
    protected function saveVersionManifest(object $objectService, array $version, array $manifest): array
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
}//end class
