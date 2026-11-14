<?php

/**
 * Unit tests for AutomationRuleSets.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The rule sets a rule-decision step names, with the key and outputs of their
 * stored decision table (logic-automation-actions-that-run D7).
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service\Automation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-a-step-decides-with-a-rule-set-req-bqar-005
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service\Automation;

use OCA\Buildiq\Service\Automation\AutomationRuleSets;
use OCA\Buildiq\Service\StoredDecisionTables;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Rule set resolution for rule decisions.
 *
 * @covers \OCA\Buildiq\Service\Automation\AutomationRuleSets
 * @uses \OCA\Buildiq\Support\ObjectRecord
 */
final class AutomationRuleSetsTest extends TestCase {

	/**
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * @var StoredDecisionTables&MockObject
	 */
	private StoredDecisionTables&MockObject $tables;

	/**
	 * Set up the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->tables = $this->createMock(StoredDecisionTables::class);
	}//end setUp()

	/**
	 * The lookup under test.
	 *
	 * @return AutomationRuleSets
	 */
	private function lookup(): AutomationRuleSets {
		return new AutomationRuleSets(objectService: $this->objects, tables: $this->tables, logger: new NullLogger());
	}//end lookup()

	/**
	 * A decision table rule set resolves to its stored table key, version and
	 * outputs; a condition-action one has no table; a missing one is left out.
	 *
	 * @return void
	 */
	public function testRuleSetsResolveTheStoredTable(): void {
		$leges = ['slug' => 'leges', 'ruleType' => 'decision-table', 'version' => '1.0.1', '@self' => ['organisation' => 'org-1']];
		$checks = ['slug' => 'checks', 'ruleType' => 'condition-action', 'version' => '2.0.0'];
		$this->objects->method('findAll')->willReturnCallback(
			static function (array $config) use ($leges, $checks): array {
				return match ($config['filters']['slug']) {
					'leges' => [$leges],
					'checks' => [$checks],
					default => [],
				};
			}
		);
		$this->tables->method('forRuleSet')->with($leges)->willReturn([['outputColumns' => [['name' => 'categorie'], ['name' => '']]]]);
		$this->tables->method('keyOfRuleSet')->with($leges)->willReturn('buildiq.org-1.leges');

		$this->assertSame(
			[
				'checks' => ['ruleType' => 'condition-action', 'version' => '2.0.0', 'tableRef' => '', 'outputs' => []],
				'leges' => ['ruleType' => 'decision-table', 'version' => '1.0.1', 'tableRef' => 'buildiq.org-1.leges', 'outputs' => ['categorie']],
			],
			$this->lookup()->ruleSets(slugs: ['checks', 'leges', 'gone'])
		);
	}//end testRuleSetsResolveTheStoredTable()

	/**
	 * A decision table rule set without a stored table has no tableRef.
	 *
	 * @return void
	 */
	public function testARuleSetWithoutAStoredTableHasNoTableRef(): void {
		$this->objects->method('findAll')->willReturn([['slug' => 'leges', 'ruleType' => 'decision-table', 'version' => '1.0.0']]);
		$this->tables->method('forRuleSet')->willReturn([]);
		$this->tables->expects($this->never())->method('keyOfRuleSet');

		$this->assertSame('', $this->lookup()->ruleSets(slugs: ['leges'])['leges']['tableRef']);
	}//end testARuleSetWithoutAStoredTableHasNoTableRef()
}//end class
