<?php

/**
 * Handler for the buildiq.addWidget MCP tool.
 *
 * Appends a widget to a page's config.widgets array in the draft manifest of
 * an ApplicationVersion, together with the config.layout row that places it on
 * the grid. Uses case-insensitive page-id matching (same rationale as
 * UpsertPageHandler).
 *
 * The entry itself is built by {@see \OCA\Buildiq\Support\ManifestWidgetShape},
 * shared with the copilot's manifest predictor. This handler used to write
 * `{type, config}`, which the canonical validator rejects for want of an `id`
 * and a `title`, so every widget it stored made the whole manifest invalid.
 *
 * @category Service
 * @package  OCA\Buildiq\Mcp\Handler
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

namespace OCA\Buildiq\Mcp\Handler;

use OCA\Buildiq\Support\ManifestWidgetShape;

/**
 * Handles the buildiq.addWidget tool invocation.
 */
class AddWidgetHandler extends AbstractToolHandler {

	/**
	 * Allowed widget type identifiers (issue #167 — widgetType allow-list).
	 *
	 * Callers may only reference widget types that exist in the Buildiq
	 * widget registry. Unknown types are rejected at input time so invalid
	 * manifests never reach OR storage.
	 *
	 * Public because `BuildiqToolProvider` publishes it as this tool's
	 * `widgetType` enum. The copilot shows that catalogue to the model and
	 * validates a plan against it, so spelling the list out there is what
	 * stops an unknown type being accepted at review and refused at execute:
	 * the same gap that let a bare menu route through.
	 *
	 * @var array<int, string>
	 */
	public const ALLOWED_WIDGET_TYPES = [
		// Content & layout widgets.
		'header',
		'label',
		'text',
		'image',
		'divider',
		'tile',
		// Statistic / metric widgets (OpenRegister-data-driven).
		'stat',
		'stats-block',
		'delta',
		'gauge',
		'chart',
		// List / table widgets.
		'object-list',
		'table',
		// Object-context widgets (detail pages).
		'data',
		'related',
		'files',
		'metadata',
		// Integration / map.
		'integration',
		'map',
	];

	/**
	 * Execute the addWidget tool.
	 *
	 * @param array<string, mixed> $args Tool arguments (appSlug, versionSlug, pageId, widgetType, widgetConfig, widgetId, title).
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/ai-copilot/spec.md
	 */
	public function handle(array $args): array {
		$validation = $this->validateArgs(args: $args);
		if (isset($validation['error']) === true) {
			return $this->errorResult(error: 'invalid_arguments', message: $validation['error']);
		}

		$appSlug = $validation['appSlug'];
		$versionSlug = $validation['versionSlug'];
		$pageId = $validation['pageId'];
		$widgetType = $validation['widgetType'];
		$widgetConfig = $validation['widgetConfig'];

		$rbacError = $this->requireWriteRole(appSlug: $appSlug);
		if ($rbacError !== null) {
			return $rbacError;
		}

		try {
			// ADR-083 rule 1 / ADR-084: use the constructor-injected contract
			// rather than reaching into the container by string name.
			$objectService = $this->objectService;

			$loaded = $this->loadVersion(objectService: $objectService, appSlug: $appSlug, versionSlug: $versionSlug);
			if (isset($loaded['error']) === true) {
				return $this->errorResult(error: $loaded['error'], message: $loaded['message']);
			}

			$version = $loaded['version'];
			$manifest = (array)($version['manifest'] ?? []);
			$pages = (array)($manifest['pages'] ?? []);

			$foundIdx = $this->findPageIndex(pages: $pages, pageId: $pageId);
			if ($foundIdx === null) {
				return $this->errorResult(error: 'not_found', message: "Page '{$pageId}' not found in manifest.");
			}

			[$pages, $widget] = $this->appendWidget(
				pages: $pages,
				pageIdx: $foundIdx,
				widgetType: $widgetType,
				widgetConfig: $widgetConfig,
				widgetId: $validation['widgetId'],
				title: $validation['title']
			);

			$manifest['pages'] = array_values($pages);

			// H4: enforce widgets-per-page (50) and total manifest size (256 KB).
			$capError = $this->checkManifestCaps(manifest: $manifest, pageIdx: $foundIdx);
			if ($capError !== null) {
				return $capError;
			}

			$saved = $this->saveVersionManifest(objectService: $objectService, version: $version, manifest: $manifest);

			$pageConfig = (array)($pages[$foundIdx]['config'] ?? []);
			$widgetCount = count((array)($pageConfig['widgets'] ?? []));

			return [
				'success' => true,
				'added' => true,
				'widget' => $widget,
				'pageId' => $pageId,
				'widgetCount' => $widgetCount,
				'version' => [
					'uuid' => $this->extractUuid(item: $saved),
					'slug' => (string)($saved['slug'] ?? $versionSlug),
				],
			];
		} catch (\Throwable $e) {
			$this->logger->error(
				'Buildiq MCP: addWidget failed',
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
	 * @return array{appSlug?: string, versionSlug?: string, pageId?: string, widgetType?: string,
	 *               widgetConfig?: array, widgetId?: string, title?: string, error?: string}
	 */
	private function validateArgs(array $args): array {
		$appSlug = (string)($args['appSlug'] ?? '');
		$versionSlug = (string)($args['versionSlug'] ?? 'development');
		$pageId = (string)($args['pageId'] ?? '');
		$widgetType = (string)($args['widgetType'] ?? '');
		$widgetConfig = $args['widgetConfig'] ?? [];
		$widgetId = trim((string)($args['widgetId'] ?? ''));
		$title = trim((string)($args['title'] ?? ''));

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

		$identityError = $this->validateIdentity(widgetId: $widgetId, title: $title);
		if ($identityError !== null) {
			return ['error' => $identityError];
		}

		return [
			'appSlug' => $appSlug,
			'versionSlug' => $versionSlug,
			'pageId' => $pageId,
			'widgetType' => $widgetType,
			'widgetConfig' => $widgetConfig,
			'widgetId' => $widgetId,
			'title' => $title,
		];

	}//end validateArgs()

	/**
	 * Check the optional `widgetId` and `title` arguments.
	 *
	 * Both are optional. They are rejected rather than silently cleaned up when
	 * they are present but malformed: an id the caller meant to address later,
	 * quietly replaced by a derived one, is the kind of mismatch nobody finds
	 * until a delta fails to land.
	 *
	 * @param string $widgetId Caller-supplied widget id, already trimmed.
	 * @param string $title Caller-supplied widget title, already trimmed.
	 *
	 * @return string|null The rejection message, or null when both are usable.
	 */
	private function validateIdentity(string $widgetId, string $title): ?string {
		if ($widgetId !== '' && preg_match('/^[a-z0-9][a-z0-9-]*[a-z0-9]$/', $widgetId) !== 1) {
			return "Invalid widgetId '{$widgetId}'. Use lowercase letters, digits and hyphens.";
		}

		if (mb_strlen($title) > 80) {
			return 'title must be 80 characters or fewer.';
		}

		return null;
	}//end validateIdentity()

	/**
	 * Find the array index of a page by case-insensitive id matching.
	 *
	 * @param array<int, mixed> $pages Pages list from the manifest.
	 * @param string $pageId The page id to find.
	 *
	 * @return int|null Index of the found page, or null if not found.
	 */
	private function findPageIndex(array $pages, string $pageId): ?int {
		$pageIdLc = strtolower($pageId);
		foreach ($pages as $i => $existing) {
			if (is_array($existing) === true && strtolower((string)($existing['id'] ?? '')) === $pageIdLc) {
				return $i;
			}
		}

		return null;
	}//end findPageIndex()

	/**
	 * Append a widget to the target page's config.widgets array.
	 *
	 * @param array<int, mixed> $pages Pages list from the manifest.
	 * @param int $pageIdx Index of the target page.
	 * @param string $widgetType Widget type identifier.
	 * @param array $widgetConfig Widget-type-specific configuration blob.
	 * @param string $widgetId Caller-supplied widget id, or '' to derive one.
	 * @param string $title Caller-supplied widget title, or '' to derive one.
	 *
	 * @return array{0: array, 1: array{id: string, title: string, type: string}}
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ManifestWidgetShape is a pure shape
	 * builder with no collaborators and no state. Injecting it would put the
	 * same object on four constructors to satisfy a rule aimed at hidden
	 * service coupling, and this class has none to hide.
	 */
	private function appendWidget(
		array $pages,
		int $pageIdx,
		string $widgetType,
		array $widgetConfig,
		string $widgetId = '',
		string $title = '',
	): array {
		// Shared with CopilotService::applyAddWidget() — see ManifestWidgetShape
		// for why id, title and a layout row all come from one place.
		[$page, $widget] = ManifestWidgetShape::appendTo(
			page: (array)$pages[$pageIdx],
			widgetType: $widgetType,
			widgetConfig: $widgetConfig,
			widgetId: $widgetId,
			title: $title
		);
		$pages[$pageIdx] = $page;

		return [$pages, $widget];
	}//end appendWidget()
}//end class
