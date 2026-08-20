<?php

/**
 * OpenBuild Application Deletion Service
 *
 * Tears down a virtual Application and everything it owns: its
 * ApplicationVersions, each version's per-version OpenRegister register, the
 * BuiltAppRoute slug-index entries, and finally the Application object itself.
 *
 * Best-effort: a per-resource failure is logged and collected into the returned
 * `orphaned` list rather than aborting the whole teardown (mirrors the wizard's
 * rollback semantics so a partial create can still be cleaned up).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenBuild\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Service;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\RegisterService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Imperative teardown of an Application and its owned resources.
 */
class ApplicationDeletionService {
	/**
	 * Page size when draining a register's objects before deleting it.
	 *
	 * @var int
	 */
	private const PURGE_BATCH_LIMIT = 500;

	/**
	 * Safety cap on drain rounds per schema, so a delete that never removes a
	 * row cannot spin forever.
	 *
	 * @var int
	 */
	private const MAX_PURGE_ROUNDS = 200;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OR object surface (find + delete)
	 * @param RegisterService $registerService OR register-level service (delete)
	 * @param RegisterMapper $registerMapper OR register lookup (by slug)
	 * @param LoggerInterface $logger PSR logger
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly RegisterService $registerService,
		private readonly RegisterMapper $registerMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Delete an Application plus its versions and routes, and — only when
	 * $deleteData is true — its per-version registers and all their objects.
	 *
	 * By default ($deleteData false) the underlying registers and the data
	 * inside them are PRESERVED: the app wrapper is removed but the user's data
	 * survives in OpenRegister. The caller must opt in (checkbox) to wipe data.
	 *
	 * @param string $appUuid The Application UUID.
	 * @param string $appSlug The Application slug (for log context).
	 * @param bool $deleteData When true, also delete the per-version registers
	 *                         and every object stored in them.
	 *
	 * @return array<int,string> Resources that could not be removed (orphaned).
	 */
	public function deleteApplication(string $appUuid, string $appSlug, bool $deleteData = false): array {
		$orphaned = [];

		// 1. Versions + (optionally) their per-version registers.
		$versions = $this->findChildren(
			schema: ApplicationVersionService::APPLICATION_VERSION_SCHEMA,
			field: 'application',
			value: $appUuid
		);
		foreach ($versions as $version) {
			$versionUuid = (string)($version['id'] ?? ($version['@self']['id'] ?? ''));
			$registerSlug = (string)($version['register'] ?? '');
			if ($deleteData === true && $registerSlug !== '') {
				$this->deleteRegister(registerSlug: $registerSlug, orphaned: $orphaned);
			}

			if ($versionUuid !== '') {
				$this->deleteObject(uuid: $versionUuid, label: 'version', orphaned: $orphaned);
			}
		}

		// 2. BuiltAppRoute slug-index entries pointing at this app.
		foreach ($this->findChildren(schema: 'built-app-route', field: 'applicationUuid', value: $appUuid) as $route) {
			$routeUuid = (string)($route['id'] ?? ($route['@self']['id'] ?? ''));
			if ($routeUuid !== '') {
				$this->deleteObject(uuid: $routeUuid, label: 'route', orphaned: $orphaned);
			}
		}

		// 3. The Application object itself.
		$this->deleteObject(uuid: $appUuid, label: 'application', orphaned: $orphaned);

		if ($orphaned !== []) {
			$this->logger->warning(
				'OpenBuild: deleteApplication({slug}) left orphaned resources: {orphaned}',
				['slug' => $appSlug, 'orphaned' => implode(', ', $orphaned)]
			);
		}

		return $orphaned;
	}//end deleteApplication()

	/**
	 * Find child objects of the application by a filter field.
	 *
	 * @param string $schema The schema slug to query.
	 * @param string $field The filter field name.
	 * @param string $value The filter value (the app UUID).
	 *
	 * @return array<int,array<string,mixed>> Normalised child object arrays.
	 */
	private function findChildren(string $schema, string $field, string $value): array {
		try {
			$results = $this->objectService->findAll(
				config: [
					'filters' => [
						'register' => ApplicationVersionService::REGISTER_SLUG,
						'schema' => $schema,
						$field => $value,
					],
					'limit' => 1000,
				]
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'OpenBuild: deleteApplication failed to query {schema}: {message}',
				['schema' => $schema, 'message' => $e->getMessage()]
			);
			return [];
		}

		$out = [];
		foreach ($results as $item) {
			if (is_array($item) === true) {
				$out[] = $item;
				continue;
			}

			if (is_object($item) === true && method_exists($item, 'jsonSerialize') === true) {
				$serialised = $item->jsonSerialize();
				if (is_array($serialised) === true) {
					$out[] = $serialised;
				}
			}
		}//end foreach

		return $out;
	}//end findChildren()

	/**
	 * Delete a per-version register by slug (best-effort).
	 *
	 * @param string $registerSlug The register slug.
	 * @param array<int,string> $orphaned Collector for failures.
	 *
	 * @return void
	 */
	private function deleteRegister(string $registerSlug, array &$orphaned): void {
		try {
			$register = $this->registerMapper->find($registerSlug, _multitenancy: false);
		} catch (Throwable $e) {
			// The register is already gone (or was never provisioned) — nothing
			// to tear down, and nothing to orphan.
			$this->logger->info(
				'OpenBuild: deleteApplication register {slug} not found, skipping: {message}',
				['slug' => $registerSlug, 'message' => $e->getMessage()]
			);
			return;
		}

		// RegisterMapper::delete() refuses to remove a register that still has
		// objects attached. Drain it first: otherwise the register — and its
		// unique organisation+slug row — survives the teardown, and a later
		// re-create with the same slug fails with a duplicate-key rollback
		// (wizard_rollback at register-provision-*).
		$this->purgeRegisterObjects(register: $register, registerSlug: $registerSlug, orphaned: $orphaned);

		try {
			$this->registerService->delete(register: $register);
		} catch (Throwable $e) {
			$this->logger->error(
				'OpenBuild: deleteApplication failed to delete register {slug}: {message}',
				['slug' => $registerSlug, 'message' => $e->getMessage()]
			);
			$orphaned[] = 'register:' . $registerSlug;
		}
	}//end deleteRegister()

	/**
	 * Delete every object stored in a register, across all its schemas.
	 *
	 * Best-effort: per-schema query failures and per-object delete failures are
	 * logged and collected into $orphaned rather than aborting. Deletes are soft
	 * (OR default), which is enough to satisfy the register-delete guard — its
	 * object count excludes soft-deleted rows (`_deleted IS NULL`).
	 *
	 * @param Register $register The register to drain.
	 * @param string $registerSlug The register slug (for log context).
	 * @param array<int,string> $orphaned Collector for failures.
	 *
	 * @return void
	 */
	private function purgeRegisterObjects(Register $register, string $registerSlug, array &$orphaned): void {
		foreach (($register->getSchemas() ?? []) as $schemaId) {
			$this->purgeRegisterSchema(registerSlug: $registerSlug, schemaId: $schemaId, orphaned: $orphaned);
		}
	}//end purgeRegisterObjects()

	/**
	 * Drain all objects for a single register+schema pair, in batches.
	 *
	 * @param string $registerSlug The register slug.
	 * @param mixed $schemaId The schema identifier (id or slug).
	 * @param array<int,string> $orphaned Collector for failures.
	 *
	 * @return void
	 */
	private function purgeRegisterSchema(string $registerSlug, mixed $schemaId, array &$orphaned): void {
		for ($round = 0; $round < self::MAX_PURGE_ROUNDS; $round++) {
			try {
				$objects = $this->objectService->findAll(
					config: [
						'filters' => [
							'register' => $registerSlug,
							'schema' => $schemaId,
						],
						'limit' => self::PURGE_BATCH_LIMIT,
					]
				);
			} catch (Throwable $e) {
				$this->logger->error(
					'OpenBuild: deleteApplication failed to list objects in register {slug} schema {schema}: {message}',
					['slug' => $registerSlug, 'schema' => (string)$schemaId, 'message' => $e->getMessage()]
				);
				$orphaned[] = 'register-objects:' . $registerSlug;
				return;
			}

			if ($objects === []) {
				return;
			}

			// Track progress so a batch that can never be deleted (e.g. an
			// append-only/archival schema) breaks the loop instead of spinning.
			$progressed = false;
			foreach ($objects as $object) {
				$uuid = $this->extractUuid(item: $object);
				if ($uuid === '') {
					continue;
				}

				try {
					$this->objectService->deleteObject(uuid: $uuid);
					$progressed = true;
				} catch (Throwable $e) {
					$this->logger->error(
						'OpenBuild: deleteApplication failed to delete object {uuid}: {message}',
						['uuid' => $uuid, 'message' => $e->getMessage()]
					);
					$orphaned[] = 'object:' . $uuid;
				}
			}//end foreach

			if ($progressed === false) {
				return;
			}
		}//end for

		// Every other exit above returns early; reaching here means the loop ran
		// the full MAX_PURGE_ROUNDS and still drained a complete batch each round,
		// so the register was NOT emptied. deleteRegister() will now fail the
		// register-delete guard ("objects still attached") and orphan the
		// register — log the true root cause here so that downstream failure is
		// diagnosable instead of surfacing as an unexplained duplicate-key
		// rollback on the next same-slug re-create.
		$this->logger->warning(
			'OpenBuild: purgeRegisterSchema hit MAX_PURGE_ROUNDS cap for register {slug} '
			. 'schema {schema} — register may still hold objects; downstream '
			. 'register-delete will orphan',
			['slug' => $registerSlug, 'schema' => (string)$schemaId, 'batch' => self::PURGE_BATCH_LIMIT, 'rounds' => self::MAX_PURGE_ROUNDS]
		);
	}//end purgeRegisterSchema()

	/**
	 * Extract an object UUID/id from a findAll result item (array or entity).
	 *
	 * @param mixed $item A rendered object array or an entity.
	 *
	 * @return string The object UUID/id, or '' when it cannot be determined.
	 */
	private function extractUuid(mixed $item): string {
		if (is_array($item) === true) {
			return (string)($item['id'] ?? ($item['@self']['id'] ?? ''));
		}

		if (is_object($item) === true && method_exists($item, 'jsonSerialize') === true) {
			$serialised = $item->jsonSerialize();
			if (is_array($serialised) === true) {
				return (string)($serialised['id'] ?? ($serialised['@self']['id'] ?? ''));
			}
		}

		return '';
	}//end extractUuid()

	/**
	 * Delete an object by UUID (best-effort).
	 *
	 * @param string $uuid The object UUID.
	 * @param string $label A short label for the orphaned list.
	 * @param array<int,string> $orphaned Collector for failures.
	 *
	 * @return void
	 */
	private function deleteObject(string $uuid, string $label, array &$orphaned): void {
		try {
			$this->objectService->deleteObject(uuid: $uuid);
		} catch (Throwable $e) {
			$this->logger->error(
				'OpenBuild: deleteApplication failed to delete {label} {uuid}: {message}',
				['label' => $label, 'uuid' => $uuid, 'message' => $e->getMessage()]
			);
			$orphaned[] = $label . ':' . $uuid;
		}
	}//end deleteObject()
}//end class
