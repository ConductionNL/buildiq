<?php

/**
 * Buildiq ManifestBaseResolver
 *
 * Reads the base manifest a delta-mode ApplicationVersion extends, for the two
 * base kinds that are not another built app: an `ApplicationTemplate` (by
 * slug) and an installed fleet app's bundled manifest (by app id). A built-app
 * base is resolved by ManifestResolverService itself, because it is that
 * app's own resolved manifest (app-delta-override D3).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Buildiq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/app-delta-override/tasks.md#task-3.1
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\Buildiq\Support\ObjectRecord;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Template and fleet-app base manifests; null whenever a base cannot be read.
 *
 * @spec openspec/changes/app-delta-override/tasks.md#task-3.1
 */
class ManifestBaseResolver {

	/**
	 * The schema slug of an application template.
	 *
	 * @var string
	 */
	public const TEMPLATE_SCHEMA = 'application-template';

	/**
	 * Where an installed fleet app keeps the manifest it bundles.
	 *
	 * @var string
	 */
	public const BUNDLED_MANIFEST = 'src/manifest.json';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService  OpenRegister object service (ADR-022)
	 * @param RegisterMapper         $registerMapper Register slug-to-ID resolver
	 * @param SchemaMapper           $schemaMapper   Schema slug-to-ID resolver
	 * @param IAppManager            $appManager     Finds an installed fleet app
	 * @param LoggerInterface        $logger         PSR logger for diagnostics
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The manifest of the application template with this slug, or null.
	 *
	 * @param string $slug The template slug.
	 *
	 * @return array<string, mixed>|null The template's manifest.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectRecord is a pure coercion with no collaborators.
	 *
	 * @spec openspec/changes/app-delta-override/tasks.md#task-3.1
	 */
	public function template(string $slug): ?array {
		if ($slug === '') {
			return null;
		}

		try {
			$registerId = $this->registerMapper->find(ApplicationVersionService::REGISTER_SLUG, _multitenancy: false)->getId();
			$schemaId = $this->schemaMapper->find(self::TEMPLATE_SCHEMA, _multitenancy: false)->getId();
			$results = $this->objectService->searchObjects(
				query: ['@self' => ['register' => $registerId, 'schema' => $schemaId], 'slug' => $slug]
			);
		} catch (Throwable $e) {
			$this->logger->warning('ManifestBaseResolver: template {slug} could not be read: {message}', ['slug' => $slug, 'message' => $e->getMessage()]);
			return null;
		}

		foreach ($results as $result) {
			$template = ObjectRecord::toArray(object: $result);
			if ((string)($template['slug'] ?? '') === $slug && is_array($template['manifest'] ?? null) === true) {
				return $template['manifest'];
			}
		}

		return null;
	}//end template()

	/**
	 * The bundled manifest of the installed fleet app with this id, or null.
	 *
	 * Only an installed and enabled app is read, and only its own
	 * `src/manifest.json`: the id is checked against the app id grammar first,
	 * so it can never name a path.
	 *
	 * @param string $appId The Nextcloud app id.
	 *
	 * @return array<string, mixed>|null The bundled manifest.
	 *
	 * @spec openspec/changes/app-delta-override/tasks.md#task-3.1
	 */
	public function fleetApp(string $appId): ?array {
		if (preg_match('/^[a-z][a-z0-9_]{1,63}$/', $appId) !== 1 || $this->appManager->isEnabledForAnyone($appId) === false) {
			return null;
		}

		try {
			$path = rtrim($this->appManager->getAppPath($appId), '/') . '/' . self::BUNDLED_MANIFEST;
		} catch (Throwable $e) {
			return null;
		}

		$json = null;
		if (is_file($path) === true) {
			$json = file_get_contents($path);
		}

		if (is_string($json) === false) {
			return null;
		}

		$manifest = json_decode($json, true);
		if (is_array($manifest) === false) {
			$this->logger->warning('ManifestBaseResolver: the bundled manifest of {app} is not JSON', ['app' => $appId]);
			return null;
		}

		return $manifest;
	}//end fleetApp()
}//end class
