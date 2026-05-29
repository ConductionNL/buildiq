<?php

/**
 * Handler for the openbuild.createApp MCP tool.
 *
 * Creates a new OpenBuild virtual app with an initial draft ApplicationVersion.
 * Preset determines the version chain: "single", "dev-prod" or "dev-staging-prod".
 *
 * @category Service
 * @package  OCA\OpenBuild\Mcp\Handler
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

namespace OCA\OpenBuild\Mcp\Handler;

/**
 * Handles the openbuild.createApp tool invocation.
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-8
 */
class CreateAppHandler extends AbstractToolHandler
{

    private const CREATE_PRESETS = ['single', 'dev-prod', 'dev-staging-prod'];

    /**
     * Execute the createApp tool.
     *
     * @param array<string, mixed> $args Tool arguments (slug, name, description, preset).
     *
     * @return array<string, mixed>
     */
    public function handle(array $args): array
    {
        $slug        = (string) ($args['slug'] ?? '');
        $name        = (string) ($args['name'] ?? '');
        $description = (string) ($args['description'] ?? '');
        $preset      = (string) ($args['preset'] ?? 'dev-prod');

        $argError = $this->validateArgs(slug: $slug, name: $name, preset: $preset);
        if ($argError !== null) {
            return $this->errorResult(error: 'invalid_arguments', message: $argError);
        }

        // Creating an app requires NC admin privileges (same policy as schema
        // creation — the app author controls a new register + schemas).
        $adminError = $this->requireAdminUser();
        if ($adminError !== null) {
            return $adminError;
        }

        try {
            $creationService = $this->container->get('OCA\OpenBuild\Service\ApplicationCreationService');
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
            $this->logger->error(
                'OpenBuild MCP: createApp failed',
                ['slug' => $slug, 'exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]
            );
            return $this->errorResult(error: 'create_failed', message: 'Failed to create virtual app. See server logs for details.');
        }//end try

    }//end handle()

    /**
     * Validate the arguments for createApp, returning an error string or null on success.
     *
     * @param string $slug   Application slug.
     * @param string $name   Application display name.
     * @param string $preset Version chain preset.
     *
     * @return string|null
     */
    private function validateArgs(string $slug, string $name, string $preset): ?string
    {
        if ($slug === '' || $this->isValidSlug(candidate: $slug) === false) {
            return "Invalid slug '{$slug}'.";
        }

        if ($name === '' || strlen($name) < 2 || strlen($name) > 80) {
            return 'Name must be between 2 and 80 characters.';
        }

        if (in_array(needle: $preset, haystack: self::CREATE_PRESETS, strict: true) === false) {
            return "Invalid preset '{$preset}'.";
        }

        return null;

    }//end validateArgs()
}//end class
