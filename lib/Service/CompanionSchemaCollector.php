<?php

/**
 * Buildiq CompanionSchemaCollector
 *
 * Reads the schemas of the register an app version publishes, in the blob shape
 * the app-repo `schemas/<slug>.json` files carry. Split out of
 * AppRepoSerializer, which had grown past the class-length budget and which
 * this concern reaches through one method.
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
 * @spec openspec/changes/github-app-repo-format/specs/github-app-repo-format/spec.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Collects an app version's companion schemas for publication.
 *
 * @spec openspec/changes/github-app-repo-format/specs/github-app-repo-format/spec.md
 */
class CompanionSchemaCollector {
	/**
	 * Constructor.
	 *
	 * @param RegisterMapper $registerMapper Resolves a register by slug.
	 * @param SchemaMapper $schemaMapper Resolves schema definitions by id.
	 * @param LoggerInterface $logger PSR logger (server-side diagnostics only).
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
	 * Read the app's register companion schemas, keyed by schema slug.
	 *
	 * The register is the one the published ApplicationVersion names. The
	 * creation wizard gives every version its own register,
	 * `openbuild-{slug}-{version}`, so `openbuild-{slug}` exists for no
	 * wizard-made app: deriving the name from the app slug alone found nothing
	 * and published a repository whose `schemas/` was empty, with no error to
	 * see. `openbuild-{slug}` stays as a fallback for the older records that
	 * carry no `register`.
	 *
	 * Mirrors DataRegisterExportBundler's schema resolution; a register that is
	 * absent (an app never provisioned one) yields no companions rather than an
	 * error, because serialisation is total.
	 *
	 * @param string $slug The Application slug.
	 * @param string $versionRegister The register named by the version being published.
	 *
	 * @return array<string,array<string,mixed>> Schema blobs keyed by slug.
	 *
	 * @spec openspec/changes/github-app-repo-format/specs/github-app-repo-format/spec.md
	 */
	public function collect(string $slug, string $versionRegister = ''): array {
		$register = $this->findRegister(slug: $slug, versionRegister: $versionRegister);
		if ($register === null) {
			return [];
		}

		$schemas = [];
		foreach ((array)$register->getSchemas() as $schemaId) {
			try {
				$schema = $this->schemaMapper->find($schemaId, _multitenancy: false);
			} catch (Throwable $e) {
				$this->logger->debug(
					'Buildiq CompanionSchemaCollector: could not resolve schema '
					. ((string)$schemaId) . ': ' . $e->getMessage()
				);
				continue;
			}

			$schemaSlug = $schema->getSlug();
			if ($schemaSlug === '') {
				continue;
			}

			$version = (string)$schema->getVersion();
			if ($version === '') {
				$version = '0.1.0';
			}

			$schemas[$schemaSlug] = [
				'slug' => $schemaSlug,
				'title' => (string)$schema->getTitle(),
				'description' => (string)$schema->getDescription(),
				'version' => $version,
				'type' => 'object',
				'required' => array_values((array)$schema->getRequired()),
				'properties' => (array)$schema->getProperties(),
			];
		}//end foreach

		return $schemas;
	}//end collect()

	/**
	 * Resolve the register the schemas live in: the one the version names,
	 * else the name derived from the app slug.
	 *
	 * @param string $slug The Application slug.
	 * @param string $versionRegister The register named by the version being published.
	 *
	 * @return object|null The register entity, or null when no candidate resolves.
	 *
	 * @spec openspec/changes/github-app-repo-format/specs/github-app-repo-format/spec.md
	 */
	private function findRegister(string $slug, string $versionRegister): ?object {
		$derived = '';
		if ($slug !== '') {
			$derived = 'openbuild-' . $slug;
		}

		foreach (array_unique(array_filter([$versionRegister, $derived])) as $candidate) {
			try {
				return $this->registerMapper->find($candidate, _multitenancy: false);
			} catch (Throwable $e) {
				$this->logger->debug(
					'Buildiq CompanionSchemaCollector: no register "' . $candidate . '": ' . $e->getMessage()
				);
			}
		}

		return null;
	}//end findRegister()
}//end class
