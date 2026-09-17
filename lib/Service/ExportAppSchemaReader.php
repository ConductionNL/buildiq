<?php

/**
 * Buildiq ExportAppSchemaReader
 *
 * Reads the schemas and records of an exported version's register, and renames
 * the source slugs to the exported app's own names.
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
 * @spec openspec/specs/openbuild-exporter/spec.md#requirement-companion-schemas-migrate-into-the-exported-app-s-own-namespace
 *
 * @SPDX-License-Identifier: EUPL-1.2
 * @SPDX-FileCopyrightText:  2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads a version register's schemas and records for an export.
 *
 * @spec openspec/specs/openbuild-exporter/spec.md#requirement-companion-schemas-migrate-into-the-exported-app-s-own-namespace
 */
class ExportAppSchemaReader {
	/**
	 * Most records exported per schema, so one export cannot dump a whole instance.
	 *
	 * @var integer
	 */
	private const MAX_RECORDS_PER_SCHEMA = 1000;

	/**
	 * Schema fields carried into the exported definition, with their getters.
	 *
	 * @var array<string,string>
	 */
	private const SCHEMA_FIELDS = [
		'title' => 'getTitle',
		'description' => 'getDescription',
		'version' => 'getVersion',
		'icon' => 'getIcon',
		'required' => 'getRequired',
		'properties' => 'getProperties',
		'configuration' => 'getConfiguration',
		'authorization' => 'getAuthorization',
	];

	/**
	 * Object keys that belong to the source instance, not to the record.
	 *
	 * @var array<int,string>
	 */
	private const INSTANCE_KEYS = ['@self', 'id', 'uuid'];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService Reads the records.
	 * @param RegisterMapper $registerMapper Resolves the version's register.
	 * @param SchemaMapper $schemaMapper Resolves the register's schemas.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Read the schemas of the version's register.
	 *
	 * @param string $registerSlug The version's register slug.
	 * @param string $prefix The schema slug prefix to strip.
	 *
	 * @spec openspec/specs/openbuild-exporter/spec.md#requirement-companion-schemas-migrate-into-the-exported-app-s-own-namespace
	 *
	 * @return array<int,array{sourceSlug: string, slug: string, registerId: int, schemaId: int, definition: array<string,mixed>}>
	 */
	public function collectSchemas(string $registerSlug, string $prefix): array {
		try {
			$register = $this->registerMapper->find($registerSlug, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->info('Buildiq export: register "' . $registerSlug . '" not found, no schemas exported: ' . $e->getMessage());
			return [];
		}

		$out = [];
		foreach ((array)$register->getSchemas() as $schemaId) {
			try {
				$schema = $this->schemaMapper->find($schemaId, _multitenancy: false);
			} catch (Throwable $e) {
				$this->logger->info('Buildiq export: schema ' . ((string)$schemaId) . ' not found: ' . $e->getMessage());
				continue;
			}

			$sourceSlug = (string)$schema->getSlug();
			if ($sourceSlug === '') {
				continue;
			}

			$slug = $sourceSlug;
			if ($prefix !== '' && str_starts_with($sourceSlug, $prefix) === true && strlen($sourceSlug) > strlen($prefix)) {
				$slug = substr($sourceSlug, strlen($prefix));
			}

			$out[] = [
				'sourceSlug' => $sourceSlug,
				'slug' => $slug,
				'registerId' => (int)$register->getId(),
				'schemaId' => (int)$schema->getId(),
				'definition' => $this->schemaDefinition(schema: $schema, slug: $slug),
			];
		}//end foreach

		return $out;
	}//end collectSchemas()

	/**
	 * Reduce a serialised schema to the portable definition.
	 *
	 * Each field is read through its getter. Some are magic on the entity, so a
	 * getter the entity does not answer is skipped rather than fatal.
	 *
	 * @param object $schema The schema entity.
	 * @param string $slug The exported slug.
	 *
	 * @return array<string,mixed> The definition.
	 */
	private function schemaDefinition(object $schema, string $slug): array {
		$definition = ['slug' => $slug, 'type' => 'object'];
		foreach (self::SCHEMA_FIELDS as $field => $getter) {
			try {
				$value = $schema->$getter();
			} catch (Throwable $e) {
				continue;
			}

			if ($value === null || $value === '' || $value === []) {
				continue;
			}

			$definition[$field] = $value;
		}

		if (isset($definition['version']) === false) {
			$definition['version'] = '0.1.0';
		}

		$definition['required'] = array_values((array)($definition['required'] ?? []));
		$definition['properties'] = (array)($definition['properties'] ?? []);

		return $definition;
	}//end schemaDefinition()

	/**
	 * Read a schema's records, stripped of their source identity.
	 *
	 * @param int $registerId The register id.
	 * @param int $schemaId The schema id.
	 * @param array<string,string> $renames Source slug to exported slug.
	 *
	 * @return array<int,array{key: string, data: array<string,mixed>}> The records, in a stable order.
	 *
	 * @spec openspec/specs/openbuild-exporter/spec.md#requirement-optional-seed-data-inclusion
	 */
	public function collectRecords(int $registerId, int $schemaId, array $renames): array {
		try {
			$results = $this->objectService->searchObjects(
				query: [
					'@self' => ['register' => $registerId, 'schema' => $schemaId],
					'_limit' => self::MAX_RECORDS_PER_SCHEMA,
				],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('Buildiq export: could not read records of schema ' . $schemaId . ': ' . $e->getMessage());
			return [];
		}

		$records = [];
		foreach ((array)$results as $result) {
			$row = $this->normalise(object: $result);
			if ($row === []) {
				continue;
			}

			$key = (string)($row['slug'] ?? '');
			if ($key === '') {
				$key = $this->uuidOf(object: $row);
			}

			foreach (self::INSTANCE_KEYS as $instanceKey) {
				unset($row[$instanceKey]);
			}

			$record = $this->renameSlugs(node: $row, renames: $renames);
			$records[$key . "\0" . count($records)] = ['key' => $key, 'data' => $record];
		}

		ksort($records);

		return array_values($records);
	}//end collectRecords()

	/**
	 * Replace every string that is exactly a source slug, or a `#/components/schemas/<slug>` reference.
	 *
	 * @param array<mixed> $node The structure to rewrite.
	 * @param array<string,string> $renames Source slug to exported slug.
	 *
	 * @return array<mixed> The rewritten structure.
	 *
	 * @spec openspec/specs/openbuild-exporter/spec.md#requirement-companion-schemas-migrate-into-the-exported-app-s-own-namespace
	 */
	public function renameSlugs(array $node, array $renames): array {
		foreach ($node as $key => $value) {
			if (is_array($value) === true) {
				$node[$key] = $this->renameSlugs(node: $value, renames: $renames);
				continue;
			}

			if (is_string($value) === false) {
				continue;
			}

			if (isset($renames[$value]) === true) {
				$node[$key] = $renames[$value];
				continue;
			}

			$slash = strrpos($value, '/');
			if ($slash !== false && isset($renames[substr($value, $slash + 1)]) === true) {
				$node[$key] = substr($value, 0, $slash + 1) . $renames[substr($value, $slash + 1)];
			}
		}//end foreach

		return $node;
	}//end renameSlugs()

	/**
	 * The UUID of a serialised object.
	 *
	 * @param array<string,mixed> $object The object.
	 *
	 * @return string The UUID, '' when absent.
	 */
	private function uuidOf(array $object): string {
		$self = ($object['@self'] ?? null);
		if (is_array($self) === true && (string)($self['id'] ?? '') !== '') {
			return (string)$self['id'];
		}

		return (string)($object['id'] ?? ($object['uuid'] ?? ''));
	}//end uuidOf()

	/**
	 * Coerce an OpenRegister result to an array.
	 *
	 * @param mixed $object The result.
	 *
	 * @return array<string,mixed> The array, empty when it is neither.
	 */
	private function normalise(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialised = $object->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		return [];
	}//end normalise()
}//end class
