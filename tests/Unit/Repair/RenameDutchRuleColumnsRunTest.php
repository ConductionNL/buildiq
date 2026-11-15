<?php

/**
 * Behaviour tests for RenameDutchRuleColumns::run().
 *
 * Drives the whole step against a mocked IDBConnection: schema-id lookup,
 * shard-table discovery, column listing, and the rename / back-fill / skip
 * decisions per column, including the failure paths that must not abort the run.
 *
 * @category Tests
 * @package  OCA\Buildiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Repair;

use OCA\Buildiq\Repair\RenameDutchRuleColumns;
use OCP\DB\Exception as DbException;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Buildiq\Repair\RenameDutchRuleColumns
 */
class RenameDutchRuleColumnsRunTest extends TestCase {
	/**
	 * Mocked database connection.
	 *
	 * @var IDBConnection&MockObject
	 */
	private IDBConnection $db;

	/**
	 * Mocked logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Mocked repair output.
	 *
	 * @var IOutput&MockObject
	 */
	private IOutput $output;

	/**
	 * Statements passed to executeStatement(), in order.
	 *
	 * @var array<int, string>
	 */
	private array $executed = [];

	/**
	 * Set up the doubles shared by every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->db = $this->createMock(IDBConnection::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->output = $this->createMock(IOutput::class);

		$this->db->method('getDatabasePlatform')->willReturn(
			new class {
				/**
				 * Quote like MySQL does.
				 *
				 * @param string $identifier Identifier.
				 *
				 * @return string
				 */
				public function quoteSingleIdentifier(string $identifier): string {
					return '`' . $identifier . '`';
				}
			}
		);
	}//end setUp()

	/**
	 * Build a prepared statement that yields the given rows, then false.
	 *
	 * @param array<int, array<string, string>> $rows Rows to return.
	 *
	 * @return IPreparedStatement&MockObject
	 */
	private function statement(array $rows): IPreparedStatement {
		$stmt = $this->createMock(IPreparedStatement::class);
		$stmt->method('fetch')->willReturnOnConsecutiveCalls(...array_merge($rows, [false]));
		return $stmt;
	}//end statement()

	/**
	 * Make the schema-id lookup return the given ids.
	 *
	 * @param array<int, int|string> $ids Schema ids.
	 *
	 * @return void
	 */
	private function schemaIds(array $ids): void {
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->with(\PDO::FETCH_COLUMN)->willReturn($ids);
		$this->db->method('executeQuery')->willReturn($result);
	}//end schemaIds()

	/**
	 * Wire prepare(): the table listing, then one column listing per table.
	 *
	 * @param array<int, string>                $tables  Table names information_schema reports.
	 * @param array<string, array<int, string>> $columns Column names per table.
	 *
	 * @return void
	 */
	private function catalogue(array $tables, array $columns): void {
		$this->db->method('prepare')->willReturnCallback(
			function (string $sql) use ($tables, $columns): IPreparedStatement {
				if (str_contains($sql, 'information_schema.tables') === true) {
					$rows = array_map(fn (string $t): array => ['table_name' => $t], $tables);
					return $this->statement(rows: $rows);
				}

				$stmt = $this->createMock(IPreparedStatement::class);
				$stmt->method('bindValue')->willReturnCallback(
					function (string $param, string $value) use ($stmt, $columns): bool {
						$rows = array_map(
							fn (string $c): array => ['column_name' => $c],
							$columns[$value] ?? []
						);
						$stmt->method('fetch')->willReturnOnConsecutiveCalls(...array_merge($rows, [false]));
						return true;
					}
				);
				return $stmt;
			}
		);
	}//end catalogue()

	/**
	 * Record every executeStatement() call, failing those matching $failOn.
	 *
	 * @param string|null $failOn Substring of the SQL that should throw.
	 *
	 * @return void
	 */
	private function recordStatements(?string $failOn = null): void {
		$this->db->method('executeStatement')->willReturnCallback(
			function (string $sql) use ($failOn): int {
				if ($failOn !== null && str_contains($sql, $failOn) === true) {
					throw new DbException('refused');
				}

				$this->executed[] = $sql;
				return 0;
			}
		);
	}//end recordStatements()

	/**
	 * Build the step under test.
	 *
	 * @return RenameDutchRuleColumns
	 */
	private function step(): RenameDutchRuleColumns {
		return new RenameDutchRuleColumns($this->db, $this->logger);
	}//end step()

	/**
	 * The step has a stable, descriptive name.
	 *
	 * @return void
	 */
	public function testTheNameDescribesTheMove(): void {
		$this->assertSame(
			'Move business-rules data from the Dutch columns to the English ones',
			$this->step()->getName()
		);
	}//end testTheNameDescribesTheMove()

	/**
	 * The schema lookup asks for exactly the five business-rules schemas.
	 *
	 * @return void
	 */
	public function testTheLookupAsksForTheFiveRuleSchemas(): void {
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn([]);
		$this->db->expects($this->once())->method('executeQuery')
			->with(
				'SELECT id FROM `*PREFIX*openregister_schemas` WHERE title IN (?,?,?,?,?)',
				['RuleSet', 'DecisionTable', 'ConditionActionRule', 'RuleExecutionLog', 'TestCase']
			)
			->willReturn($result);
		$this->db->expects($this->never())->method('prepare');

		$this->output->expects($this->once())->method('info')
			->with('RenameDutchRuleColumns: no business-rules shard tables on this install; nothing to do.');

		$this->step()->run($this->output);
	}//end testTheLookupAsksForTheFiveRuleSchemas()

	/**
	 * A failing schema lookup is logged and treated as nothing to do.
	 *
	 * @return void
	 */
	public function testAFailingSchemaLookupIsLoggedAndSkipped(): void {
		$this->db->method('executeQuery')->willThrowException(new DbException('no table'));
		$this->db->expects($this->never())->method('prepare');
		$this->db->expects($this->never())->method('executeStatement');

		$this->logger->expects($this->once())->method('warning')
			->with('RenameDutchRuleColumns: could not resolve schema ids; skipping.', ['exception' => 'no table']);
		$this->output->expects($this->once())->method('info')
			->with('RenameDutchRuleColumns: no business-rules shard tables on this install; nothing to do.');

		$this->step()->run($this->output);
	}//end testAFailingSchemaLookupIsLoggedAndSkipped()

	/**
	 * A failing table listing is logged and treated as nothing to do.
	 *
	 * @return void
	 */
	public function testAFailingTableListingIsLoggedAndSkipped(): void {
		$this->schemaIds(ids: [7]);
		$this->db->method('prepare')->willThrowException(new \RuntimeException('denied'));
		$this->db->expects($this->never())->method('executeStatement');

		$this->logger->expects($this->once())->method('warning')
			->with('RenameDutchRuleColumns: could not list tables; skipping.', ['exception' => 'denied']);
		$this->output->expects($this->once())->method('info')
			->with('RenameDutchRuleColumns: no business-rules shard tables on this install; nothing to do.');

		$this->step()->run($this->output);
	}//end testAFailingTableListingIsLoggedAndSkipped()

	/**
	 * A column present only in Dutch is renamed in place.
	 *
	 * @return void
	 */
	public function testADutchOnlyColumnIsRenamed(): void {
		$this->schemaIds(ids: ['7']);
		$this->catalogue(
			tables: ['oc_openregister_table_206_7'],
			columns: ['oc_openregister_table_206_7' => ['id', 'naam']]
		);
		$this->recordStatements();

		$this->output->expects($this->once())->method('info')
			->with('RenameDutchRuleColumns: 1 column(s) renamed, 0 column(s) back-filled, across 1 shard table(s).');

		$this->step()->run($this->output);

		$this->assertSame(
			['ALTER TABLE `oc_openregister_table_206_7` RENAME COLUMN `naam` TO `name`'],
			$this->executed
		);
	}//end testADutchOnlyColumnIsRenamed()

	/**
	 * Where the English column already exists, data is copied and the Dutch column kept.
	 *
	 * @return void
	 */
	public function testAnExistingEnglishColumnIsBackFilledNotDropped(): void {
		$this->schemaIds(ids: [7]);
		$this->catalogue(
			tables: ['oc_openregister_table_206_7'],
			columns: ['oc_openregister_table_206_7' => ['naam', 'name', 'actief']]
		);
		$this->recordStatements();

		$this->output->expects($this->once())->method('info')
			->with('RenameDutchRuleColumns: 1 column(s) renamed, 1 column(s) back-filled, across 1 shard table(s).');

		$this->step()->run($this->output);

		$this->assertSame(
			[
				'UPDATE `oc_openregister_table_206_7` SET `name` = `naam` WHERE `name` IS NULL AND `naam` IS NOT NULL',
				'ALTER TABLE `oc_openregister_table_206_7` RENAME COLUMN `actief` TO `active`',
			],
			$this->executed
		);
		foreach ($this->executed as $sql) {
			$this->assertStringNotContainsString('DROP', $sql);
		}
	}//end testAnExistingEnglishColumnIsBackFilledNotDropped()

	/**
	 * An already-migrated table produces no statements.
	 *
	 * @return void
	 */
	public function testAnAlreadyMigratedTableIsLeftAlone(): void {
		$this->schemaIds(ids: [7]);
		$this->catalogue(
			tables: ['oc_openregister_table_206_7'],
			columns: ['oc_openregister_table_206_7' => ['id', 'name', 'description', 'active']]
		);
		$this->db->expects($this->never())->method('executeStatement');

		$this->output->expects($this->once())->method('info')
			->with('RenameDutchRuleColumns: 0 column(s) renamed, 0 column(s) back-filled, across 1 shard table(s).');

		$this->step()->run($this->output);
	}//end testAnAlreadyMigratedTableIsLeftAlone()

	/**
	 * Every register's shard of an affected schema is migrated; others are not.
	 *
	 * @return void
	 */
	public function testEveryRegistersShardIsMigratedAndOthersIgnored(): void {
		$this->schemaIds(ids: [7]);
		$this->catalogue(
			tables: [
				'oc_openregister_table_206_7',
				'oc_openregister_table_2421_7',
				'oc_openregister_table_206_17',
				'oc_openregister_table_206_7',
			],
			columns: [
				'oc_openregister_table_206_7' => ['versie'],
				'oc_openregister_table_2421_7' => ['versie'],
				'oc_openregister_table_206_17' => ['versie'],
			]
		);
		$this->recordStatements();

		$this->output->expects($this->once())->method('info')
			->with('RenameDutchRuleColumns: 2 column(s) renamed, 0 column(s) back-filled, across 2 shard table(s).');

		$this->step()->run($this->output);

		$this->assertSame(
			[
				'ALTER TABLE `oc_openregister_table_206_7` RENAME COLUMN `versie` TO `version`',
				'ALTER TABLE `oc_openregister_table_2421_7` RENAME COLUMN `versie` TO `version`',
			],
			$this->executed
		);
	}//end testEveryRegistersShardIsMigratedAndOthersIgnored()

	/**
	 * A failed statement is logged, not counted, and the run continues.
	 *
	 * @return void
	 */
	public function testAFailedStatementIsLoggedAndTheRunContinues(): void {
		$this->schemaIds(ids: [7]);
		$this->catalogue(
			tables: ['oc_openregister_table_206_7'],
			columns: ['oc_openregister_table_206_7' => ['naam', 'versie']]
		);
		$this->recordStatements(failOn: '`naam`');

		$this->logger->expects($this->once())->method('warning')
			->with(
				'RenameDutchRuleColumns: statement failed; leaving the column as it was.',
				[
					'sql' => 'ALTER TABLE `oc_openregister_table_206_7` RENAME COLUMN `naam` TO `name`',
					'exception' => 'refused',
				]
			);
		$this->output->expects($this->once())->method('info')
			->with('RenameDutchRuleColumns: 1 column(s) renamed, 0 column(s) back-filled, across 1 shard table(s).');

		$this->step()->run($this->output);

		$this->assertSame(
			['ALTER TABLE `oc_openregister_table_206_7` RENAME COLUMN `versie` TO `version`'],
			$this->executed
		);
	}//end testAFailedStatementIsLoggedAndTheRunContinues()

	/**
	 * A table whose columns cannot be read is skipped with a warning.
	 *
	 * @return void
	 */
	public function testUnreadableColumnsSkipThatTable(): void {
		$this->schemaIds(ids: [7]);
		$this->db->method('prepare')->willReturnCallback(
			function (string $sql): IPreparedStatement {
				if (str_contains($sql, 'information_schema.tables') === true) {
					return $this->statement(rows: [['table_name' => 'oc_openregister_table_206_7']]);
				}

				throw new \RuntimeException('columns hidden');
			}
		);
		$this->db->expects($this->never())->method('executeStatement');

		$this->logger->expects($this->once())->method('warning')
			->with(
				'RenameDutchRuleColumns: could not read columns; skipping table.',
				['table' => 'oc_openregister_table_206_7', 'exception' => 'columns hidden']
			);
		$this->output->expects($this->once())->method('info')
			->with('RenameDutchRuleColumns: 0 column(s) renamed, 0 column(s) back-filled, across 1 shard table(s).');

		$this->step()->run($this->output);
	}//end testUnreadableColumnsSkipThatTable()
}//end class
