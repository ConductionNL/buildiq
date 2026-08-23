<?php

/**
 * Buildiq Populate Application Permissions Repair Step
 *
 * Idempotent migration that populates the `permissions` block on every
 * existing Application whose `permissions` is missing or empty.
 * Per design.md "Migration Plan" of buildiq-rbac, the default is
 * `{ owners: ['admin'], editors: [], viewers: [] }`. The migration
 * skips any Application whose `permissions.owners` is already
 * non-empty (idempotent re-runs).
 *
 * The repair step is the ADR-031 §Exceptions(1) thin glue that
 * compensates for the absence of an OR-side schema migration hook.
 * Runs after `InitializeSettings` (which re-imports the register
 * config and adds the `permissions` property to the schema).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Repair
 * @package  OCA\Buildiq\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * Runs without a Nextcloud user session (Anonymous), which OpenRegister RBAC
 * denies read/write access to by default. The find-and-patch body runs inside
 * `ObjectService::runAsSystem()`, elevating the caller to a trusted system
 * principal for the callable only. The `_rbac`/`_multitenancy` bypass on the
 * save call remains as defence in depth.
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-30
 */

declare(strict_types=1);

namespace OCA\Buildiq\Repair;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Repair step that populates `permissions` on legacy Applications.
 */
class PopulateApplicationPermissions implements IRepairStep {
	/**
	 * Default group placed in `permissions.owners` when an Application
	 * has none. Matches design.md "Seed Data" / OQ-4.
	 */
	private const FALLBACK_OWNER_GROUP = 'admin';

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Logger for diagnostics
	 * @param ObjectServiceInterface $objectService OpenRegister object service
	 *
	 * @return void
	 */
	public function __construct(
		private LoggerInterface $logger,
		private ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'Populate permissions on pre-existing Buildiq Applications';
	}//end getName()

	/**
	 * Run the migration.
	 *
	 * @param IOutput $output The output interface for progress reporting
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-30
	 */
	public function run(IOutput $output): void {
		$output->info('Populating permissions on pre-existing Applications...');

		try {
			$patched = $this->patchAllApplications(output: $output);

			if ($patched === null) {
				$output->info('No Applications found; nothing to migrate.');
				return;
			}

			$output->info('Permissions populated on ' . $patched . ' Application(s).');
			$this->logger->info(
				'Buildiq: PopulateApplicationPermissions completed',
				['patched' => $patched]
			);
		} catch (\Throwable $e) {
			$output->warning('Could not populate permissions: ' . $e->getMessage());
			$this->logger->error(
				'Buildiq: PopulateApplicationPermissions failed',
				['exception' => $e->getMessage()]
			);
		}//end try
	}//end run()

	/**
	 * Patch every Application inside OpenRegister's system context.
	 *
	 * Extracted from {@see self::run()} so the whole find-and-patch body can be
	 * handed to `runAsSystem()` as a single callable.
	 *
	 * @param IOutput $output Repair output channel.
	 *
	 * @return int|null The number patched, or null when no Applications exist.
	 */
	private function patchAllApplications(IOutput $output): ?int {
		// `runAsSystem()` is declared on ObjectServiceInterface, so the
		// method_exists() probe this replaces could never be false and the
		// un-elevated fallback was unreachable.
		return $this->objectService->runAsSystem(
			fn (): ?int => $this->patchApplicationsMissingPermissions(output: $output)
		);
	}//end patchAllApplications()

	/**
	 * Find every Application row and patch the ones missing `permissions`.
	 *
	 * Split out of `run()` so the whole find-and-patch body can be handed to
	 * `ObjectService::runAsSystem()` as a single callable (see class docblock
	 * for why elevation is needed at all).
	 *
	 * @param IOutput $output Output channel for progress reporting
	 *
	 * @return integer|null Number of Applications patched, or null when none were found
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-30
	 */
	private function patchApplicationsMissingPermissions(IOutput $output): ?int {
		$applications = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => 'openbuild',
					'schema' => 'application',
				],
				'limit' => 1000,
			]
		);

		if (empty($applications) === true) {
			return null;
		}

		$patched = 0;
		foreach ($applications as $applicationEntry) {
			$applicationArray = $this->normaliseObject(object: $applicationEntry);
			if ($this->needsMigration(application: $applicationArray) === false) {
				continue;
			}

			$uuid = $this->extractUuid(application: $applicationArray);
			if ($uuid === null) {
				$output->warning('Skipping an Application without a resolvable UUID.');
				continue;
			}

			$applicationArray['permissions'] = [
				'owners' => [self::FALLBACK_OWNER_GROUP],
				'editors' => [],
				'viewers' => [],
			];

			// Defence in depth alongside the runAsSystem() elevation: the
			// Application schema's update:[admin] guard would otherwise deny
			// the Anonymous repair-step caller.
			$this->objectService->saveObject(
				object: $applicationArray,
				register: 'openbuild',
				schema: 'application',
				_rbac: false,
				_multitenancy: false
			);
			$patched++;
		}//end foreach

		return $patched;
	}//end patchApplicationsMissingPermissions()

	/**
	 * Decide whether an Application needs the permissions migration.
	 *
	 * An Application needs migration when `permissions` is missing,
	 * null, not an object, or when `permissions.owners` is empty.
	 *
	 * @param array<string, mixed> $application The Application data
	 *
	 * @return bool True when the Application should be patched
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-30
	 */
	private function needsMigration(array $application): bool {
		$permissions = ($application['permissions'] ?? null);
		if (is_array($permissions) === false) {
			return true;
		}

		$owners = ($permissions['owners'] ?? null);
		if (is_array($owners) === false) {
			return true;
		}

		return empty($owners) === true;
	}//end needsMigration()

	/**
	 * Extract the OR uuid from a normalised Application array.
	 *
	 * Per the memory rule, OR serialises uuid into the "@self.id" key
	 * (canonical), the "@self.uuid" key (legacy), or top-level uuid.
	 *
	 * @param array<string, mixed> $application The Application data
	 *
	 * @return string|null The uuid, or null when missing
	 */
	private function extractUuid(array $application): ?string {
		$self = ($application['@self'] ?? []);
		if (is_array($self) === true) {
			$candidate = ($self['id'] ?? ($self['uuid'] ?? null));
			if (is_string($candidate) === true && $candidate !== '') {
				return $candidate;
			}
		}

		$direct = ($application['uuid'] ?? null);
		if (is_string($direct) === true && $direct !== '') {
			return $direct;
		}

		return null;
	}//end extractUuid()

	/**
	 * Coerce an OR result entry (ObjectEntity or array) to an associative array.
	 *
	 * @param mixed $object The OR object/result entry
	 *
	 * @return array<string, mixed>
	 */
	private function normaliseObject(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialised = $object->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		if (is_object($object) === true && method_exists($object, 'getObject') === true) {
			$inner = $object->getObject();
			if (is_array($inner) === true) {
				return $inner;
			}
		}

		return [];
	}//end normaliseObject()
}//end class
