<?php

/**
 * Handler for the buildiq.upsertPage MCP tool.
 *
 * Creates or updates a page entry in the draft manifest of an ApplicationVersion.
 * The lookup uses case-insensitive page id matching so the LLM does not need to
 * remember exact casing.
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

use OCA\Buildiq\Support\ManifestEntryList;
use OCA\Buildiq\Support\ManifestPageShape;
use OCA\Buildiq\Support\ManifestRoute;

/**
 * Handles the buildiq.upsertPage tool invocation.
 */
class UpsertPageHandler extends AbstractToolHandler {

	private const PAGE_TYPES = ['dashboard', 'index', 'detail', 'form'];

	/**
	 * Execute the upsertPage tool.
	 *
	 * @param array<string, mixed> $args Tool arguments (appSlug, versionSlug, pageId, title, type, route, config).
	 *
	 * @return array<string, mixed>
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ManifestPageShape is a pure shape
	 * builder with no collaborators and no state.
	 */
	public function handle(array $args): array {
		$validation = $this->validateArgs(args: $args);
		if (isset($validation['error']) === true) {
			return $this->errorResult(error: 'invalid_arguments', message: $validation['error']);
		}

		$appSlug = $validation['appSlug'];
		$versionSlug = $validation['versionSlug'];
		$pageId = $validation['pageId'];

		$rbacError = $this->requireWriteRole(appSlug: $appSlug);
		if ($rbacError !== null) {
			return $rbacError;
		}

		try {
			$loaded = $this->loadManifestForEdit(appSlug: $appSlug, versionSlug: $versionSlug);
			if (isset($loaded['isError']) === true) {
				return $loaded;
			}

			$manifest = $loaded['manifest'];
			$pages = (array)($manifest['pages'] ?? []);

			// Shared with CopilotService::applyUpsertPage() so the page reviewed
			// and the page stored are the same one. See ManifestPageShape for
			// the per-type fields the canonical validator insists on.
			$newPage = ManifestPageShape::normalise(page: [
				'id' => $pageId,
				'route' => $validation['route'],
				'type' => $validation['type'],
				'title' => $validation['title'],
				'config' => $validation['config'],
			]);

			[$pages, $replaced] = $this->upsertPageInList(pages: $pages, newPage: $newPage);

			$manifest['pages'] = array_values($pages);

			// H4: enforce pages-per-manifest (100) and total manifest size (256 KB).
			// Cap is applied after upsert so updates to existing pages always pass.
			$capError = $this->appendCapError(manifest: $manifest, replaced: $replaced);
			if ($capError !== null) {
				return $capError;
			}

			$saved = $this->saveVersionManifest(objectService: $this->objectService, version: $loaded['version'], manifest: $manifest);

			return $this->upsertOutcome(replaced: $replaced) + [
				'page' => $newPage,
				'pageCount' => count($pages),
				'version' => $this->versionSummary(saved: $saved, versionSlug: $versionSlug),
			];
		} catch (\Throwable $e) {
			$this->logger->error(
				'Buildiq MCP: upsertPage failed',
				['appSlug' => $appSlug, 'pageId' => $pageId, 'exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]
			);
			return $this->errorResult(error: 'upsert_failed', message: 'Failed to upsert page. See server logs for details.');
		}//end try

	}//end handle()

	/**
	 * Validate and extract typed arguments for upsertPage.
	 *
	 * @param array<string, mixed> $args Raw tool arguments.
	 *
	 * @return array<string, mixed>
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ManifestRoute is a pure rule with
	 * no collaborators and no state.
	 */
	private function validateArgs(array $args): array {
		$appSlug = (string)($args['appSlug'] ?? '');
		$versionSlug = (string)($args['versionSlug'] ?? 'development');
		$pageId = (string)($args['pageId'] ?? '');
		$title = (string)($args['title'] ?? '');
		$type = (string)($args['type'] ?? '');
		// A route written as a bare page id is rooted rather than refused;
		// see ManifestRoute for the shapes that stay refused.
		$route = ManifestRoute::normalise(route: (string)($args['route'] ?? ''));
		$config = $args['config'] ?? [];

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

		// Validate route to a safe path pattern: must start with '/' and contain
		// only path-safe characters (issue #167 — route injection guard).
		if (ManifestRoute::isValid(route: $route) === false) {
			return [
				'error' => "Invalid route '{$route}'. "
				. "Routes must start with '/' and contain only safe path characters.",
			];
		}

		if (is_array($config) === false) {
			$config = [];
		}

		return [
			'appSlug' => $appSlug,
			'versionSlug' => $versionSlug,
			'pageId' => $pageId,
			'title' => $title,
			'type' => $type,
			'route' => $route,
			'config' => $config,
		];

	}//end validateArgs()

	/**
	 * Upsert a page into the pages list using case-insensitive id matching ({@see ManifestEntryList}).
	 *
	 * Returns the updated pages array and a boolean indicating whether an existing
	 * page was replaced (true) or a new page was appended (false).
	 *
	 * @param array<int, mixed> $pages Existing pages list from the manifest.
	 * @param array<string, mixed> $newPage The page definition to insert or replace with.
	 *
	 * @return array{0: array, 1: bool}
	 */
	private function upsertPageInList(array $pages, array $newPage): array {
		$list = new ManifestEntryList(entries: $pages);
		$replaced = $list->upsert(entry: $newPage);

		return [$list->entries(), $replaced];
	}//end upsertPageInList()
}//end class
