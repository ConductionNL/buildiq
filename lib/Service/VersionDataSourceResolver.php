<?php

/**
 * Buildiq VersionDataSourceResolver
 *
 * Finds the (register, schema) pairs that hold an application version's data,
 * for the insights KPIs and the per-schema object counts.
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
 * @spec openspec/specs/application-insights/spec.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use Throwable;

/**
 * Resolves where a version's data lives.
 *
 * @spec openspec/specs/application-insights/spec.md
 */
class VersionDataSourceResolver {
	/**
	 * Constructor.
	 *
	 * @param RegisterMapper $registerMapper Register lookup
	 * @param SchemaMapper $schemaMapper Schema lookup
	 *
	 * @return void
	 */
	public function __construct(
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
	) {
	}//end __construct()

	/**
	 * The (register, schema) pairs that hold this version's data.
	 *
	 * The KPIs used to count only manifest pages whose register equals the
	 * version's register. That read 0 whenever the pages pointed elsewhere
	 * (Hello World keeps its messages in the `buildiq` register) and ignored
	 * every schema in the version's register that no page names yet.
	 *
	 * Now: every schema the version's register lists, plus the schemas its
	 * pages name in that register. A version without a register of its own
	 * keeps its data where its pages say, so then every page's register and
	 * schema counts.
	 *
	 * @param array<string, mixed>|null $manifest The version's manifest.
	 * @param string $registerSlug The version's register slug.
	 * @param array<int, mixed> $pageSchemaRefs Schemas the pages name in the version's register.
	 *
	 * @return array<int, array{register: string, schemaId: int, schemaSlug: string}> Unique data sources.
	 *
	 * @spec openspec/specs/application-insights/spec.md
	 */
	public function resolve(?array $manifest, string $registerSlug, array $pageSchemaRefs): array {
		try {
			$ownRegister = $this->registerMapper->find($registerSlug, _multitenancy: false);
			$pairs = $this->ownRegisterPairs(
				schemaRefs: (array)($ownRegister->getSchemas() ?? []),
				pageSchemaRefs: $pageSchemaRefs,
				registerSlug: $registerSlug
			);
		} catch (Throwable $e) {
			$pairs = $this->pagePairs(manifest: $manifest);
		}

		$sources = [];
		foreach ($pairs as [$register, $schemaRef]) {
			try {
				$schema = $this->schemaMapper->find($schemaRef, _multitenancy: false);
			} catch (Throwable $e) {
				continue;
			}

			$schemaId = (int)$schema->getId();
			$key = $register . '#' . $schemaId;
			if ($schemaId === 0 || isset($sources[$key]) === true) {
				continue;
			}

			$sources[$key] = [
				'register' => $register,
				'schemaId' => $schemaId,
				'schemaSlug' => (string)$schema->getSlug(),
			];
		}

		return array_values($sources);
	}//end resolve()

	/**
	 * Data sources of a version that has its own register.
	 *
	 * @param array<int, mixed> $schemaRefs The schemas the register lists.
	 * @param array<int, mixed> $pageSchemaRefs Schemas the pages name in that register.
	 * @param string $registerSlug The version's register slug.
	 *
	 * @return array<int, array{0: string, 1: string}> (register, schema ref) pairs.
	 *
	 * @spec openspec/specs/application-insights/spec.md
	 */
	private function ownRegisterPairs(array $schemaRefs, array $pageSchemaRefs, string $registerSlug): array {
		$pairs = [];
		$refs = array_merge($schemaRefs, $pageSchemaRefs);
		foreach ($refs as $schemaRef) {
			$pairs[] = [$registerSlug, (string)$schemaRef];
		}

		return $pairs;
	}//end ownRegisterPairs()

	/**
	 * Data sources of a version without a register: wherever its pages point.
	 *
	 * @param array<string, mixed>|null $manifest The version's manifest.
	 *
	 * @return array<int, array{0: string, 1: string}> (register, schema ref) pairs.
	 *
	 * @spec openspec/specs/application-insights/spec.md
	 */
	private function pagePairs(?array $manifest): array {
		$pairs = [];
		foreach ((array)($manifest['pages'] ?? []) as $page) {
			$config = [];
			if (is_array($page) === true && is_array($page['config'] ?? null) === true) {
				$config = $page['config'];
			}

			$register = ($config['register'] ?? '');
			$schema = ($config['schema'] ?? '');
			if (is_string($register) === true && $register !== '' && is_scalar($schema) === true && (string)$schema !== '') {
				$pairs[] = [$register, (string)$schema];
			}
		}

		return $pairs;
	}//end pagePairs()
}//end class
