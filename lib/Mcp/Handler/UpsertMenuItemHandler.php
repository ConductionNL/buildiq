<?php

/**
 * Handler for the openbuild.upsertMenuItem MCP tool.
 *
 * Creates or updates a top-level menu item in the draft manifest of an
 * ApplicationVersion. If an item with the given id already exists it is
 * replaced in-place; otherwise the new item is appended.
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
 * Handles the openbuild.upsertMenuItem tool invocation.
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
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

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
				'OpenBuild MCP: upsertMenuItem failed',
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
	 */
	private function validateArgs(array $args): array {
		$appSlug = (string)($args['appSlug'] ?? '');
		$versionSlug = (string)($args['versionSlug'] ?? 'development');
		$id = (string)($args['id'] ?? '');
		$label = (string)($args['label'] ?? '');
		$icon = (string)($args['icon'] ?? '');
		$route = (string)($args['route'] ?? '');
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

		// Validate route to a safe path pattern (issue #167 — route injection guard).
		if ($this->isValidRoute(route: $route) === false) {
			return [
				'error' => "Invalid route '{$route}'. "
				. "Routes must start with '/' and contain only safe path characters.",
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
	 * Validate a route value against a safe path pattern.
	 *
	 * Accepts paths that start with '/' and consist only of alphanumeric
	 * characters, hyphens, underscores, dots, forward slashes, and route
	 * parameter placeholders (:param or {param}). Rejects javascript: URIs,
	 * protocol-relative paths, and other injection vectors (issue #167).
	 *
	 * @param string $route The candidate route string.
	 *
	 * @return bool
	 */
	private function isValidRoute(string $route): bool {
		if (strlen($route) > 256) {
			return false;
		}

		// Require the character after the leading '/' to be non-slash so that
		// protocol-relative URLs (//host/path) are rejected.
		return (bool)preg_match('#^/([a-zA-Z0-9_\-\.:\{][a-zA-Z0-9/_\-\.:\{\}]*)?$#', $route);
	}//end isValidRoute()

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
