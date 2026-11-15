<?php

/**
 * Failure-path tests for the MoveDecisionTablesToOpenRegister repair step.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Repair
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

namespace OCA\Buildiq\Tests\Unit\Repair;

use OCA\Buildiq\Repair\MoveDecisionTablesToOpenRegister;
use OCA\Buildiq\Service\DecisionTableEvaluator;
use OCA\Buildiq\Service\DecisionTableShape;
use OCA\Buildiq\Service\ExpressionEvaluator;
use OCA\Buildiq\Service\StoredDecisionTables;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Service\Dmn\DecisionTableEvaluator as SharedEvaluator;
use OCA\OpenRegister\Service\Dmn\DecisionTableStore;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the refusal and degradation paths of {@see MoveDecisionTablesToOpenRegister}.
 *
 * @covers \OCA\Buildiq\Repair\MoveDecisionTablesToOpenRegister
 * @uses   \OCA\Buildiq\Service\StoredDecisionTables
 * @uses   \OCA\Buildiq\Service\DecisionTableShape
 * @uses   \OCA\Buildiq\Service\DecisionTableEvaluator
 * @uses   \OCA\Buildiq\Service\ExpressionEvaluator
 * @uses   \OCA\Buildiq\Support\ObjectRecord
 */
final class MoveDecisionTablesToOpenRegisterFailuresTest extends TestCase {

	/**
	 * @var IDBConnection&MockObject
	 */
	private IDBConnection&MockObject $db;

	/**
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * @var RegisterMapper&MockObject
	 */
	private RegisterMapper&MockObject $registerMapper;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * @var IOutput&MockObject
	 */
	private IOutput&MockObject $output;

	/**
	 * Messages sent to the output, as "level: message".
	 *
	 * @var list<string>
	 */
	private array $said = [];

	/**
	 * Wire the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->db = $this->createMock(IDBConnection::class);
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->output = $this->createMock(IOutput::class);
		$this->said = [];

		$this->output->method('info')->willReturnCallback(
			function (string $message): void {
				$this->said[] = 'info: ' . $message;
			}
		);
		$this->output->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->said[] = 'warning: ' . $message;
			}
		);

		// OpenRegister's register is readable and holds nothing, by default.
		$this->objects->method('searchObjectsBySlug')->willReturn([]);

	}//end setUp()

	/**
	 * Answer the schema-id lookups per slug.
	 *
	 * @param array<string,list<int>> $schemas Slug => schema ids.
	 *
	 * @return void
	 */
	private function schemas(array $schemas): void {
		$this->db->method('executeQuery')->willReturnCallback(
			function (string $sql, array $params = []) use ($schemas): IResult {
				$result = $this->createMock(IResult::class);
				$result->method('fetchAll')->willReturn($schemas[(string)($params[0] ?? '')] ?? []);
				return $result;
			}
		);

	}//end schemas()

	/**
	 * Resolve buildiq's register to id 7.
	 *
	 * @return void
	 */
	private function registerResolves(): void {
		$register = new Register();
		$register->setId(7);
		$this->registerMapper->method('find')->willReturn($register);

	}//end registerResolves()

	/**
	 * Build the step.
	 *
	 * @return MoveDecisionTablesToOpenRegister
	 */
	private function step(): MoveDecisionTablesToOpenRegister {
		$tables = new StoredDecisionTables(
			$this->createMock(DecisionTableStore::class),
			$this->objects,
			new DecisionTableShape(new DecisionTableEvaluator(new ExpressionEvaluator(), $this->createMock(SharedEvaluator::class))),
			$this->createMock(LoggerInterface::class),
		);

		return new MoveDecisionTablesToOpenRegister(
			$this->db,
			$this->registerMapper,
			$this->objects,
			$tables,
			$this->logger,
		);

	}//end step()

	/**
	 * The step names what it moves and where to.
	 *
	 * @return void
	 */
	public function testTheNameSaysWhatMovesWhere(): void {
		$this->assertSame(
			'Move buildiq decision tables into OpenRegister\'s decision-tables register',
			$this->step()->getName()
		);

	}//end testTheNameSaysWhatMovesWhere()

	/**
	 * An unreadable schema table moves nothing and touches nothing.
	 *
	 * @return void
	 */
	public function testAnUnreadableSchemaTableMovesNothing(): void {
		$this->db->method('executeQuery')->willThrowException(new RuntimeException('no such table'));
		$this->db->expects($this->never())->method('executeStatement');
		$this->objects->expects($this->never())->method('searchObjects');

		$this->logger->expects($this->exactly(2))->method('warning')
			->with('MoveDecisionTablesToOpenRegister: could not read the schema table.', $this->anything());

		$this->step()->run($this->output);

		$this->assertSame(['info: MoveDecisionTablesToOpenRegister: schema table unreadable; nothing moved.'], $this->said);

	}//end testAnUnreadableSchemaTableMovesNothing()

	/**
	 * The schema lookup asks for the slug under both application ids.
	 *
	 * @return void
	 */
	public function testTheSchemaLookupCoversBothApplicationIds(): void {
		$asked = [];
		$this->db->method('executeQuery')->willReturnCallback(
			function (string $sql, array $params = []) use (&$asked): IResult {
				$asked[] = [$sql, $params];
				$result = $this->createMock(IResult::class);
				$result->method('fetchAll')->willReturn([]);
				return $result;
			}
		);

		$this->step()->run($this->output);

		$sql = 'SELECT id FROM `*PREFIX*openregister_schemas` WHERE slug = ? AND application IN (?, ?)';
		$this->assertSame(
			[
				[$sql, ['decision-table', 'buildiq', 'openbuild']],
				[$sql, ['buildiq-decision-table-moved', 'buildiq', 'openbuild']],
			],
			$asked
		);
		$this->assertSame(
			['info: MoveDecisionTablesToOpenRegister: no buildiq decision-table schema on this install; nothing to do.'],
			$this->said
		);

	}//end testTheSchemaLookupCoversBothApplicationIds()

	/**
	 * An unresolvable buildiq register reads no rows; with nothing refused the
	 * old schema is still retired.
	 *
	 * @return void
	 */
	public function testAnUnresolvableRegisterReadsNoRows(): void {
		$this->schemas(schemas: ['decision-table' => [3]]);
		$this->registerMapper->method('find')->willThrowException(new RuntimeException('gone'));
		$this->objects->expects($this->never())->method('searchObjects');
		$this->db->expects($this->once())->method('executeStatement')
			->with('UPDATE `*PREFIX*openregister_schemas` SET slug = ? WHERE id = ?', ['buildiq-decision-table-moved', 3]);

		$this->logger->expects($this->once())->method('warning')
			->with('MoveDecisionTablesToOpenRegister: the buildiq register is not resolvable; nothing moved.', ['exception' => 'gone']);

		$this->step()->run($this->output);

		$this->assertSame(
			[
				'info: MoveDecisionTablesToOpenRegister: 0 moved, 0 already in OpenRegister, 0 refused.',
				'info: MoveDecisionTablesToOpenRegister: schema 3 renamed `decision-table` -> `buildiq-decision-table-moved`.',
			],
			$this->said
		);

	}//end testAnUnresolvableRegisterReadsNoRows()

	/**
	 * A schema whose rows cannot be read is logged and the next schema is still read.
	 *
	 * @return void
	 */
	public function testAnUnreadableSchemaIsSkippedAndTheNextRead(): void {
		$this->schemas(schemas: ['decision-table' => [3], 'buildiq-decision-table-moved' => [4]]);
		$this->registerResolves();

		$queried = [];
		$this->objects->method('searchObjects')->willReturnCallback(
			static function (array $query = []) use (&$queried): array {
				$queried[] = $query;
				if ($query['@self']['schema'] === 3) {
					throw new RuntimeException('read refused');
				}

				return [];
			}
		);

		$this->logger->expects($this->once())->method('warning')
			->with(
				'MoveDecisionTablesToOpenRegister: the old decision tables could not be read.',
				['schema' => 3, 'exception' => 'read refused']
			);

		$this->step()->run($this->output);

		$this->assertSame(
			[
				['@self' => ['register' => 7, 'schema' => 3]],
				['@self' => ['register' => 7, 'schema' => 4]],
			],
			$queried
		);

	}//end testAnUnreadableSchemaIsSkippedAndTheNextRead()

	/**
	 * A table without a ruleSetId cannot be keyed: it counts as refused and
	 * the old schema keeps its slug.
	 *
	 * @return void
	 */
	public function testATableWithoutARuleSetIsRefusedAndBlocksTheRename(): void {
		$this->schemas(schemas: ['decision-table' => [3]]);
		$this->registerResolves();
		$this->objects->method('searchObjects')->willReturn([['ruleSetId' => '  ', 'rules' => []]]);
		$this->objects->expects($this->never())->method('saveObject');
		$this->db->expects($this->never())->method('executeStatement');

		$this->logger->expects($this->once())->method('warning')
			->with('MoveDecisionTablesToOpenRegister: a decision table without a ruleSetId cannot be keyed; left in place.');

		$this->step()->run($this->output);

		$this->assertSame(
			[
				'info: MoveDecisionTablesToOpenRegister: 0 moved, 0 already in OpenRegister, 1 refused.',
				'warning: MoveDecisionTablesToOpenRegister: 1 table(s) were refused (see the log); the old schema keeps its slug until they move.',
			],
			$this->said
		);

	}//end testATableWithoutARuleSetIsRefusedAndBlocksTheRename()

	/**
	 * Two schemas still called `decision-table`: the step refuses to guess.
	 *
	 * @return void
	 */
	public function testTwoCurrentSchemasAreNotRenamed(): void {
		$this->schemas(schemas: ['decision-table' => [3, 5]]);
		$this->registerResolves();
		$this->objects->method('searchObjects')->willReturn([]);
		$this->db->expects($this->never())->method('executeStatement');

		$this->step()->run($this->output);

		$this->assertSame(
			[
				'info: MoveDecisionTablesToOpenRegister: 0 moved, 0 already in OpenRegister, 0 refused.',
				'warning: MoveDecisionTablesToOpenRegister: more than one buildiq `decision-table` schema; refusing to guess which to rename.',
			],
			$this->said
		);

	}//end testTwoCurrentSchemasAreNotRenamed()

	/**
	 * A failing rename is logged as an error and reported.
	 *
	 * @return void
	 */
	public function testAFailingRenameIsReported(): void {
		$this->schemas(schemas: ['decision-table' => [3]]);
		$this->registerResolves();
		$this->objects->method('searchObjects')->willReturn([]);
		$this->db->method('executeStatement')->willThrowException(new RuntimeException('read only'));

		$this->logger->expects($this->once())->method('error')
			->with(
				'MoveDecisionTablesToOpenRegister: renaming the old schema failed; two schemas now share the slug `decision-table`.',
				['id' => 3, 'exception' => 'read only']
			);

		$this->step()->run($this->output);

		$this->assertSame(
			[
				'info: MoveDecisionTablesToOpenRegister: 0 moved, 0 already in OpenRegister, 0 refused.',
				'warning: MoveDecisionTablesToOpenRegister: renaming the old schema failed; see the log.',
			],
			$this->said
		);

	}//end testAFailingRenameIsReported()

	/**
	 * With only the retired schema left, a held table counts as kept and no
	 * rename is issued.
	 *
	 * @return void
	 */
	public function testOnlyARetiredSchemaKeepsHeldTablesWithoutRenaming(): void {
		$this->schemas(schemas: ['buildiq-decision-table-moved' => [4]]);
		$this->registerResolves();

		$held = $this->createMock(ObjectEntityInterface::class);
		$held->method('getObject')->willReturn(['key' => 'buildiq.acme.pricing']);
		$held->method('getUuid')->willReturn('uuid-held');
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->objects->method('searchObjectsBySlug')->willReturn([$held]);
		$this->objects->method('searchObjects')->willReturn(
			[['ruleSetId' => 'Pricing', '@self' => ['organisation' => 'ACME']]]
		);
		$this->objects->expects($this->never())->method('saveObject');
		$this->db->expects($this->never())->method('executeStatement');

		$this->step()->run($this->output);

		$this->assertSame(['info: MoveDecisionTablesToOpenRegister: 0 moved, 1 already in OpenRegister, 0 refused.'], $this->said);

	}//end testOnlyARetiredSchemaKeepsHeldTablesWithoutRenaming()
}//end class
