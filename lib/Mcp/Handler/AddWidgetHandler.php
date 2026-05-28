<?php

/**
 * Handler for the openbuilt.addWidget MCP tool.
 *
 * Appends a widget to a page's config.widgets array in the draft manifest of
 * an ApplicationVersion. Uses case-insensitive page-id matching (same rationale
 * as UpsertPageHandler).
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
 * Handles the openbuilt.addWidget tool invocation.
 */
class AddWidgetHandler extends AbstractToolHandler
{

    /**
     * Allowed widget type identifiers (issue #167 — widgetType allow-list).
     *
     * Callers may only reference widget types that exist in the OpenBuilt
     * widget registry. Unknown types are rejected at input time so invalid
     * manifests never reach OR storage.
     *
     * @var array<int, string>
     */
    private const ALLOWED_WIDGET_TYPES = [
        'stat-counter',
        'data-table',
        'chart-bar',
        'chart-line',
        'chart-pie',
        'kanban-board',
        'timeline',
        'markdown',
        'iframe',
        'form-embed',
        'object-list',
        'object-detail',
        'calendar',
        'map',
    ];

    /**
     * Execute the addWidget tool.
     *
     * @param array<string, mixed> $args Tool arguments (appSlug, versionSlug, pageId, widgetType, widgetConfig).
     *
     * @return array<string, mixed>
     */
    public function handle(array $args): array
    {
        $validation = $this->validateArgs(args: $args);
        if (isset($validation['error']) === true) {
            return $this->errorResult(error: 'invalid_arguments', message: $validation['error']);
        }

        $appSlug      = $validation['appSlug'];
        $versionSlug  = $validation['versionSlug'];
        $pageId       = $validation['pageId'];
        $widgetType   = $validation['widgetType'];
        $widgetConfig = $validation['widgetConfig'];

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

            $foundIdx = $this->findPageIndex(pages: $pages, pageId: $pageId);
            if ($foundIdx === null) {
                return $this->errorResult(error: 'not_found', message: "Page '{$pageId}' not found in manifest.");
            }

            [$pages, $widget] = $this->appendWidget(
                pages: $pages,
                pageIdx: $foundIdx,
                widgetType: $widgetType,
                widgetConfig: $widgetConfig
            );

            $manifest['pages'] = array_values($pages);

            // H4: enforce widgets-per-page (50) and total manifest size (256 KB).
            $capError = $this->checkManifestCaps(manifest: $manifest, pageIdx: $foundIdx);
            if ($capError !== null) {
                return $capError;
            }

            $saved = $this->saveVersionManifest(objectService: $objectService, version: $version, manifest: $manifest);

            $pageConfig  = (array) ($pages[$foundIdx]['config'] ?? []);
            $widgetCount = count((array) ($pageConfig['widgets'] ?? []));

            return [
                'success'     => true,
                'added'       => true,
                'widget'      => $widget,
                'pageId'      => $pageId,
                'widgetCount' => $widgetCount,
                'version'     => [
                    'uuid' => $this->extractUuid(item: $saved),
                    'slug' => (string) ($saved['slug'] ?? $versionSlug),
                ],
            ];
        } catch (\Throwable $e) {
            $this->logger->error(
                'OpenBuilt MCP: addWidget failed',
                ['appSlug' => $appSlug, 'pageId' => $pageId, 'exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]
            );
            return $this->errorResult(error: 'add_failed', message: 'Failed to add widget. See server logs for details.');
        }//end try

    }//end handle()

    /**
     * Validate and extract typed arguments for addWidget.
     *
     * @param array<string, mixed> $args Raw tool arguments.
     *
     * @return array{appSlug?: string, versionSlug?: string, pageId?: string, widgetType?: string, widgetConfig?: array, error?: string}
     */
    private function validateArgs(array $args): array
    {
        $appSlug      = (string) ($args['appSlug'] ?? '');
        $versionSlug  = (string) ($args['versionSlug'] ?? 'development');
        $pageId       = (string) ($args['pageId'] ?? '');
        $widgetType   = (string) ($args['widgetType'] ?? '');
        $widgetConfig = $args['widgetConfig'] ?? [];

        if ($appSlug === '' || $this->isValidSlug(candidate: $appSlug) === false) {
            return ['error' => "Invalid appSlug '{$appSlug}'."];
        }

        if ($pageId === '') {
            return ['error' => 'pageId is required.'];
        }

        if ($widgetType === '') {
            return ['error' => 'widgetType is required.'];
        }

        // Validate widgetType against the known widget registry (issue #167).
        if (in_array(needle: $widgetType, haystack: self::ALLOWED_WIDGET_TYPES, strict: true) === false) {
            $allowed = implode(', ', self::ALLOWED_WIDGET_TYPES);
            return ['error' => "Unknown widgetType '{$widgetType}'. Allowed types: {$allowed}."];
        }

        if (is_array($widgetConfig) === false) {
            $widgetConfig = [];
        }

        return [
            'appSlug'      => $appSlug,
            'versionSlug'  => $versionSlug,
            'pageId'       => $pageId,
            'widgetType'   => $widgetType,
            'widgetConfig' => $widgetConfig,
        ];

    }//end validateArgs()

    /**
     * Find the array index of a page by case-insensitive id matching.
     *
     * @param array<int, mixed> $pages  Pages list from the manifest.
     * @param string            $pageId The page id to find.
     *
     * @return int|null Index of the found page, or null if not found.
     */
    private function findPageIndex(array $pages, string $pageId): ?int
    {
        $pageIdLc = strtolower($pageId);
        foreach ($pages as $i => $existing) {
            if (is_array($existing) === true && strtolower((string) ($existing['id'] ?? '')) === $pageIdLc) {
                return $i;
            }
        }

        return null;

    }//end findPageIndex()

    /**
     * Append a widget to the target page's config.widgets array.
     *
     * @param array<int, mixed> $pages        Pages list from the manifest.
     * @param int               $pageIdx      Index of the target page.
     * @param string            $widgetType   Widget type identifier.
     * @param array             $widgetConfig Widget-type-specific configuration blob.
     *
     * @return array{0: array, 1: array{type: string, config: array}}
     */
    private function appendWidget(array $pages, int $pageIdx, string $widgetType, array $widgetConfig): array
    {
        $page       = $pages[$pageIdx];
        $pageConfig = (array) ($page['config'] ?? []);
        $widgets    = (array) ($pageConfig['widgets'] ?? []);
        $widget     = ['type' => $widgetType, 'config' => $widgetConfig];
        $widgets[]  = $widget;
        $pageConfig['widgets'] = $widgets;
        $page['config']        = $pageConfig;
        $pages[$pageIdx]       = $page;

        return [$pages, $widget];

    }//end appendWidget()
}//end class
