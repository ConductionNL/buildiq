<?php

/**
 * Handler for the openbuilt.upsertPage MCP tool.
 *
 * Creates or updates a page entry in the draft manifest of an ApplicationVersion.
 * The lookup uses case-insensitive page id matching so the LLM does not need to
 * remember exact casing.
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
 * Handles the openbuilt.upsertPage tool invocation.
 */
class UpsertPageHandler extends AbstractToolHandler
{

    private const PAGE_TYPES = ['dashboard', 'index', 'detail', 'form'];

    /**
     * Execute the upsertPage tool.
     *
     * @param array<string, mixed> $args Tool arguments (appSlug, versionSlug, pageId, title, type, route, config).
     *
     * @return array<string, mixed>
     */
    public function handle(array $args): array
    {
        $validation = $this->validateArgs(args: $args);
        if (isset($validation['error']) === true) {
            return $this->errorResult(error: 'invalid_arguments', message: $validation['error']);
        }

        $appSlug     = $validation['appSlug'];
        $versionSlug = $validation['versionSlug'];
        $pageId      = $validation['pageId'];

        $rbacError = $this->requireWriteRole(appSlug: $appSlug);
        if ($rbacError !== null) {
            return $rbacError;
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            $loaded = $this->loadVersion(objectService: $objectService, appSlug: $appSlug, versionSlug: $versionSlug);
            if (isset($loaded['error']) === true) {
                return $this->errorResult(error: $loaded['error'], message: $loaded['message']);
            }

            $version  = $loaded['version'];
            $manifest = (array) ($version['manifest'] ?? []);
            $pages    = (array) ($manifest['pages'] ?? []);

            $newPage = [
                'id'     => $pageId,
                'route'  => $validation['route'],
                'type'   => $validation['type'],
                'title'  => $validation['title'],
                'config' => $validation['config'],
            ];

            [$pages, $replaced] = $this->upsertPageInList(pages: $pages, pageId: $pageId, newPage: $newPage);

            $manifest['pages'] = array_values($pages);
            $saved = $this->saveVersionManifest(objectService: $objectService, version: $version, manifest: $manifest);

            $action = 'created';
            if ($replaced === true) {
                $action = 'updated';
            }

            return [
                'success'   => true,
                'action'    => $action,
                'page'      => $newPage,
                'pageCount' => count($pages),
                'version'   => [
                    'uuid' => $this->extractUuid(item: $saved),
                    'slug' => (string) ($saved['slug'] ?? $versionSlug),
                ],
            ];
        } catch (\Throwable $e) {
            $this->logger->error(
                'OpenBuilt MCP: upsertPage failed',
                ['appSlug' => $appSlug, 'pageId' => $pageId, 'exception' => $e->getMessage()]
            );
            return $this->errorResult(error: 'upsert_failed', message: 'Failed to upsert page: '.$e->getMessage());
        }//end try

    }//end handle()

    /**
     * Validate and extract typed arguments for upsertPage.
     *
     * @param array<string, mixed> $args Raw tool arguments.
     *
     * @return array<string, mixed>
     */
    private function validateArgs(array $args): array
    {
        $appSlug     = (string) ($args['appSlug'] ?? '');
        $versionSlug = (string) ($args['versionSlug'] ?? 'development');
        $pageId      = (string) ($args['pageId'] ?? '');
        $title       = (string) ($args['title'] ?? '');
        $type        = (string) ($args['type'] ?? '');
        $route       = (string) ($args['route'] ?? '');
        $config      = $args['config'] ?? [];

        if ($appSlug === '' || $this->isValidSlug(candidate: $appSlug) === false) {
            return ['error' => "Invalid appSlug '{$appSlug}'."];
        }

        if ($pageId === '') {
            return ['error' => 'pageId is required.'];
        }

        if ($title === '') {
            return ['error' => 'title is required.'];
        }

        if (in_array(needle: $type, haystack: self::PAGE_TYPES, strict: true) === false) {
            return ['error' => "Invalid page type '{$type}'."];
        }

        if ($route === '') {
            return ['error' => 'route is required.'];
        }

        if (is_array($config) === false) {
            $config = [];
        }

        return [
            'appSlug'     => $appSlug,
            'versionSlug' => $versionSlug,
            'pageId'      => $pageId,
            'title'       => $title,
            'type'        => $type,
            'route'       => $route,
            'config'      => $config,
        ];

    }//end validateArgs()

    /**
     * Upsert a page into the pages list using case-insensitive id matching.
     *
     * Returns the updated pages array and a boolean indicating whether an existing
     * page was replaced (true) or a new page was appended (false).
     *
     * @param array<int, mixed>    $pages   Existing pages list from the manifest.
     * @param string               $pageId  The page id to look up (case-insensitive).
     * @param array<string, mixed> $newPage The page definition to insert or replace with.
     *
     * @return array{0: array, 1: bool}
     */
    private function upsertPageInList(array $pages, string $pageId, array $newPage): array
    {
        $replaced = false;
        $pageIdLc = strtolower($pageId);

        foreach ($pages as $i => $existing) {
            if (is_array($existing) === true && strtolower((string) ($existing['id'] ?? '')) === $pageIdLc) {
                $pages[$i] = $newPage;
                $replaced  = true;
                break;
            }
        }

        if ($replaced === false) {
            $pages[] = $newPage;
        }

        return [$pages, $replaced];

    }//end upsertPageInList()
}//end class
