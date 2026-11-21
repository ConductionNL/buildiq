<?php

/**
 * App Demo Records Service
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Fills an app's register with demo records generated from its own schemas,
 * and removes them again in one action (capability-requirements-backfill
 * REQ-BQCV-030). The records it creates are listed on the Application as
 * `demoRecords`, which is what marks them as demo data: removing deletes
 * exactly those, so records a maker created are kept.
 *
 * @category Service
 * @package  OCA\Buildiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/application-creation-wizard/spec.md#requirement-a-maker-fills-a-new-app-with-demo-records-req-bqcv-030
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\Buildiq\Kernel\Contracts\VersionPromotionServiceInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectDeletion;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Creates and removes an app's demo records.
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/application-creation-wizard/spec.md#requirement-a-maker-fills-a-new-app-with-demo-records-req-bqcv-030
 */
class AppDemoRecordsService {

	/**
	 * How many demo records each schema gets per fill.
	 *
	 * @var int
	 */
	public const PER_SCHEMA = 3;

	/**
	 * The Application property listing the demo records.
	 *
	 * @var string
	 */
	public const MARKER = 'demoRecords';

	/**
	 * Constructor.
	 *
	 * @param RegisterMapper         $registerMapper Reads the app's register.
	 * @param SchemaMapper           $schemaMapper   Reads its schemas.
	 * @param ObjectServiceInterface $objectService  Saves and deletes the records and the Application.
	 * @param LoggerInterface        $logger         Records a record that could not be saved or deleted.
	 */
	public function __construct(
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Create demo records in every schema of the app's register and list them on the Application.
	 *
	 * The records go into the register of the app's production version.
	 *
	 * @param string               $applicationUuid The Application's uuid.
	 * @param array<string, mixed> $application     The Application.
	 *
	 * @return array{created: int, skipped: list<string>, failed: int, total: int}|null
	 *         Null when the app has no production register. `skipped` names the
	 *         schemas no valid demo record can be made for; `total` is how many
	 *         demo records the app lists now.
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/application-creation-wizard/spec.md#requirement-a-maker-fills-a-new-app-with-demo-records-req-bqcv-030
	 */
	public function fill(string $applicationUuid, array $application): ?array {
		$register = $this->productionRegister(application: $application);
		if ($register === '') {
			return null;
		}

		$listed = self::listed(application: $application);
		$result = ['created' => 0, 'skipped' => [], 'failed' => 0];
		foreach ($this->schemas(register: $register) as $schema) {
			$slug = (string)$schema->getSlug();
			$records = $this->recordsFor(schema: $schema);
			if ($records === []) {
				$result['skipped'][] = $slug;
				continue;
			}

			foreach ($records as $record) {
				$uuid = $this->create(record: $record, target: new ObjectFindTarget(register: $register, schema: $slug));
				if ($uuid === null) {
					$result['failed']++;
					continue;
				}

				$listed[] = ['register' => $register, 'schema' => $slug, 'uuid' => $uuid];
				$result['created']++;
			}
		}

		if ($result['created'] > 0) {
			$application[self::MARKER] = $listed;
			$this->saveApplication(applicationUuid: $applicationUuid, application: $application);
		}

		$result['total'] = count($listed);
		return $result;
	}//end fill()

	/**
	 * Delete the app's demo records; one that cannot be deleted stays listed.
	 *
	 * @param string               $applicationUuid The Application's uuid.
	 * @param array<string, mixed> $application     The Application.
	 *
	 * @return array{removed: int, failed: int}
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/application-creation-wizard/spec.md#requirement-a-maker-fills-a-new-app-with-demo-records-req-bqcv-030
	 */
	public function remove(string $applicationUuid, array $application): array {
		$kept = [];
		$removed = 0;
		$listed = self::listed(application: $application);
		foreach ($listed as $entry) {
			if ($this->delete(entry: $entry) === true) {
				$removed++;
				continue;
			}

			$kept[] = $entry;
		}

		if ($listed !== []) {
			$application[self::MARKER] = $kept;
			$this->saveApplication(applicationUuid: $applicationUuid, application: $application);
		}

		return ['removed' => $removed, 'failed' => count($kept)];
	}//end remove()

	/**
	 * The Application with this uuid, or null when it cannot be read.
	 *
	 * @param string $uuid The Application's uuid.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/application-creation-wizard/spec.md#requirement-a-maker-fills-a-new-app-with-demo-records-req-bqcv-030
	 */
	public function application(string $uuid): ?array {
		return $this->buildiqObject(uuid: $uuid, schema: VersionPromotionServiceInterface::APPLICATION_SCHEMA);
	}//end application()

	/**
	 * The register of the app's production version, '' when it has none.
	 *
	 * @param array<string, mixed> $application The Application.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/application-creation-wizard/spec.md#requirement-a-maker-fills-a-new-app-with-demo-records-req-bqcv-030
	 */
	public function productionRegister(array $application): string {
		$version = ($application['productionVersion'] ?? null);
		if (is_array($version) === false) {
			$version = $this->buildiqObject(uuid: (string)$version, schema: VersionPromotionServiceInterface::APPLICATION_VERSION_SCHEMA);
		}

		return (string)(($version ?? [])['register'] ?? '');
	}//end productionRegister()

	/**
	 * The demo records of one schema, [] when its required properties cannot all be filled.
	 *
	 * @param object $schema The OpenRegister schema.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function recordsFor(object $schema): array {
		$properties = (array)$schema->getProperties();
		$required = array_values(array_map('strval', (array)$schema->getRequired()));
		$records = [];
		for ($n = 1; $n <= self::PER_SCHEMA; $n++) {
			$record = (new DemoRecordValues(n: $n))->record(properties: $properties, required: $required);
			if ($record === null) {
				return [];
			}

			$records[] = $record;
		}

		return $records;
	}//end recordsFor()

	/**
	 * The schemas of a register; an unreadable register or schema is skipped.
	 *
	 * @param string $register The register id or slug.
	 *
	 * @return list<object> The OpenRegister schemas.
	 */
	private function schemas(string $register): array {
		try {
			$ids = ($this->registerMapper->find($register, _rbac: false, _multitenancy: false)->getSchemas() ?? []);
		} catch (Throwable $e) {
			$this->logger->warning('Buildiq: demo records could not read register "' . $register . '": ' . $e->getMessage());
			return [];
		}

		$schemas = [];
		foreach ((array)$ids as $id) {
			try {
				$schemas[] = $this->schemaMapper->find($id, _rbac: false, _multitenancy: false);
			} catch (Throwable $e) {
				$this->logger->warning('Buildiq: demo records skipped schema ' . (string)$id . ': ' . $e->getMessage());
			}
		}

		return $schemas;
	}//end schemas()

	/**
	 * Save one demo record through OpenRegister's validation, and answer its uuid.
	 *
	 * @param array<string, mixed> $record The record.
	 * @param ObjectFindTarget     $target Its register and schema.
	 *
	 * @return string|null Null when it was refused.
	 */
	private function create(array $record, ObjectFindTarget $target): ?string {
		try {
			$entity = $this->objectService->saveObject(object: $record, options: new ObjectSaveOptions(target: $target));
		} catch (Throwable $e) {
			$this->logger->warning('Buildiq: a demo record for schema "' . (string)$target->schema . '" was refused: ' . $e->getMessage());
			return null;
		}

		$uuid = (string)($entity->getUuid() ?? '');
		if ($uuid === '') {
			return null;
		}

		return $uuid;
	}//end create()

	/**
	 * Delete one listed demo record; one already gone counts as deleted.
	 *
	 * @param array<string, string> $entry The listed record.
	 *
	 * @return bool Whether it is gone.
	 */
	private function delete(array $entry): bool {
		try {
			$this->objectService->deleteObject(
				new ObjectDeletion(
					uuid: $entry['uuid'],
					target: new ObjectFindTarget(register: $entry['register'], schema: $entry['schema'])
				)
			);
		} catch (Throwable $e) {
			$this->logger->warning('Buildiq: demo record ' . $entry['uuid'] . ' could not be deleted: ' . $e->getMessage());
			return false;
		}

		return true;
	}//end delete()

	/**
	 * Save the Application, which carries its list of demo records.
	 *
	 * @param string               $applicationUuid The Application's uuid.
	 * @param array<string, mixed> $application     The Application with its `demoRecords`.
	 *
	 * @return void
	 */
	private function saveApplication(string $applicationUuid, array $application): void {
		$target = new ObjectFindTarget(
			register: VersionPromotionServiceInterface::REGISTER_SLUG,
			schema: VersionPromotionServiceInterface::APPLICATION_SCHEMA
		);
		$options = new ObjectSaveOptions(target: $target, uuid: $applicationUuid);
		$this->objectService->saveObject(object: $application, options: $options, _rbac: false, _multitenancy: false);
	}//end saveApplication()

	/**
	 * An object of buildiq's own register, or null for an empty uuid or a failed read.
	 *
	 * @param string $uuid   The object's uuid.
	 * @param string $schema Its schema.
	 *
	 * @return array<string, mixed>|null
	 */
	private function buildiqObject(string $uuid, string $schema): ?array {
		$entity = null;
		if ($uuid !== '') {
			try {
				$options = new ObjectFindOptions(register: VersionPromotionServiceInterface::REGISTER_SLUG, schema: $schema);
				$entity = $this->objectService->find(id: $uuid, options: $options);
			} catch (Throwable $e) {
				$this->logger->info('Buildiq: demo records could not read ' . $schema . ' ' . $uuid . ': ' . $e->getMessage());
			}
		}

		$data = $entity?->jsonSerialize();
		if (is_array($data) === false) {
			return null;
		}

		return $data;
	}//end buildiqObject()

	/**
	 * The demo records an Application lists, each with a register, schema and uuid.
	 *
	 * @param array<string, mixed> $application The Application.
	 *
	 * @return list<array<string, string>>
	 */
	private static function listed(array $application): array {
		$listed = [];
		foreach ((array)($application[self::MARKER] ?? []) as $entry) {
			if (is_array($entry) === false || trim((string)($entry['uuid'] ?? '')) === '') {
				continue;
			}

			$listed[] = [
				'register' => (string)($entry['register'] ?? ''),
				'schema' => (string)($entry['schema'] ?? ''),
				'uuid' => (string)$entry['uuid'],
			];
		}

		return $listed;
	}//end listed()
}//end class
