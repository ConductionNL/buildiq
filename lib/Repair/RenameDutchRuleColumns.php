<?php

/**
 * OpenBuild RenameDutchRuleColumns Repair Step
 *
 * Moves the stored data of the business-rules schemas from their Dutch column
 * names to the English ones the register now declares.
 *
 * WHY THIS IS NEEDED AT ALL. OpenRegister does not store an object as a JSON
 * blob keyed by property name — each schema property is a real, snake_cased
 * COLUMN in the per-schema shard table `oc_openregister_table_{register}_{schema}`.
 * On schema sync, MagicMapper ADDS a column when the snake_cased property name
 * is absent, and it NEVER renames: there is not a single `RENAME COLUMN` in
 * openregister. Its only DROP path removes a camelCase duplicate whose
 * snake_case twin already exists.
 *
 * So renaming `naam` to `name` in the register, on its own, produces:
 *   1. the schema declares `name`;
 *   2. MagicMapper adds an empty `name` column;
 *   3. the data stays in `naam`, which is never dropped and never read;
 *   4. every read of that property returns null.
 *
 * No error, no data loss, and invisible to every gate and test — the suites
 * assert against fixtures, not against migrated rows. The only symptom is a
 * field that has quietly gone blank. This step closes that gap.
 *
 * SAFETY. Non-destructive and idempotent:
 *   - a column is renamed only when the OLD one exists and the NEW one does not;
 *   - where MagicMapper has already added an empty NEW column, the data is
 *     copied across and the old column is left in place rather than dropped,
 *     so the step is reversible and a re-run is a no-op;
 *   - nothing is deleted, so an install that has already been read from is
 *     recoverable either way.
 *
 * SCOPE. Resolves shard tables at runtime, because their names carry numeric
 * register and schema ids that differ per install. A schema registered in more
 * than one register has one shard table per register and all of them are
 * migrated — openbuild's are in two (observed: registers 206 and 2421), so
 * handling only the first would migrate half the data.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Repair
 * @package  OCA\OpenBuild\Repair
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

namespace OCA\OpenBuild\Repair;

use OCP\DB\Exception;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Rename the Dutch business-rules columns to their English equivalents.
 *
 * @spec openspec/specs/business-rules-engine/spec.md
 */
class RenameDutchRuleColumns implements IRepairStep {
	/**
	 * Schema titles whose shard tables carry the renamed columns.
	 *
	 * @var array<int, string>
	 */
	private const SCHEMA_TITLES = [
		'RuleSet',
		'DecisionTable',
		'ConditionActionRule',
		'RuleExecutionLog',
		'TestCase',
	];

	/**
	 * Old snake_case column name => new snake_case column name.
	 *
	 * Snake_case, not camelCase: MagicMapper stores `afdoeningsToelichting` as
	 * `afdoenings_toelichting`, so the English `validFrom` is `valid_from`.
	 * Renaming to the camelCase form would create a column the mapper then
	 * treats as a duplicate and drops.
	 *
	 * @var array<string, string>
	 */
	private const COLUMN_MAP = [
		'naam' => 'name',
		'beschrijving' => 'description',
		'versie' => 'version',
		'eigenaar_app' => 'owner_app',
		'geactiveerd_op' => 'activated_on',
		'ingangsdatum' => 'valid_from',
		'einddatum' => 'valid_until',
		'regels' => 'rules',
		'condities' => 'conditions',
		'waardes' => 'values',
		'defaultwaarde' => 'default_value',
		'expressie_pad' => 'expression_path',
		'prioriteit' => 'priority',
		'conditie' => 'condition',
		'acties' => 'actions',
		'actief' => 'active',
		'geraakt_regels' => 'triggered_rules',
		'rule_set_versie' => 'rule_set_version',
		'tijdstip' => 'timestamp',
		'output_resultaat' => 'output_result',
		'executie_duur_ms' => 'execution_duration_ms',
		'fouten' => 'errors',
		'verwacht_resultaat' => 'expected_result',
		'laatste_test_resultaat' => 'last_test_result',
		'laatste_test_output' => 'last_test_output',
	];

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Human-readable step name.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/business-rules-engine/spec.md
	 */
	public function getName(): string {
		return 'Move business-rules data from the Dutch columns to the English ones';
	}//end getName()

	/**
	 * Run the column migration across every shard table of every affected schema.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/business-rules-engine/spec.md
	 */
	public function run(IOutput $output): void {
		$tables = $this->shardTables();
		if ($tables === []) {
			$output->info('RenameDutchRuleColumns: no business-rules shard tables on this install; nothing to do.');
			return;
		}

		$renamed = 0;
		$copied = 0;

		foreach ($tables as $table) {
			$columns = $this->columnsOf(table: $table);
			$qTable = $this->quote(identifier: $table);

			foreach (self::COLUMN_MAP as $old => $new) {
				$hasOld = in_array($old, $columns, true);
				$hasNew = in_array($new, $columns, true);

				if ($hasOld === false) {
					// Either already migrated, or this schema never had the property.
					continue;
				}

				$qOld = $this->quote(identifier: $old);
				$qNew = $this->quote(identifier: $new);

				if ($hasNew === false) {
					// Clean case: the mapper has not yet added the English column.
					$sql = 'ALTER TABLE ' . $qTable . ' RENAME COLUMN ' . $qOld . ' TO ' . $qNew;
					if ($this->exec(sql: $sql) === true) {
						$renamed++;
					}

					continue;
				}

				// The mapper already added an empty English column. Copy the data
				// across and LEAVE the Dutch column in place — dropping it here
				// would make the step irreversible for no benefit.
				$sql = 'UPDATE ' . $qTable . ' SET ' . $qNew . ' = ' . $qOld
					. ' WHERE ' . $qNew . ' IS NULL AND ' . $qOld . ' IS NOT NULL';
				if ($this->exec(sql: $sql) === true) {
					$copied++;
				}
			}//end foreach
		}//end foreach

		$output->info(
			'RenameDutchRuleColumns: ' . $renamed . ' column(s) renamed, '
			. $copied . ' column(s) back-filled, across ' . count($tables) . ' shard table(s).'
		);

	}//end run()

	/**
	 * Resolve the shard tables for the affected schemas, across every register.
	 *
	 * @return array<int, string>
	 */
	private function shardTables(): array {
		$placeholders = implode(',', array_fill(0, count(self::SCHEMA_TITLES), '?'));

		try {
			$ids = $this->db->executeQuery(
				'SELECT id FROM `*PREFIX*openregister_schemas` WHERE title IN (' . $placeholders . ')',
				self::SCHEMA_TITLES
			)->fetchAll(\PDO::FETCH_COLUMN);
		} catch (Exception $e) {
			$this->logger->warning(
				'RenameDutchRuleColumns: could not resolve schema ids; skipping.',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		if ($ids === []) {
			return [];
		}

		// Table discovery goes through information_schema, NOT IDBConnection.
		// OCP\IDBConnection exposes neither getSchema() nor getPrefix(); both
		// exist only on the concrete OC\DB\Connection. Calling them is a runtime
		// fatal that `php -l` and phpcs both report as clean — only phpstan
		// catches it. Pattern follows openregister's own RegisterService: anchor
		// on the `openregister_table_` MARKER, never on a computed prefix.
		try {
			$stmt = $this->db->prepare(
				'SELECT table_name FROM information_schema.tables WHERE table_name LIKE :pattern'
			);
			$stmt->bindValue('pattern', '%openregister\_table\_%');
			$stmt->execute();
		} catch (\Throwable $e) {
			$this->logger->warning(
				'RenameDutchRuleColumns: could not list tables; skipping.',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		// Suffix match on the schema id, so every register's shard is found.
		$suffixes = [];
		foreach ($ids as $id) {
			$suffixes[] = '_' . ((int)$id);
		}

		$tables = [];
		while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
			$name = (string)($row['table_name'] ?? '');
			if ($this->isShardOfSchema(table: $name, suffixes: $suffixes) === true) {
				$tables[] = $name;
			}
		}

		return array_values(array_unique($tables));
	}//end shardTables()

	/**
	 * Whether a table name is an openregister shard ending in one of the ids.
	 *
	 * @param string $table Table name from information_schema.
	 * @param array<int, string> $suffixes `_<schemaId>` suffixes to accept.
	 *
	 * @return bool
	 */
	private function isShardOfSchema(string $table, array $suffixes): bool {
		if ($table === '' || strpos($table, 'openregister_table_') === false) {
			return false;
		}

		foreach ($suffixes as $suffix) {
			if (substr($table, -strlen($suffix)) === $suffix) {
				return true;
			}
		}

		return false;
	}//end isShardOfSchema()

	/**
	 * List the column names of a table.
	 *
	 * @param string $table Unprefixed-safe table name.
	 *
	 * @return array<int, string>
	 */
	private function columnsOf(string $table): array {
		// Queried from information_schema — IDBConnection has no getSchema().
		try {
			$stmt = $this->db->prepare(
				'SELECT column_name FROM information_schema.columns WHERE table_name = :table'
			);
			$stmt->bindValue('table', $table);
			$stmt->execute();
		} catch (\Throwable $e) {
			$this->logger->warning(
				'RenameDutchRuleColumns: could not read columns; skipping table.',
				['table' => $table, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$columns = [];
		while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
			$name = (string)($row['column_name'] ?? '');
			if ($name !== '') {
				$columns[] = $name;
			}
		}

		return $columns;
	}//end columnsOf()

	/**
	 * Execute one DDL/DML statement, logging and swallowing failure.
	 *
	 * A failure here must not abort the whole repair run: the remaining tables
	 * are independent, and the un-migrated column is still readable.
	 *
	 * @param string $sql The statement.
	 *
	 * @return bool Whether it succeeded.
	 */
	private function exec(string $sql): bool {
		try {
			$this->db->executeStatement($sql);
			return true;
		} catch (Exception $e) {
			$this->logger->warning(
				'RenameDutchRuleColumns: statement failed; leaving the column as it was.',
				['sql' => $sql, 'exception' => $e->getMessage()]
			);
			return false;
		}

	}//end exec()

	/**
	 * Quote an identifier for the active platform.
	 *
	 * @param string $identifier Table or column name.
	 *
	 * @return string
	 */
	private function quote(string $identifier): string {
		return $this->db->getDatabasePlatform()->quoteSingleIdentifier($identifier);
	}//end quote()
}//end class
