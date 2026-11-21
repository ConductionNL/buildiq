<?php

/**
 * Tests for RunAutomationNodes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service\Automation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-an-automation-can-call-another-req-bqsf-004
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service\Automation;

use OCA\Buildiq\Exception\UnsupportedAutomationCombinationException;
use OCA\Buildiq\Service\Automation\RunAutomationNodes;
use PHPUnit\Framework\TestCase;

/**
 * A run-automation step compiles to a waiting sub-flow, or is refused by name.
 *
 * @covers \OCA\Buildiq\Service\Automation\RunAutomationNodes
 */
final class RunAutomationNodesTest extends TestCase {

	/**
	 * The automation that holds the step.
	 *
	 * @var array<string, mixed>
	 */
	private const OWN = ['id' => 'own-uuid', 'slug' => 'intake', 'applicationSlug' => 'permits'];

	/**
	 * The nodes builder over the given targets.
	 *
	 * @param array<string, array<string, mixed>> $targets The targets by uuid.
	 *
	 * @return RunAutomationNodes
	 */
	private static function nodes(array $targets): RunAutomationNodes {
		return new RunAutomationNodes(automation: self::OWN, targets: $targets);
	}//end nodes()

	/**
	 * A target with the given fields.
	 *
	 * @param array<string, mixed> $fields What differs from a shared, compiled automation of another app.
	 *
	 * @return array<string, mixed>
	 */
	private static function target(array $fields = []): array {
		return array_merge(
			[
				'slug' => 'check-address',
				'name' => 'Controleer adres',
				'applicationSlug' => 'subsidies',
				'shared' => true,
				'provenance' => ['flowUuid' => 'flow-7'],
			],
			$fields
		);
	}//end target()

	/**
	 * A shared automation of another app compiles to a sub-flow that waits on its flow.
	 *
	 * @return void
	 */
	public function testASharedAutomationCompilesToAWaitingSubFlow(): void {
		$node = self::nodes(targets: ['t-1' => self::target()])->build(action: ['type' => 'run-automation', 'automation' => 't-1'], index: 0);

		$this->assertSame(
			expected: ['id' => 'step-1', 'type' => 'openregister.sub-flow', 'config' => ['flowId' => 'flow-7', 'wait' => true]],
			actual: $node
		);
	}//end testASharedAutomationCompilesToAWaitingSubFlow()

	/**
	 * An unshared automation of the same app may be run.
	 *
	 * @return void
	 */
	public function testAnAutomationOfTheSameAppNeedsNoSharing(): void {
		$node = self::nodes(targets: ['t-1' => self::target(fields: ['applicationSlug' => 'permits', 'shared' => false])])
			->build(action: ['type' => 'run-automation', 'automation' => 't-1'], index: 2);

		$this->assertSame(expected: 'step-3', actual: $node['id']);
		$this->assertSame(expected: 'flow-7', actual: $node['config']['flowId']);
	}//end testAnAutomationOfTheSameAppNeedsNoSharing()

	/**
	 * Each refusal, with the message it names.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, array<string, mixed>>, 2: string}>
	 */
	public static function refusals(): array {
		return [
			'no automation named' => [['type' => 'run-automation'], [], RunAutomationNodes::MISSING_REFUSAL],
			'itself' => [['type' => 'run-automation', 'automation' => 'own-uuid'], ['own-uuid' => self::target()], RunAutomationNodes::SELF_REFUSAL],
			'unknown' => [['type' => 'run-automation', 'automation' => 'gone'], [], RunAutomationNodes::UNKNOWN_REFUSAL],
			'not shared' => [
				['type' => 'run-automation', 'automation' => 't-1'],
				['t-1' => self::target(fields: ['shared' => false])],
				RunAutomationNodes::NOT_SHARED_REFUSAL,
			],
			'no flow' => [
				['type' => 'run-automation', 'automation' => 't-1'],
				['t-1' => self::target(fields: ['provenance' => []])],
				'The automation "Controleer adres" has no flow yet; compile it before another automation runs it.',
			],
		];
	}//end refusals()

	/**
	 * A step that cannot run is refused before anything is written.
	 *
	 * @param array<string, mixed>                $action  The step.
	 * @param array<string, array<string, mixed>> $targets The resolved targets.
	 * @param string                              $message The refusal.
	 *
	 * @return void
	 *
	 * @dataProvider refusals
	 */
	public function testAStepThatCannotRunIsRefused(array $action, array $targets, string $message): void {
		$this->expectException(exception: UnsupportedAutomationCombinationException::class);
		$this->expectExceptionMessage(message: $message);

		self::nodes(targets: $targets)->build(action: $action, index: 0);
	}//end testAStepThatCannotRunIsRefused()

	/**
	 * The named uuids are listed once each, in order, and other steps are ignored.
	 *
	 * @return void
	 */
	public function testTheTargetUuidsAreListedOnceInOrder(): void {
		$actions = [
			['type' => 'run-automation', 'automation' => 'b'],
			['type' => 'object-op'],
			['type' => 'run-automation', 'automation' => ' a '],
			['type' => 'run-automation', 'automation' => 'b'],
			['type' => 'run-automation'],
			'not an action',
		];

		$this->assertSame(expected: ['b', 'a'], actual: RunAutomationNodes::targetUuids(actions: $actions));
	}//end testTheTargetUuidsAreListedOnceInOrder()
}//end class
