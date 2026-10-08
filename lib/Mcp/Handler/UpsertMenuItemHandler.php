<?php

/**
 * Handler for the buildiq.upsertMenuItem MCP tool.
 *
 * Creates or updates a top-level menu item in the draft manifest of an
 * ApplicationVersion. If an item with the given id already exists it is
 * replaced in-place; otherwise the new item is appended.
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

use OCA\Buildiq\Support\ManifestRoute;

/**
 * Handles the buildiq.upsertMenuItem tool invocation.
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-42
 */
class UpsertMenuItemHandler extends AbstractToolHandler {
	/**
	 * Execute the upsertMenuItem tool.
	 *
	 * @param array<string, mixed> $args Tool arguments (appSlug, versionSlug, id, label, icon, route, order).
	 *
	 * @return array<string, mixed>
	 */
	public function handle(array $args): array {
		$validation = $this->validateArgs(args: $args);
		if (isset($validation['error']) === true) {
			return $this->errorResult(error: 'invalid_arguments', message: $validation['error']);
		}

		$appSlug = $validation['appSlug'];
		$versionSlug = $validation['versionSlug'];
		$id = $validation['id'];

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
			$menu = (array)($manifest['menu'] ?? []);

			$newItem = [
				'id' => $id,
				'label' => $validation['label'],
				'icon' => $validation['icon'],
				'route' => $validation['route'],
				'order' => $validation['order'],
			];

			[$menu, $replaced] = $this->upsertMenuItemInList(menu: $menu, itemId: $id, newItem: $newItem);

			$manifest['menu'] = array_values($menu);

			// H4: enforce menu-per-manifest (30) and total manifest size (256 KB).
			// Cap is applied after upsert so updates to existing items always pass.
			if ($replaced === false) {
				$capError = $this->checkManifestCaps(manifest: $manifest);
				if ($capError !== null) {
					return $capError;
				}
			}

			$saved = $this->saveVersionManifest(objectService: $objectService, version: $version, manifest: $manifest);

			$action = 'created';
			if ($replaced === true) {
				$action = 'updated';
			}

			return [
				'success' => true,
				'action' => $action,
				'menuItem' => $newItem,
				'menuCount' => count($menu),
				'version' => [
					'uuid' => $this->extractUuid(item: $saved),
					'slug' => (string)($saved['slug'] ?? $versionSlug),
				],
			];
		} catch (\Throwable $e) {
			$this->logger->error(
				'Buildiq MCP: upsertMenuItem failed',
				['appSlug' => $appSlug, 'id' => $id, 'exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]
			);
			return $this->errorResult(error: 'upsert_failed', message: 'Failed to upsert menu item. See server logs for details.');
		}//end try

	}//end handle()

	/**
	 * Validate and extract typed arguments for upsertMenuItem.
	 *
	 * @param array<string, mixed> $args Raw tool arguments.
	 *
	 * @return array{appSlug?: string, versionSlug?: string, id?: string, label?: string, icon?: string, route?: string, order?: int, error?: string}
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ManifestRoute is a pure rule with
	 * no collaborators and no state.
	 */
	private function validateArgs(array $args): array {
		$appSlug = (string)($args['appSlug'] ?? '');
		$versionSlug = (string)($args['versionSlug'] ?? 'development');
		$id = (string)($args['id'] ?? '');
		$label = (string)($args['label'] ?? '');
		$icon = (string)($args['icon'] ?? '');
		$route = trim((string)($args['route'] ?? ''));
		$order = 100;
		if (isset($args['order']) === true) {
			$order = (int)$args['order'];
		}

		if ($appSlug === '' || $this->isValidSlug(candidate: $appSlug) === false) {
			return ['error' => "Invalid appSlug '{$appSlug}'."];
		}

		if ($id === '') {
			return ['error' => 'id is required.'];
		}

		if ($label === '') {
			return ['error' => 'label is required.'];
		}

		if ($route === '') {
			return ['error' => 'route is required.'];
		}

		// A menu item targets a route by NAME, and the runtime names every route
		// after its page id, so a bare page id is the canonical value here and a
		// path is accepted too. Only a scheme or a host is refused, which is what
		// the injection guard in issue #167 was actually for.
		if (ManifestRoute::isValidMenuTarget(route: $route) === false) {
			return [
				'error' => "Invalid route '{$route}'. "
				. 'A menu route is a page id, or a path starting with \'/\', '
				. 'and may contain only safe path characters.',
			];
		}

		return [
			'appSlug' => $appSlug,
			'versionSlug' => $versionSlug,
			'id' => $id,
			'label' => $label,
			'icon' => $icon,
			'route' => $route,
			'order' => $order,
		];

	}//end validateArgs()

	/**
	 * Upsert a menu item in the menu list using case-insensitive id matching.
	 *
	 * Uses the same case-insensitive strategy as UpsertPageHandler so that an
	 * LLM can reliably target an existing item regardless of case variations
	 * (issue #166 — earlier code used strict equality which diverged from pages).
	 *
	 * Returns the updated menu array and a boolean indicating whether an existing
	 * item was replaced (true) or a new item was appended (false).
	 *
	 * @param array<int, mixed> $menu Existing menu list from the manifest.
	 * @param string $itemId The menu item id to look up (case-insensitive).
	 * @param array<string, mixed> $newItem The menu item definition to insert or replace with.
	 *
	 * @return array{0: array, 1: bool}
	 */
	private function upsertMenuItemInList(array $menu, string $itemId, array $newItem): array {
		$replaced = false;
		$itemIdLc = strtolower($itemId);

		foreach ($menu as $i => $existing) {
			if (is_array($existing) === true && strtolower((string)($existing['id'] ?? '')) === $itemIdLc) {
				$menu[$i] = $newItem;
				$replaced = true;
				break;
			}
		}

		if ($replaced === false) {
			$menu[] = $newItem;
		}

		return [$menu, $replaced];
	}//end upsertMenuItemInList()
}//end class
