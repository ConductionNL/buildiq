<?php

/**
 * Handler for the openbuilt.listApps MCP tool.
 *
 * Returns matching virtual apps with source descriptors, applying an optional
 * status filter and a result-count cap.
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
 * Handles the openbuilt.listApps tool invocation.
 */
class ListAppsHandler extends AbstractToolHandler
{

    private const ITEMS_CAP = 20;

    private const APP_STATUSES = ['any', 'draft', 'published', 'archived'];

    /**
     * Execute the listApps tool.
     *
     * @param array<string, mixed> $args Tool arguments (limit, statusFilter).
     *
     * @return array<string, mixed>
     */
    public function handle(array $args): array
    {
        $validation = $this->validateArgs(args: $args);
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

    }//end handle()

    /**
     * Validate and normalise arguments for the listApps tool.
     *
     * @param array<string, mixed> $args Raw tool arguments.
     *
     * @return array{limit?: int, statusFilter?: string, error?: string}
     */
    private function validateArgs(array $args): array
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

    }//end validateArgs()

    /**
     * Map a raw Application object/array into the compact representation returned by listApps.
     *
     * @param mixed $raw Raw OR Application entity, array, or any JSON-serialisable value.
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
}//end class
