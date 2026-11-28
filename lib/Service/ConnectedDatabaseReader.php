<?php

/**
 * ConnectedDatabaseReader — reads a register that a database source backs.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * "Start from a database" binds a register OpenRegister serves from a
 * `database` source. Before anything is created the server checks it: the
 * register must exist for the caller (OpenRegister's own access rules), its
 * `source` must name a source of type `database`, and every chosen table must
 * be one of the register's schemas. Whether that source takes writes is read
 * here, at creation time, from `authConfig.writable`; it is never stored on the
 * Application. Only the source's type and write setting are read, never its
 * connection details or credentials.
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
 * @spec openspec/changes/archive/2026-11-20-data-external-database-sources/specs/external-database-sources/spec.md#requirement-generate-an-app-from-a-connected-database-req-bqdb-003
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\SourceMapperInterface;
use Throwable;

/**
 * Resolves a register and chosen tables into what the manifest builder needs.
 *
 * @spec openspec/changes/archive/2026-11-20-data-external-database-sources/specs/external-database-sources/spec.md#requirement-generate-an-app-from-a-connected-database-req-bqdb-003
 */
class ConnectedDatabaseReader {

	/**
	 * The source type that serves a register from an external database.
	 *
	 * @var string
	 */
	public const DATABASE_TYPE = 'database';

	/**
	 * Constructor.
	 *
	 * @param RegisterMapper        $registers OpenRegister's register mapper, read with the caller's access rules.
	 * @param SchemaMapper          $schemas   OpenRegister's schema mapper, for the register's tables.
	 * @param SourceMapperInterface $sources   OpenRegister's source mapper, for the source's type and write setting.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly RegisterMapper $registers,
		private readonly SchemaMapper $schemas,
		private readonly SourceMapperInterface $sources,
	) {
	}//end __construct()

	/**
	 * The database register and its chosen tables.
	 *
	 * @param string       $register The register slug the maker picked.
	 * @param list<string> $tables   The table (schema) slugs the maker ticked, in menu order.
	 *
	 * @return array{register: string, label: string, writable: bool, schemas: list<array<string, mixed>>}
	 *
	 * @throws InvalidArgumentException When the register cannot be read, is not backed by a
	 *                                  database, or a table is not one of its schemas.
	 *
	 * @spec openspec/changes/archive/2026-11-20-data-external-database-sources/specs/external-database-sources/spec.md#requirement-generate-an-app-from-a-connected-database-req-bqdb-003
	 */
	public function read(string $register, array $tables): array {
		$slug = trim($register);
		if ($slug === '') {
			throw new InvalidArgumentException('Choose a connected database.');
		}

		try {
			$entity = $this->registers->find($slug);
		} catch (Throwable) {
			throw new InvalidArgumentException(sprintf('The register "%s" does not exist or cannot be read.', $slug));
		}

		$writable = $this->databaseWritable(sourceId: (string)($entity->getSource() ?? ''), register: $slug);
		$chosen   = $this->chosenTables(schemaIds: $entity->getSchemas(), tables: $tables, register: $slug);

		$label = trim((string)($entity->getTitle() ?? ''));
		if ($label === '') {
			$label = $slug;
		}

		return [
			'register' => $slug,
			'label' => $label,
			'writable' => $writable,
			'schemas' => $chosen,
		];
	}//end read()

	/**
	 * Whether the register's source is a database, and whether it takes writes.
	 *
	 * @param string $sourceId The source the register names ('' for none).
	 * @param string $register The register slug, for the refusal.
	 *
	 * @return bool Whether the source takes writes.
	 *
	 * @throws InvalidArgumentException When no database source backs the register.
	 *
	 * @spec openspec/changes/archive/2026-11-20-data-external-database-sources/specs/external-database-sources/spec.md#requirement-generate-an-app-from-a-connected-database-req-bqdb-003
	 */
	private function databaseWritable(string $sourceId, string $register): bool {
		$source = $this->findSource(sourceId: trim($sourceId));
		if ($source === null || (string)$source->getType() !== self::DATABASE_TYPE) {
			throw new InvalidArgumentException(sprintf('The register "%s" is not a connected database.', $register));
		}

		$authConfig = $source->getAuthConfig();
		return is_array($authConfig) === true && ($authConfig['writable'] ?? false) === true;
	}//end databaseWritable()

	/**
	 * The source a register names, or null when it names none or the source cannot be read.
	 *
	 * @param string $sourceId The trimmed source id ('' for none).
	 *
	 * @return mixed The source, or null.
	 *
	 * @spec openspec/changes/archive/2026-11-20-data-external-database-sources/specs/external-database-sources/spec.md#requirement-generate-an-app-from-a-connected-database-req-bqdb-003
	 */
	private function findSource(string $sourceId): mixed {
		if ($sourceId === '') {
			return null;
		}

		try {
			return $this->sources->findForSystem(sourceId: $sourceId);
		} catch (Throwable) {
			return null;
		}
	}//end findSource()

	/**
	 * The chosen tables as `{slug, title, properties}`, in the order the maker ticked them.
	 *
	 * @param mixed        $schemaIds The register's schema ids.
	 * @param list<string> $tables    The ticked table slugs.
	 * @param string       $register  The register slug, for the refusal.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @throws InvalidArgumentException When nothing is ticked or a table is not in the register.
	 *
	 * @spec openspec/changes/archive/2026-11-20-data-external-database-sources/specs/external-database-sources/spec.md#requirement-generate-an-app-from-a-connected-database-req-bqdb-003
	 */
	private function chosenTables(mixed $schemaIds, array $tables, string $register): array {
		$bySlug = $this->tablesBySlug(schemaIds: $schemaIds);

		$chosen = [];
		foreach ($tables as $table) {
			$slug = trim((string)$table);
			if ($slug === '' || isset($chosen[$slug]) === true) {
				continue;
			}

			if (isset($bySlug[$slug]) === false) {
				throw new InvalidArgumentException(sprintf('The table "%s" is not part of the register "%s".', $slug, $register));
			}

			$chosen[$slug] = $bySlug[$slug];
		}

		if ($chosen === []) {
			throw new InvalidArgumentException('Choose at least one table of the database.');
		}

		return array_values($chosen);
	}//end chosenTables()

	/**
	 * The register's tables by slug; a schema that cannot be read is left out.
	 *
	 * @param mixed $schemaIds The register's schema ids.
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/changes/archive/2026-11-20-data-external-database-sources/specs/external-database-sources/spec.md#requirement-generate-an-app-from-a-connected-database-req-bqdb-003
	 */
	private function tablesBySlug(mixed $schemaIds): array {
		$bySlug = [];
		foreach ((array)$schemaIds as $schemaId) {
			try {
				$schema = $this->schemas->find($schemaId);
			} catch (Throwable) {
				continue;
			}

			$slug = (string)$schema->getSlug();
			if ($slug !== '') {
				$bySlug[$slug] = [
					'slug' => $slug,
					'title' => (string)($schema->getTitle() ?? ''),
					'properties' => (array)$schema->getProperties(),
				];
			}
		}

		return $bySlug;
	}//end tablesBySlug()
}//end class
