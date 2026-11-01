<?php

/**
 * Buildiq MoveDecisionTablesToOpenRegister repair step.
 *
 * Moves every decision table buildiq kept in its own `decision-table` schema
 * into OpenRegister's `decision-tables` register (decision 196), where the
 * rule engine now reads it through OpenRegister's DecisionTableStore.
 *
 * Each table is written under the key of the rule set it belongs to,
 * `buildiq.<organisation>.<rule-set-slug>` (see StoredDecisionTables), in the
 * shape OpenRegister evaluates. A table whose key OpenRegister already holds
 * is left alone, so the step is idempotent and never overwrites a table
 * someone edited after the move.
 *
 * Only when every table moved does the old schema give up its slug: it is
 * renamed to `buildiq-decision-table-moved` in place. A schema slug is looked
 * up globally in places (the seed import's fallback among them), and two
 * schemas both called `decision-table` is the collision gate 106 exists to
 * stop. The rows stay on the renamed schema; nothing is deleted.
 *
 * Runs BEFORE InitializeSettings, so the old schema is still in the register
 * when its rows are read and has its new slug before the import looks the
 * slug up. When OpenRegister's register is not there yet (OpenRegister not yet
 * upgraded) nothing moves and nothing is renamed; the next upgrade retries.
 * Never throws: it runs under <install>, where an escaping exception aborts the
 * install.
 *
 * @category Repair
 * @package  OCA\Buildiq\Repair
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/decision-tables-live-in-openregister/specs/business-rules-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Repair;

use OCA\Buildiq\Service\RuleEngineService;
use OCA\Buildiq\Service\StoredDecisionTables;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\RegisterMapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Moves buildiq's decision tables into OpenRegister's decision-tables register.
 *
 * @spec openspec/changes/decision-tables-live-in-openregister/specs/business-rules-engine/spec.md
 */
class MoveDecisionTablesToOpenRegister implements IRepairStep {

	/**
	 * The slug buildiq's own decision-table schema carried.
	 */
	public const OLD_SLUG = 'decision-table';

	/**
	 * The slug the old schema carries once its tables have moved.
	 */
	public const RETIRED_SLUG = 'buildiq-decision-table-moved';

	/**
	 * The application ids the old schema may carry (mid-rename, see MigrateSchemaApplicationId).
	 */
	private const APPLICATIONS = ['buildiq', 'openbuild'];

	/**
	 * Constructor.
	 *
	 * @param IDBConnection          $db             Reads and renames the old schema row.
	 * @param RegisterMapper         $registerMapper Resolves buildiq's register.
	 * @param ObjectServiceInterface $objects        Reads the old rows.
	 * @param StoredDecisionTables   $tables         Writes a table into OpenRegister's register.
	 * @param LoggerInterface        $logger         Logger.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly RegisterMapper $registerMapper,
		private readonly ObjectServiceInterface $objects,
		private readonly StoredDecisionTables $tables,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The repair step's name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/decision-tables-live-in-openregister/specs/business-rules-engine/spec.md
	 */
	public function getName(): string {
		return 'Move buildiq decision tables into OpenRegister\'s decision-tables register';

	}//end getName()

	/**
	 * Move the tables, then retire the old schema's slug when all of them moved.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decision-tables-live-in-openregister/specs/business-rules-engine/spec.md
	 */
	public function run(IOutput $output): void {
		$current = $this->schemaIds(slug: self::OLD_SLUG);
		$retired = $this->schemaIds(slug: self::RETIRED_SLUG);
		if ($current === null || $retired === null) {
			$output->info('MoveDecisionTablesToOpenRegister: schema table unreadable; nothing moved.');
			return;
		}

		$schemaIds = array_values(array_unique(array_merge($current, $retired)));
		if ($schemaIds === []) {
			$output->info('MoveDecisionTablesToOpenRegister: no buildiq decision-table schema on this install; nothing to do.');
			return;
		}

		try {
			// Probe OpenRegister's register before reading anything: an
			// OpenRegister that has not imported it yet answers a throw here.
			$this->tables->exists(key: StoredDecisionTables::KEY_PREFIX . 'probe');
		} catch (Throwable $e) {
			$this->logger->warning(
				'MoveDecisionTablesToOpenRegister: OpenRegister\'s decision-tables register is not readable; tables stay where they are until the next upgrade.',
				['exception' => $e->getMessage()]
			);
			$output->warning('MoveDecisionTablesToOpenRegister: OpenRegister\'s decision-tables register is not there yet; nothing moved.');
			return;
		}

		$counts = ['moved' => 0, 'kept' => 0, 'failed' => 0];
		foreach ($this->oldRows(schemaIds: $schemaIds) as $row) {
			++$counts[$this->moveOne(row: $row)];
		}

		$failed = $counts['failed'];
		$output->info(
			'MoveDecisionTablesToOpenRegister: ' . $counts['moved'] . ' moved, ' . $counts['kept'] . ' already in OpenRegister, ' . $failed . ' refused.'
		);

		if ($failed > 0) {
			$output->warning(
				'MoveDecisionTablesToOpenRegister: ' . $failed . ' table(s) were refused (see the log); the old schema keeps its slug until they move.'
			);
			return;
		}

		$this->retire(schemaIds: $current, output: $output);

	}//end run()

	/**
	 * Write one old row into OpenRegister's register.
	 *
	 * @param array<string,mixed> $row The old row, as the object service serialises it.
	 *
	 * @return string `moved`, `kept` (the key is already held) or `failed`.
	 */
	private function moveOne(array $row): string {
		$ruleSetSlug = trim((string)($row['ruleSetId'] ?? ''));
		if ($ruleSetSlug === '') {
			$this->logger->warning('MoveDecisionTablesToOpenRegister: a decision table without a ruleSetId cannot be keyed; left in place.');
			return 'failed';
		}

		$self = [];
		if (is_array($row['@self'] ?? null) === true) {
			$self = $row['@self'];
		}

		$ruleSet = [
			'slug' => $ruleSetSlug,
			'name' => $ruleSetSlug,
			'@self' => ['organisation' => (string)($self['organisation'] ?? '')],
		];
		$key = $this->tables->keyOfRuleSet(ruleSet: $ruleSet);

		try {
			if ($this->tables->exists(key: $key) === true) {
				// Either an earlier run moved it, or a rule set had two tables:
				// the engine only ever evaluated the first, so the second stays.
				return 'kept';
			}

			$this->tables->save(ruleSet: $ruleSet, table: $row);
		} catch (Throwable $e) {
			$this->logger->warning(
				'MoveDecisionTablesToOpenRegister: OpenRegister refused the table of rule set "' . $ruleSetSlug . '"; left in place.',
				['key' => $key, 'exception' => $e->getMessage()]
			);
			return 'failed';
		}

		return 'moved';

	}//end moveOne()

	/**
	 * Every row of the old schema(s), across organisations.
	 *
	 * @param list<int> $schemaIds The old schema ids.
	 *
	 * @return list<array<string,mixed>> The rows.
	 */
	private function oldRows(array $schemaIds): array {
		try {
			$register = $this->registerMapper->find(RuleEngineService::REGISTER_SLUG, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->warning(
				'MoveDecisionTablesToOpenRegister: the buildiq register is not resolvable; nothing moved.',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		$rows = [];
		foreach ($schemaIds as $schemaId) {
			try {
				$found = $this->objects->searchObjects(
					query: ['@self' => ['register' => (int)$register->getId(), 'schema' => $schemaId]],
					_rbac: false,
					_multitenancy: false
				);
			} catch (Throwable $e) {
				$this->logger->warning(
					'MoveDecisionTablesToOpenRegister: the old decision tables could not be read.',
					['schema' => $schemaId, 'exception' => $e->getMessage()]
				);
				continue;
			}

			foreach ((array)$found as $object) {
				$data = $this->normalise(object: $object);
				if ($data !== []) {
					$rows[] = $data;
				}
			}
		}//end foreach

		return $rows;

	}//end oldRows()

	/**
	 * Rename the old schema's slug so `decision-table` names only OpenRegister's schema.
	 *
	 * @param list<int> $schemaIds The buildiq schemas still called `decision-table`.
	 * @param IOutput   $output    Repair output.
	 *
	 * @return void
	 */
	private function retire(array $schemaIds, IOutput $output): void {
		if ($schemaIds === []) {
			return;
		}

		if (count($schemaIds) > 1) {
			$output->warning('MoveDecisionTablesToOpenRegister: more than one buildiq `decision-table` schema; refusing to guess which to rename.');
			return;
		}

		try {
			$this->db->executeStatement(
				'UPDATE `*PREFIX*openregister_schemas` SET slug = ? WHERE id = ?',
				[self::RETIRED_SLUG, $schemaIds[0]]
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'MoveDecisionTablesToOpenRegister: renaming the old schema failed; two schemas now share the slug `decision-table`.',
				['id' => $schemaIds[0], 'exception' => $e->getMessage()]
			);
			$output->warning('MoveDecisionTablesToOpenRegister: renaming the old schema failed; see the log.');
			return;
		}

		$output->info(
			'MoveDecisionTablesToOpenRegister: schema ' . $schemaIds[0] . ' renamed `' . self::OLD_SLUG . '` -> `' . self::RETIRED_SLUG . '`.'
		);

	}//end retire()

	/**
	 * The ids of buildiq's schemas carrying a slug.
	 *
	 * @param string $slug The slug.
	 *
	 * @return list<int>|null The ids, or null when the schema table is unreadable.
	 */
	private function schemaIds(string $slug): ?array {
		$placeholders = implode(', ', array_fill(0, count(self::APPLICATIONS), '?'));

		try {
			$rows = $this->db->executeQuery(
				'SELECT id FROM `*PREFIX*openregister_schemas` WHERE slug = ? AND application IN (' . $placeholders . ')',
				array_merge([$slug], self::APPLICATIONS)
			)->fetchAll(\PDO::FETCH_COLUMN);
		} catch (Throwable $e) {
			$this->logger->warning(
				'MoveDecisionTablesToOpenRegister: could not read the schema table.',
				['slug' => $slug, 'exception' => $e->getMessage()]
			);
			return null;
		}

		return array_values(array_map('intval', (array)$rows));

	}//end schemaIds()

	/**
	 * An object as an array, with its `@self` metadata when the entity has it.
	 *
	 * @param mixed $object The search result.
	 *
	 * @return array<string,mixed> The data, or an empty array.
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
