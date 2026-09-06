<?php

/**
 * Handler for the buildiq.getAppManifest MCP tool.
 *
 * Resolves a published virtual-app slug to its runtime manifest blob via the
 * built-app-route schema in the buildiq OR register.
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

/**
 * Handles the buildiq.getAppManifest tool invocation.
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-50
 */
class GetAppManifestHandler extends AbstractToolHandler {
	/**
	 * Execute the getAppManifest tool.
	 *
	 * @param array<string, mixed> $args Tool arguments (slug).
	 *
	 * @return array<string, mixed>
	 */
	public function handle(array $args): array {
		$slug = $args['slug'] ?? null;
		if ($slug === null || $slug === '') {
			return $this->errorResult(error: 'invalid_arguments', message: 'Required argument slug is missing.');
		}

		if ($this->isValidSlug(candidate: (string)$slug) === false) {
			return $this->errorResult(error: 'invalid_arguments', message: "Invalid slug '{$slug}'.");
		}

		if ($this->requireAuthenticatedUser() === null) {
			return $this->errorResult(error: 'forbidden', message: 'You must be signed in to read a virtual app manifest.');
		}

		try {
			// ADR-083 rule 1 / ADR-084: use the constructor-injected contract
			// rather than reaching into the container by string name.
			$objectService = $this->objectService;
			$resolved = $this->resolveApplicationBySlug(objectService: $objectService, slug: (string)$slug);
			if (isset($resolved['error']) === true) {
				return $this->errorResult(error: $resolved['error'], message: $resolved['message']);
			}

			$application = $resolved['application'];

			// C2 fix: per-app RBAC gate — caller must hold at least one role.
			$rbacError = $this->requireAnyRoleOnApp(app: $application);
			if ($rbacError !== null) {
				return $rbacError;
			}

			$manifest = ($application['manifest'] ?? null);
			if (is_array(value: $manifest) === false) {
				return $this->errorResult(error: 'no_manifest', message: 'Application has no manifest.');
			}

			// C2 fix: strip the permissions roster before returning to the caller.
			// The internal access-control block MUST NOT leak to MCP consumers.
			unset($manifest['permissions']);

			$name = (string)($application['name'] ?? $slug);
			return [
				'success' => true,
				'slug' => (string)$slug,
				'name' => $name,
				'manifest' => $manifest,
				'sources' => [$this->sourceDescriptor(uuid: $this->extractUuid(item: $application), slug: (string)$slug, label: $name)],
			];
		} catch (\Throwable $e) {
			$this->logger->error(
				'Buildiq MCP: getAppManifest failed',
				['slug' => $slug, 'exception' => $e->getMessage()]
			);
			return $this->errorResult(error: 'internal_error', message: 'Failed to resolve manifest.');
		}//end try

	}//end handle()

	/**
	 * Resolve a published-app slug to its underlying Application object via the built-app-route schema.
	 *
	 * @param object $objectService OpenRegister ObjectService instance used for slug lookups.
	 * @param string $slug Public route slug of the published virtual app.
	 *
	 * @return array{application?: array<string, mixed>, error?: string, message?: string}
	 */
	private function resolveApplicationBySlug(object $objectService, string $slug): array {
		$routeResults = $objectService->searchObjectsBySlug(
			self::REGISTER_SLUG,
			'built-app-route',
			['slug' => $slug],
			_rbac: true,
			_multitenancy: false
		);
		if (is_array($routeResults) === false || $routeResults === []) {
			return ['error' => 'not_found', 'message' => "No published virtual app found for slug '{$slug}'."];
		}

		$route = $this->toArray(item: $routeResults[0]);
		$applicationUuid = ($route['applicationUuid'] ?? null);
		if ($applicationUuid === null || $applicationUuid === '') {
			return ['error' => 'inconsistent_state', 'message' => 'Route exists but has no applicationUuid.'];
		}

		$application = $objectService->find(id: (string)$applicationUuid, register: self::REGISTER_SLUG, schema: 'built-app');
		if ($application === null) {
			return ['error' => 'inconsistent_state', 'message' => 'Route points to an Application that does not exist.'];
		}

		return ['application' => $this->toArray(item: $application)];
	}//end resolveApplicationBySlug()
}//end class
