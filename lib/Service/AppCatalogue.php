<?php

/**
 * AppCatalogue
 *
 * Reads the apps on this instance and their versions for the command line. It
 * runs in system context (an occ command has no signed-in user), so it reads
 * every app, the way an administrator with shell access sees them.
 *
 * @category  Service
 * @package   OCA\Buildiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/buildiq
 *
 * @spec openspec/changes/lifecycle-import-app-and-cli/specs/app-management-cli/spec.md#requirement-list-apps-from-the-command-line-req-bqic-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\Buildiq\Kernel\Contracts\AppCatalogueInterface;
use OCA\Buildiq\Support\ObjectRecord;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * The apps and their versions, read for the command line.
 *
 * @category Service
 * @package  OCA\Buildiq\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @spec openspec/changes/lifecycle-import-app-and-cli/specs/app-management-cli/spec.md#requirement-list-apps-from-the-command-line-req-bqic-004
 */
class AppCatalogue implements AppCatalogueInterface {

	/**
	 * The shared buildiq register.
	 *
	 * @var string
	 */
	public const REGISTER_SLUG = 'buildiq';

	/**
	 * The Application schema.
	 *
	 * @var string
	 */
	public const APPLICATION_SCHEMA = 'built-app';

	/**
	 * The ApplicationVersion schema.
	 *
	 * @var string
	 */
	public const VERSION_SCHEMA = 'applicationVersion';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Every app, or the one with the given slug, sorted by slug.
	 *
	 * @param string|null $slug Only this app, when given.
	 *
	 * @return array<int,array<string,mixed>> The apps.
	 *
	 * @spec openspec/changes/lifecycle-import-app-and-cli/specs/app-management-cli/spec.md#requirement-list-apps-from-the-command-line-req-bqic-004
	 */
	public function apps(?string $slug = null): array {
		$filters = [];
		if ($slug !== null) {
			$filters['slug'] = $slug;
		}

		$apps = [];
		foreach ($this->search(schema: self::APPLICATION_SCHEMA, filters: $filters) as $app) {
			// The slug filter is re-checked: OpenRegister's filter is not always exact.
			if ($slug === null || (string)($app['slug'] ?? '') === $slug) {
				$apps[] = $app;
			}
		}

		usort($apps, static fn (array $left, array $right): int => strcmp((string)($left['slug'] ?? ''), (string)($right['slug'] ?? '')));
		return $apps;
	}//end apps()

	/**
	 * The app with the given slug, or null.
	 *
	 * @param string $slug The app slug.
	 *
	 * @return array<string,mixed>|null The app.
	 *
	 * @spec openspec/changes/lifecycle-import-app-and-cli/specs/app-management-cli/spec.md#requirement-delete-an-app-from-the-command-line-req-bqic-007
	 */
	public function app(string $slug): ?array {
		return ($this->apps(slug: $slug)[0] ?? null);
	}//end app()

	/**
	 * The versions of an app, each with its slug, semver and status.
	 *
	 * @param string $appUuid The app's uuid.
	 *
	 * @return array<int,array{slug:string,semver:string,status:string}> The versions, sorted by slug.
	 *
	 * @spec openspec/changes/lifecycle-import-app-and-cli/specs/app-management-cli/spec.md#requirement-list-apps-from-the-command-line-req-bqic-004
	 */
	public function versions(string $appUuid): array {
		$versions = [];
		foreach ($this->search(schema: self::VERSION_SCHEMA, filters: ['application' => $appUuid]) as $version) {
			if ((string)($version['application'] ?? '') !== $appUuid) {
				continue;
			}

			$versions[] = [
				'slug' => (string)($version['slug'] ?? ''),
				'semver' => (string)($version['semver'] ?? ''),
				'status' => (string)($version['status'] ?? ''),
			];
		}

		usort($versions, static fn (array $left, array $right): int => strcmp($left['slug'], $right['slug']));
		return $versions;
	}//end versions()

	/**
	 * The objects of one schema in the buildiq register, serialised.
	 *
	 * @param string              $schema  The schema slug.
	 * @param array<string,mixed> $filters The filters.
	 *
	 * @return array<int,array<string,mixed>> The objects.
	 */
	private function search(string $schema, array $filters): array {
		// System context: the command line reads every app, not one user's.
		$results = $this->objectService->searchObjectsBySlug(
			self::REGISTER_SLUG,
			$schema,
			$filters,
			_rbac: false,
			_multitenancy: false
		);
		if (is_array($results) === false) {
			return [];
		}

		return array_map(static fn (mixed $result): array => ObjectRecord::serialised(object: $result), array_values($results));
	}//end search()
}//end class
