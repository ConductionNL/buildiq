<?php

/**
 * Handler for the openbuilt.promoteVersion MCP tool.
 *
 * Promotes a virtual app from one version (e.g. development) to its downstream
 * target (e.g. production) using the configured strategy.
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

/**
 * Handles the openbuilt.promoteVersion tool invocation.
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuilt/tasks.md#task-59
 */
class PromoteVersionHandler extends AbstractToolHandler
{

    private const PROMOTE_STRATEGIES = ['empty-start', 'start-with-source-data', 'migrate-existing-data'];

    /**
     * Execute the promoteVersion tool.
     *
     * @param array<string, mixed> $args Tool arguments (appSlug, sourceVersionSlug, strategy).
     *
     * @return array<string, mixed>
     */
    public function handle(array $args): array
    {
        $validation = $this->validateArgs(args: $args);
        if (isset($validation['error']) === true) {
            return $this->errorResult(error: 'invalid_arguments', message: $validation['error']);
        }

        $appSlug           = $validation['appSlug'];
        $sourceVersionSlug = $validation['sourceVersionSlug'];
        $strategy          = $validation['strategy'];

        // Spec REQ-OBVP-007 — NC admins are NOT auto-granted; caller must hold
        // an explicit owners or editors entry on the Application.
        $rbacError = $this->requireWriteRole(appSlug: $appSlug, allowAdminBypass: false);
        if ($rbacError !== null) {
            return $rbacError;
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            $loaded = $this->loadVersion(objectService: $objectService, appSlug: $appSlug, versionSlug: $sourceVersionSlug);
            if (isset($loaded['error']) === true) {
                return $this->errorResult(error: $loaded['error'], message: $loaded['message']);
            }

            $source = $loaded['version'];
            if (($source['promotesTo'] ?? null) === null || $source['promotesTo'] === '') {
                return $this->errorResult(
                    error: 'no_promote_target',
                    message: "Version '{$sourceVersionSlug}' has no downstream target."
                );
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
            $this->logger->error(
                'OpenBuilt MCP: promoteVersion failed',
                ['appSlug' => $appSlug, 'source' => $sourceVersionSlug, 'exception' => $e->getMessage()]
            );
            return $this->errorResult(error: 'promote_failed', message: 'Failed to promote version: '.$e->getMessage());
        }//end try

    }//end handle()

    /**
     * Validate and extract typed arguments for promoteVersion.
     *
     * @param array<string, mixed> $args Raw tool arguments.
     *
     * @return array{appSlug?: string, sourceVersionSlug?: string, strategy?: string, error?: string}
     */
    private function validateArgs(array $args): array
    {
        $appSlug           = (string) ($args['appSlug'] ?? '');
        $sourceVersionSlug = (string) ($args['sourceVersionSlug'] ?? '');
        $strategy          = (string) ($args['strategy'] ?? 'empty-start');

        if ($appSlug === '' || $this->isValidSlug(candidate: $appSlug) === false) {
            return ['error' => "Invalid appSlug '{$appSlug}'."];
        }

        if ($sourceVersionSlug === '' || $this->isValidSlug(candidate: $sourceVersionSlug) === false) {
            return ['error' => "Invalid sourceVersionSlug '{$sourceVersionSlug}'."];
        }

        if (in_array(needle: $strategy, haystack: self::PROMOTE_STRATEGIES, strict: true) === false) {
            return ['error' => "Invalid strategy '{$strategy}'."];
        }

        return [
            'appSlug'           => $appSlug,
            'sourceVersionSlug' => $sourceVersionSlug,
            'strategy'          => $strategy,
        ];

    }//end validateArgs()
}//end class
