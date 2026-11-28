<?php

/**
 * Unit tests for MoveStuckAutomationsToFlows.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * A manual automation compiled to a rule set moves to a flow; a transition
 * webhook is disabled with the reason; healthy automations are left alone
 * (logic-automation-actions-that-run REQ-BQAR-007, D9).
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-24-logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-stuck-automations-are-moved-or-disabled-with-a-reason-req-bqar-007
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Repair;

use OCA\Buildiq\Exception\UnsupportedAutomationCombinationException;
use OCA\Buildiq\Kernel\Contracts\AutomationCompilerServiceInterface;
use OCA\Buildiq\Repair\MoveStuckAutomationsToFlows;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Moves, disables and leaves alone.
 *
 * @covers \OCA\Buildiq\Repair\MoveStuckAutomationsToFlows
 * @uses \OCA\Buildiq\Exception\UnsupportedAutomationCombinationException
 * @uses \OCA\Buildiq\Support\ObjectRecord
 * @uses \OCA\Buildiq\Service\Automation\AutomationRecords
 */
final class MoveStuckAutomationsToFlowsTest extends TestCase {

	/**
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * @var AutomationCompilerServiceInterface&MockObject
	 */
	private AutomationCompilerServiceInterface&MockObject $compiler;

	/**
	 * Set up the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->compiler = $this->createMock(AutomationCompilerServiceInterface::class);
	}//end setUp()

	/**
	 * The step under test.
	 *
	 * @return MoveStuckAutomationsToFlows
	 */
	private function step(): MoveStuckAutomationsToFlows {
		return new MoveStuckAutomationsToFlows(objectService: $this->objects, compiler: $this->compiler, logger: new NullLogger());
	}//end step()

	/**
	 * A manual automation that compiled to a rule set.
	 *
	 * @return array<string, mixed>
	 */
	private static function manualAutomation(): array {
		return [
			'id' => 'auto-manual',
			'slug' => 'send-claim',
			'enabled' => true,
			'trigger' => ['type' => 'manual'],
			'actions' => [['type' => 'object-op', 'operation' => 'create', 'schema' => 'flag']],
			'provenance' => ['ruleSetSlug' => 'aut-11112222', 'notificationKeys' => [], 'lifecycleActions' => []],
		];
	}//end manualAutomation()

	/**
	 * A transition webhook that compiled to a lifecycle record.
	 *
	 * @return array<string, mixed>
	 */
	private static function transitionWebhook(): array {
		return [
			'id' => 'auto-transition',
			'slug' => 'notify-crm',
			'enabled' => true,
			'trigger' => ['type' => 'lifecycle-transition', 'schema' => 'permit', 'transition' => 'approve'],
			'actions' => [['type' => 'webhook', 'url' => 'https://crm.test/hook']],
			'provenance' => ['lifecycleActions' => [['schema' => 'permit', 'transition' => 'approve', 'marker' => 'aut-notify-crm']]],
		];
	}//end transitionWebhook()

	/**
	 * Which automations are stuck.
	 *
	 * @return void
	 */
	public function testWhichAutomationsAreStuck(): void {
		self::assertTrue(MoveStuckAutomationsToFlows::isStuck(self::manualAutomation()));
		self::assertTrue(MoveStuckAutomationsToFlows::isStuck(self::transitionWebhook()));

		$moved = self::manualAutomation();
		$moved['provenance'] = ['flowUuid' => 'flow-1'];
		self::assertFalse(MoveStuckAutomationsToFlows::isStuck($moved));

		$notifyOnTransition = self::transitionWebhook();
		$notifyOnTransition['actions'] = [['type' => 'send-notification']];
		self::assertFalse(MoveStuckAutomationsToFlows::isStuck($notifyOnTransition));

		$created = self::transitionWebhook();
		$created['trigger']['type'] = 'object-created';
		self::assertFalse(MoveStuckAutomationsToFlows::isStuck($created));

		self::assertFalse(MoveStuckAutomationsToFlows::isStuck(['trigger' => ['type' => 'manual'], 'provenance' => 'broken']));
	}//end testWhichAutomationsAreStuck()

	/**
	 * A manual automation loses its rule set and is saved with the flow's
	 * provenance; a transition webhook is disabled with the refusal; a healthy
	 * automation is not touched.
	 *
	 * @return void
	 */
	public function testMovesDisablesAndLeavesAlone(): void {
		$healthy = ['id' => 'auto-ok', 'trigger' => ['type' => 'object-created'], 'actions' => [], 'provenance' => ['notificationKeys' => []]];
		$this->objects->method('findAll')->willReturn([self::manualAutomation(), self::transitionWebhook(), $healthy]);

		$removed = [];
		$this->compiler->method('remove')->willReturnCallback(
			static function (array $automation, array $provenance) use (&$removed): void {
				$removed[] = [$automation['id'], $provenance];
			}
		);
		$this->compiler->method('compile')->willReturnCallback(
			static function (array $automation): array {
				if ($automation['id'] === 'auto-transition') {
					throw new UnsupportedAutomationCombinationException(message: 'Record and webhook steps cannot run on a lifecycle transition yet.');
				}

				return ['flow' => ['name' => 'aut-send-claim'], 'hash' => 'sha256:a'];
			}
		);
		$this->compiler->expects($this->once())->method('apply')->willReturn(['flowUuid' => 'flow-1', 'compiledHash' => 'sha256:a']);

		$saved = [];
		$entity = $this->createMock(ObjectEntityInterface::class);
		$this->objects->method('saveObject')->willReturnCallback(
			static function (...$args) use (&$saved, $entity) {
				$saved[$args[1]->uuid] = $args[0];
				return $entity;
			}
		);

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('MoveStuckAutomationsToFlows: moved 1 automation(s) to flows, disabled 1 with a reason.');

		$this->step()->run($output);

		self::assertSame(
			[['auto-manual', self::manualAutomation()['provenance']], ['auto-transition', self::transitionWebhook()['provenance']]],
			$removed
		);
		self::assertSame(['auto-manual', 'auto-transition'], array_keys($saved));
		self::assertSame(['flowUuid' => 'flow-1', 'compiledHash' => 'sha256:a'], $saved['auto-manual']['provenance']);
		self::assertTrue($saved['auto-manual']['enabled']);
		self::assertArrayNotHasKey('disabledReason', $saved['auto-manual']);
		self::assertFalse($saved['auto-transition']['enabled']);
		self::assertSame('Record and webhook steps cannot run on a lifecycle transition yet.', $saved['auto-transition']['disabledReason']);
		self::assertSame([], $saved['auto-transition']['provenance']['lifecycleActions']);
	}//end testMovesDisablesAndLeavesAlone()

	/**
	 * Unreadable automations end the step with a warning and no write; a
	 * failed removal leaves that automation as it was.
	 *
	 * @return void
	 */
	public function testNeverThrows(): void {
		$this->objects->method('findAll')->willThrowException(new RuntimeException('register missing'));
		$this->objects->expects($this->never())->method('saveObject');
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning')->with('MoveStuckAutomationsToFlows: automations could not be read; nothing moved.');

		$this->step()->run($output);
	}//end testNeverThrows()

	/**
	 * A removal that fails is not followed by a recompile or a save.
	 *
	 * @return void
	 */
	public function testAFailedRemovalSavesNothing(): void {
		$this->objects->method('findAll')->willReturn([self::manualAutomation()]);
		$this->compiler->method('remove')->willThrowException(new RuntimeException('busy'));
		$this->compiler->expects($this->never())->method('compile');
		$this->objects->expects($this->never())->method('saveObject');
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('MoveStuckAutomationsToFlows: moved 0 automation(s) to flows, disabled 0 with a reason.');

		$this->step()->run($output);
	}//end testAFailedRemovalSavesNothing()
}//end class
