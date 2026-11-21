<?php

/**
 * Buildiq MoveDecisionTablesToOpenRegister unit tests.
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
 * Tests for {@see MoveDecisionTablesToOpenRegister}.
 *
 * @spec openspec/changes/decision-tables-live-in-openregister/specs/business-rules-engine/spec.md
 */
final class MoveDecisionTablesToOpenRegisterTest extends TestCase {

	/**
	 * @var IDBConnection&MockObject
	 */
	private IDBConnection&MockObject $db;

	/**
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * Rows OpenRegister's decision-tables register holds, by key.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $held = [];

	/**
	 * Slug renames the step issued, as [slug, id].
	 *
	 * @var list<array{0:mixed,1:mixed}>
	 */
	private array $renames = [];

	/**
	 * Wire the step with a modelled database and OpenRegister.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->db = $this->createMock(IDBConnection::class);
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->held = [];
		$this->renames = [];

		$this->db->method('executeStatement')->willReturnCallback(
			function (string $sql, array $params = []): int {
				$this->renames[] = [$params[0] ?? null, $params[1] ?? null];
				return 1;
			}
		);

	}//end setUp()

	/**
	 * The step, with old schemas per slug and the old rows the search returns.
	 *
	 * @param array<string,list<int>>        $schemas  Slug => buildiq schema ids.
	 * @param list<array<string,mixed>>      $oldRows  The old decision-table rows.
	 * @param bool                           $orReady  Whether OpenRegister's register is readable.
	 * @param list<string>                   $refuse   Keys OpenRegister refuses to save.
	 *
	 * @return MoveDecisionTablesToOpenRegister
	 */
	private function step(array $schemas, array $oldRows, bool $orReady = true, array $refuse = []): MoveDecisionTablesToOpenRegister {
		$this->db->method('executeQuery')->willReturnCallback(
			function (string $sql, array $params = []) use ($schemas): IResult {
				$result = $this->createMock(IResult::class);
				$result->method('fetchAll')->willReturn(($schemas[(string)($params[0] ?? '')] ?? []));
				return $result;
			}
		);

		$this->objects->method('searchObjects')->willReturnCallback(
			static function (array $query = [], bool $_rbac = true, bool $_multitenancy = true) use ($oldRows): array {
				// Every organisation's rows, so the step must read unscoped.
				if ($_multitenancy === true || $_rbac === true) {
					return [];
				}

				return $oldRows;
			}
		);

		$this->objects->method('searchObjectsBySlug')->willReturnCallback(
			function () use ($orReady): array {
				if ($orReady === false) {
					throw new RuntimeException('searchObjectsBySlug: register slug not found in caller organisation: decision-tables');
				}

				$entities = [];
				foreach ($this->held as $key => $row) {
					$entity = $this->createMock(ObjectEntityInterface::class);
					$entity->method('getObject')->willReturn($row);
					$entity->method('getUuid')->willReturn('uuid-' . $key);
					$entities[] = $entity;
				}

				return $entities;
			}
		);

		$this->objects->method('saveObject')->willReturnCallback(
			function (array $object) use ($refuse): ObjectEntityInterface {
				if (in_array($object['key'], $refuse, true) === true) {
					throw new RuntimeException('The decision table "' . $object['key'] . '" cannot be run.');
				}

				$this->held[$object['key']] = $object;
				return $this->createMock(ObjectEntityInterface::class);
			}
		);

		$register = new Register();
		$register->setId(7);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn($register);

		$tables = new StoredDecisionTables(
			$this->createMock(DecisionTableStore::class),
			$this->objects,
			new DecisionTableShape(new DecisionTableEvaluator(new ExpressionEvaluator(), $this->createMock(SharedEvaluator::class))),
			$this->createMock(LoggerInterface::class),
		);

		return new MoveDecisionTablesToOpenRegister(
			$this->db,
			$registerMapper,
			$this->objects,
			$tables,
			$this->createMock(LoggerInterface::class),
		);

	}//end step()

	/**
	 * An old row, as the object service serialises it.
	 *
	 * @param string $ruleSet      The rule set slug.
	 * @param string $organisation The organisation holding it.
	 *
	 * @return array<string,mixed>
	 */
	private function oldRow(string $ruleSet, string $organisation): array {
		return [
			'ruleSetId' => $ruleSet,
			'hitPolicy' => 'first',
			'inputColumns' => [['name' => 'age', 'type' => 'integer', 'expressionPath' => 'applicant.age']],
			'outputColumns' => [['name' => 'decision', 'type' => 'string', 'defaultValue' => 'deny']],
			'rules' => [['conditions' => ['age' => '>=18'], 'values' => ['decision' => 'approve'], 'label' => 'adult']],
			'@self' => ['id' => 'old-' . $ruleSet, 'organisation' => $organisation],
		];

	}//end oldRow()

	/**
	 * Every old table moves under its rule set's key, and then the old schema
	 * gives up the slug `decision-table`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decision-tables-live-in-openregister/specs/business-rules-engine/spec.md
	 */
	public function testEveryTableMovesAndTheOldSchemaIsRenamed(): void {
		$step = $this->step(
			schemas: ['decision-table' => [102]],
			oldRows: [$this->oldRow('loan', 'org-a'), $this->oldRow('loan', 'org-b')]
		);

		$step->run($this->createMock(IOutput::class));

		$this->assertSame(['buildiq.org-a.loan', 'buildiq.org-b.loan'], array_keys($this->held));
		$this->assertSame(['>=18'], $this->held['buildiq.org-a.loan']['rules'][0]['inputEntries']);
		$this->assertSame('buildiq', $this->held['buildiq.org-a.loan']['owner']);
		$this->assertSame([[MoveDecisionTablesToOpenRegister::RETIRED_SLUG, 102]], $this->renames);

	}//end testEveryTableMovesAndTheOldSchemaIsRenamed()

	/**
	 * A key OpenRegister already holds is left alone: a second run moves
	 * nothing and overwrites no table edited after the first.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decision-tables-live-in-openregister/specs/business-rules-engine/spec.md
	 */
	public function testATableAlreadyInOpenRegisterIsNotOverwritten(): void {
		$this->held['buildiq.org-a.loan'] = ['key' => 'buildiq.org-a.loan', 'name' => 'edited after the move'];
		$step = $this->step(schemas: ['decision-table' => [102]], oldRows: [$this->oldRow('loan', 'org-a')]);

		$this->objects->expects($this->never())->method('saveObject');
		$step->run($this->createMock(IOutput::class));

		$this->assertSame('edited after the move', $this->held['buildiq.org-a.loan']['name']);

	}//end testATableAlreadyInOpenRegisterIsNotOverwritten()

	/**
	 * A table OpenRegister refuses stays where it is, and the old schema keeps
	 * its slug so the next run can still find it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decision-tables-live-in-openregister/specs/business-rules-engine/spec.md
	 */
	public function testARefusedTableKeepsTheOldSchemaItsSlug(): void {
		$step = $this->step(
			schemas: ['decision-table' => [102]],
			oldRows: [$this->oldRow('loan', 'org-a'), $this->oldRow('bad', 'org-a')],
			refuse: ['buildiq.org-a.bad']
		);

		$step->run($this->createMock(IOutput::class));

		$this->assertSame(['buildiq.org-a.loan'], array_keys($this->held));
		$this->assertSame([], $this->renames);

	}//end testARefusedTableKeepsTheOldSchemaItsSlug()

	/**
	 * Before OpenRegister has its register, nothing moves and nothing is renamed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decision-tables-live-in-openregister/specs/business-rules-engine/spec.md
	 */
	public function testNothingMovesBeforeOpenRegisterHasItsRegister(): void {
		$step = $this->step(schemas: ['decision-table' => [102]], oldRows: [$this->oldRow('loan', 'org-a')], orReady: false);

		$this->objects->expects($this->never())->method('saveObject');
		$step->run($this->createMock(IOutput::class));

		$this->assertSame([], $this->renames);

	}//end testNothingMovesBeforeOpenRegisterHasItsRegister()

	/**
	 * A run after the rename still finds rows left on the renamed schema.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decision-tables-live-in-openregister/specs/business-rules-engine/spec.md
	 */
	public function testARenamedSchemaIsStillRead(): void {
		$step = $this->step(
			schemas: [MoveDecisionTablesToOpenRegister::RETIRED_SLUG => [102]],
			oldRows: [$this->oldRow('loan', '')]
		);

		$step->run($this->createMock(IOutput::class));

		$this->assertSame(['buildiq.loan'], array_keys($this->held));
		$this->assertSame([], $this->renames);

	}//end testARenamedSchemaIsStillRead()

	/**
	 * A fresh install has no old schema: nothing to do.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decision-tables-live-in-openregister/specs/business-rules-engine/spec.md
	 */
	public function testAnInstallWithoutTheOldSchemaDoesNothing(): void {
		$step = $this->step(schemas: [], oldRows: []);

		$this->objects->expects($this->never())->method('searchObjects');
		$step->run($this->createMock(IOutput::class));

		$this->assertSame([], $this->held);

	}//end testAnInstallWithoutTheOldSchemaDoesNothing()
}//end class
