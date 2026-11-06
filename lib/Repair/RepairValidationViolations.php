<?php

/**
 * Buildiq Repair Validation Violations Repair Step
 *
 * Brings stored rows in line with the same-row validations the register
 * declares in `x-openregister-validations`, before InitializeSettings imports
 * them. OpenRegister refuses a row that breaks one of those rules on its next
 * save, whatever field that save changes, so a row stored before the rules
 * applied would otherwise become impossible to update.
 *
 * - A hybrid Application without a `baseRef.id` gets
 *   `{kind: fleet-app, id: <slug>}` (its slug is the fleet app id).
 * - An ApplicationVersion whose `promotesTo` names itself gets `promotesTo: null`.
 * - A user-scoped ApplicationVersion without an owner is counted and reported
 *   by uuid, and left unchanged: its owner cannot be inferred.
 *
 * Idempotent: a repaired row no longer matches, so a second run changes
 * nothing. Never throws.
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
 * @spec openspec/specs/unified-app-model/spec.md#requirement-application-carries-a-baseref-linking-a-hybrid-app-to-its-fleet-base
 */

declare(strict_types=1);

namespace OCA\Buildiq\Repair;

use OCA\Buildiq\Service\AppOverrideService;
use OCA\Buildiq\Support\ObjectRecord;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Repair step that fixes or reports rows breaking the register validations.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) ObjectRecord is a pure coercion with no collaborators.
 *
 * @spec openspec/specs/unified-app-model/spec.md#requirement-application-carries-a-baseref-linking-a-hybrid-app-to-its-fleet-base
 */
class RepairValidationViolations implements IRepairStep {
	/**
	 * The counters a run reports, all zero.
	 *
	 * @var array<string,int>
	 */
	private const NO_COUNTS = [
		'hybridWithoutBaseRef' => 0,
		'baseRefBackfilled' => 0,
		'selfPromotion' => 0,
		'selfPromotionCleared' => 0,
		'userWithoutOwner' => 0,
		'failed' => 0,
	];

	/**
	 * The schema each repair counter saves to.
	 *
	 * @var array<string,string>
	 */
	private const SCHEMA_OF = [
		'baseRefBackfilled' => AppOverrideService::APPLICATION_SCHEMA,
		'selfPromotionCleared' => AppOverrideService::APPLICATION_VERSION_SCHEMA,
	];

	/**
	 * The counters of the current run.
	 *
	 * @var array<string,int>
	 */
	private array $counts = self::NO_COUNTS;

	/**
	 * Uuids of user-scoped versions without an owner, found in the current run.
	 *
	 * @var array<int,string>
	 */
	private array $ownerless = [];

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Logger for diagnostics
	 * @param ObjectServiceInterface $objectService OpenRegister object service
	 *
	 * @return void
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/unified-app-model/spec.md#requirement-application-carries-a-baseref-linking-a-hybrid-app-to-its-fleet-base
	 */
	public function getName(): string {
		return 'Repair Buildiq rows that break the register validations';
	}//end getName()

	/**
	 * Repair or report every violating row, then report the counts.
	 *
	 * @param IOutput $output The output interface for progress reporting
	 *
	 * @return void
	 *
	 * @spec openspec/specs/unified-app-model/spec.md#requirement-application-carries-a-baseref-linking-a-hybrid-app-to-its-fleet-base
	 * @spec openspec/specs/application-versions/spec.md#requirement-cycle-prevention-on-the-promotesto-chain
	 * @spec openspec/specs/layered-app-deltas/spec.md#requirement-applicationversion-carries-a-scope-discriminator-and-owner
	 */
	public function run(IOutput $output): void {
		$this->counts = self::NO_COUNTS;
		$this->ownerless = [];

		try {
			$this->objectService->runAsSystem(fn () => $this->repairAll());
		} catch (Throwable $e) {
			$output->warning('Repair-validation-violations: could not run (' . $e->getMessage() . ').');
			$this->logger->error('Buildiq: RepairValidationViolations failed', ['exception' => $e->getMessage()]);
			return;
		}

		$this->report(output: $output);
	}//end run()

	/**
	 * Repair both schemas.
	 *
	 * @return void
	 */
	private function repairAll(): void {
		foreach ($this->rows(schema: AppOverrideService::APPLICATION_SCHEMA) as $row) {
			$this->repairApplication(row: $row);
		}

		foreach ($this->rows(schema: AppOverrideService::APPLICATION_VERSION_SCHEMA) as $row) {
			$this->repairVersion(row: $row);
		}
	}//end repairAll()

	/**
	 * Backfill `baseRef` on a hybrid Application that lacks `baseRef.id`.
	 *
	 * @param array<string,mixed> $row The stored Application.
	 *
	 * @return void
	 */
	private function repairApplication(array $row): void {
		if (self::lacksBaseRefId(row: $row) === false) {
			return;
		}

		$this->counts['hybridWithoutBaseRef']++;
		$baseRef = self::backfilledBaseRef(row: $row);
		if ($baseRef === null) {
			$this->counts['failed']++;
			return;
		}

		$row['baseRef'] = $baseRef;
		$this->saveCounted(row: $row, counter: 'baseRefBackfilled');
	}//end repairApplication()

	/**
	 * Clear a self-pointing `promotesTo`, and note a user-scoped version without owner.
	 *
	 * @param array<string,mixed> $row The stored ApplicationVersion.
	 *
	 * @return void
	 */
	private function repairVersion(array $row): void {
		if (self::lacksOwner(row: $row) === true) {
			$this->counts['userWithoutOwner']++;
			$this->ownerless[] = (ObjectRecord::uuid(record: $row) ?? '(no uuid)');
		}

		if (self::promotesToItself(row: $row) === false) {
			return;
		}

		$this->counts['selfPromotion']++;
		$row['promotesTo'] = null;
		$this->saveCounted(row: $row, counter: 'selfPromotionCleared');
	}//end repairVersion()

	/**
	 * Save a repaired row under its own schema and count the outcome.
	 *
	 * @param array<string,mixed> $row The repaired row.
	 * @param string $counter The counter a successful save raises (a key of SCHEMA_OF).
	 *
	 * @return void
	 */
	private function saveCounted(array $row, string $counter): void {
		$uuid = ObjectRecord::uuid(record: $row);
		unset($row['@self']);
		try {
			$this->objectService->saveObject(
				object: $row,
				register: AppOverrideService::REGISTER_SLUG,
				schema: self::SCHEMA_OF[$counter],
				uuid: $uuid,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->counts['failed']++;
			$this->logger->warning('Buildiq: RepairValidationViolations could not save ' . ($uuid ?? '(no uuid)'), ['exception' => $e->getMessage()]);
			return;
		}

		$this->counts[$counter]++;
	}//end saveCounted()

	/**
	 * Every stored row of one schema in this app's register, as arrays.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function rows(string $schema): array {
		try {
			$found = $this->objectService->findAll(
				config: ['filters' => ['register' => AppOverrideService::REGISTER_SLUG, 'schema' => $schema]],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->debug('Buildiq: RepairValidationViolations found no ' . $schema . ' rows (' . $e->getMessage() . ').');
			return [];
		}

		return array_map(static fn (mixed $entry): array => ObjectRecord::toArray(object: $entry), $found);
	}//end rows()

	/**
	 * Write the counts to the repair output and the log.
	 *
	 * @param IOutput $output The output interface for progress reporting
	 *
	 * @return void
	 */
	private function report(IOutput $output): void {
		$counts = $this->counts;
		$output->info(
			'Repair-validation-violations: hybrid apps without baseRef.id ' . $counts['hybridWithoutBaseRef']
			. ' (backfilled ' . $counts['baseRefBackfilled'] . '), versions promoting to themselves ' . $counts['selfPromotion']
			. ' (cleared ' . $counts['selfPromotionCleared'] . '), user-scoped versions without owner ' . $counts['userWithoutOwner']
			. ' (left unchanged), failed ' . $counts['failed'] . '.'
		);
		if ($this->ownerless !== []) {
			$output->warning(
				'Repair-validation-violations: user-scoped versions without an owner are refused on their next save; '
				. 'assign an owner or delete them: ' . implode(', ', $this->ownerless)
			);
		}

		$this->logger->info('Buildiq: RepairValidationViolations completed', $counts + ['ownerless' => $this->ownerless]);
	}//end report()

	/**
	 * Whether a row is a hybrid Application without a usable `baseRef.id`.
	 *
	 * @param array<string,mixed> $row The stored Application.
	 *
	 * @return bool
	 */
	private static function lacksBaseRefId(array $row): bool {
		if (($row['appType'] ?? null) !== 'hybrid') {
			return false;
		}

		$baseRef = ($row['baseRef'] ?? null);

		return is_array($baseRef) === false || self::isBlank(value: ($baseRef['id'] ?? null)) === true;
	}//end lacksBaseRefId()

	/**
	 * The row's `baseRef` with `kind: fleet-app` and its slug as id, or null without a slug.
	 *
	 * @param array<string,mixed> $row The stored Application.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function backfilledBaseRef(array $row): ?array {
		$slug = ($row['slug'] ?? null);
		if (is_string($slug) === false || trim($slug) === '') {
			return null;
		}

		$baseRef = ($row['baseRef'] ?? []);
		if (is_array($baseRef) === false) {
			$baseRef = [];
		}

		return array_replace($baseRef, ['kind' => 'fleet-app', 'id' => trim($slug)]);
	}//end backfilledBaseRef()

	/**
	 * Whether a version's `promotesTo` contains its own uuid, in any case (so also its urn or braced form).
	 *
	 * @param array<string,mixed> $row The stored ApplicationVersion.
	 *
	 * @return bool
	 */
	private static function promotesToItself(array $row): bool {
		$uuid = ObjectRecord::uuid(record: $row);
		$target = ($row['promotesTo'] ?? null);
		if ($uuid === null || is_string($target) === false) {
			return false;
		}

		return str_contains(strtolower($target), strtolower($uuid));
	}//end promotesToItself()

	/**
	 * Whether a version is user-scoped without an owner.
	 *
	 * @param array<string,mixed> $row The stored ApplicationVersion.
	 *
	 * @return bool
	 */
	private static function lacksOwner(array $row): bool {
		return ($row['scope'] ?? null) === 'user' && self::isBlank(value: ($row['owner'] ?? null)) === true;
	}//end lacksOwner()

	/**
	 * Whether a value is absent, empty or whitespace, as the register's `trim` condition reads it.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private static function isBlank(mixed $value): bool {
		return ($value === null || is_scalar($value) === true) && trim((string)$value) === '';
	}//end isBlank()
}//end class
