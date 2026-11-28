<?php

/**
 * Buildiq VersionSchemaLocator
 *
 * Finds an application version's schemas that its register no longer lists, so
 * deleting the app with its data removes them too.
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
 * @spec openspec/specs/application-detail-ui/spec.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Locates detached version schemas by their namespaced slug.
 *
 * @spec openspec/specs/application-detail-ui/spec.md
 */
class VersionSchemaLocator {
	/**
	 * Constructor.
	 *
	 * @param RegisterMapper $registerMapper Register lookup
	 * @param SchemaMapper $schemaMapper Schema lookup
	 * @param LoggerInterface $logger PSR logger
	 *
	 * @return void
	 */
	public function __construct(
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Find this app's version schemas that no register lists any more.
	 *
	 * Each version owns schemas named `{app}-{version}-{name}`. An earlier
	 * promotion bug handed the target register the source's schema ids, which
	 * left the target's own schemas attached to no register at all. Walking the
	 * registers' schema lists cannot see those, so "delete all data" left them
	 * behind. This derives every `{name}` the app's registers hold, and looks up
	 * `{app}-{version}-{name}` for every version, so a detached schema is still
	 * found and cleaned up with its version.
	 *
	 * @param string $appSlug The application slug.
	 * @param array<int,array<string,mixed>> $versions The app's version rows.
	 *
	 * @return array<string,array<int,string>> Version slug => schema ids its register does not list.
	 *
	 * @spec openspec/specs/application-detail-ui/spec.md
	 */
	public function findDetachedSchemaIds(string $appSlug, array $versions): array {
		[$versionSlugs, $listed] = $this->listedSchemas(versions: $versions);
		if ($appSlug === '' || $versionSlugs === [] || $listed === []) {
			return [];
		}

		$candidates = [];
		$names = $this->schemaNames(appSlug: $appSlug, versionSlugs: $versionSlugs, schemaIds: array_keys($listed));
		foreach ($names as $name) {
			foreach ($versionSlugs as $versionSlug) {
				$candidates[strtolower($appSlug . '-' . $versionSlug . '-' . $name)] = $versionSlug;
			}
		}

		if ($candidates === []) {
			return [];
		}

		try {
			$found = $this->schemaMapper->findIdsBySlugs(array_keys($candidates));
		} catch (Throwable $e) {
			$this->logger->warning(
				'Buildiq: could not look up detached version schemas of {slug}: {message}',
				['slug' => $appSlug, 'message' => $e->getMessage()]
			);
			return [];
		}

		return $this->unlistedByVersion(found: $found, candidates: $candidates, listed: $listed);
	}//end findDetachedSchemaIds()

	/**
	 * Group the found schema ids no register lists by their version.
	 *
	 * @param array<string,mixed> $found Lower-cased slug => ids.
	 * @param array<string,string> $candidates Lower-cased slug => version slug.
	 * @param array<string,bool> $listed Schema ids the registers list.
	 *
	 * @return array<string,array<int,string>> Version slug => schema ids.
	 */
	private function unlistedByVersion(array $found, array $candidates, array $listed): array {
		$detached = [];
		foreach ($found as $slug => $ids) {
			$versionSlug = ($candidates[strtolower((string)$slug)] ?? null);
			foreach ((array)$ids as $id) {
				if ($versionSlug !== null && isset($listed[(string)$id]) === false) {
					$detached[$versionSlug][] = (string)$id;
				}
			}
		}

		return $detached;
	}//end unlistedByVersion()

	/**
	 * The app's version slugs and every schema id their registers list.
	 *
	 * @param array<int,array<string,mixed>> $versions The app's version rows.
	 *
	 * @return array{0: array<int,string>, 1: array<string,bool>} Version slugs, listed schema ids.
	 */
	private function listedSchemas(array $versions): array {
		$versionSlugs = [];
		$listed = [];
		foreach ($versions as $version) {
			$versionSlug = (string)($version['slug'] ?? '');
			$registerSlug = (string)($version['register'] ?? '');
			if ($versionSlug === '' || $registerSlug === '') {
				continue;
			}

			$versionSlugs[] = $versionSlug;
			try {
				$register = $this->registerMapper->find($registerSlug, _multitenancy: false);
			} catch (Throwable $e) {
				continue;
			}

			foreach (($register->getSchemas() ?? []) as $schemaId) {
				$listed[(string)$schemaId] = true;
			}
		}

		return [$versionSlugs, $listed];
	}//end listedSchemas()

	/**
	 * The `{name}` parts of the listed schemas named `{app}-{version}-{name}`.
	 *
	 * @param string $appSlug The application slug.
	 * @param array<int,string> $versionSlugs The app's version slugs.
	 * @param array<int,int|string> $schemaIds The listed schema ids.
	 *
	 * @return array<int,string> Unique names.
	 */
	private function schemaNames(string $appSlug, array $versionSlugs, array $schemaIds): array {
		$names = [];
		foreach ($schemaIds as $schemaId) {
			try {
				$slug = (string)$this->schemaMapper->find(id: $schemaId, _rbac: false, _multitenancy: false)->getSlug();
			} catch (Throwable $e) {
				continue;
			}

			foreach ($versionSlugs as $versionSlug) {
				$prefix = $appSlug . '-' . $versionSlug . '-';
				if (str_starts_with($slug, $prefix) === true && strlen($slug) > strlen($prefix)) {
					$names[substr($slug, strlen($prefix))] = true;
				}
			}
		}

		return array_keys($names);
	}//end schemaNames()
}//end class
