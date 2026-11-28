<?php

/**
 * Tests for the disable sweep of script steps.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service\Automation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/logic-script-step/specs/automation-script-step/spec.md#requirement-the-script-runs-only-in-the-code-runner-req-bqss-003
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service\Automation;

use OCA\Buildiq\Kernel\Contracts\AutomationCompilerServiceInterface;
use OCA\Buildiq\Kernel\Contracts\AutomationWriteServiceInterface;
use OCA\Buildiq\Service\Automation\ScriptStepSweep;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * "An administrator turns scripts off": both enabled automations with a script
 * are disabled with the reason; the others are left alone.
 *
 * @spec openspec/changes/logic-script-step/specs/automation-script-step/spec.md#requirement-the-script-runs-only-in-the-code-runner-req-bqss-003
 */
final class ScriptStepSweepTest extends TestCase {

	/**
	 * An automation.
	 *
	 * @param string $uuid    Its uuid.
	 * @param bool   $enabled Whether it is enabled.
	 * @param bool   $script  Whether it holds a script step.
	 *
	 * @return array<string, mixed>
	 */
	private static function automation(string $uuid, bool $enabled, bool $script): array {
		$action = ['type' => 'send-notification'];
		if ($script === true) {
			$action = ['type' => 'script-step', 'code' => 'return items;'];
		}

		return [
			'id' => $uuid,
			'enabled' => $enabled,
			'actions' => [$action],
			'provenance' => ['scriptAuthor' => 'alice', 'flowUuid' => 'f-' . $uuid],
		];
	}//end automation()

	/**
	 * The sweep over the given automations, recording what it stored.
	 *
	 * @param array<int, array<string, mixed>> $automations The stored automations.
	 * @param array<string, array>             $saved       Receives the stored automations by uuid.
	 * @param string                           $failing     A uuid whose removal fails, if any.
	 *
	 * @return ScriptStepSweep
	 */
	private function sweep(array $automations, array &$saved, string $failing = ''): ScriptStepSweep {
		$objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objectService->method('findAll')->willReturn($automations);
		$compiler = $this->createMock(originalClassName: AutomationCompilerServiceInterface::class);
		$compiler->method('remove')->willReturnCallback(
			static function (array $automation) use ($failing): void {
				if ($automation['id'] === $failing) {
					throw new RuntimeException(message: 'flow engine unreachable');
				}
			}
		);
		$writer = $this->createMock(originalClassName: AutomationWriteServiceInterface::class);
		$writer->method('saveCompiled')->willReturnCallback(
			static function (array $automation, string $uuid) use (&$saved): array {
				$saved[$uuid] = $automation;
				return $automation;
			}
		);

		return new ScriptStepSweep(objectService: $objectService, compiler: $compiler, writeService: $writer, logger: new NullLogger());
	}//end sweep()

	/**
	 * Enabled script automations are disabled with the reason and keep their author; others are untouched.
	 *
	 * @return void
	 */
	public function testItDisablesEveryEnabledAutomationWithAScript(): void {
		$saved  = [];
		$counts = $this->sweep(
			automations: [
				self::automation(uuid: 'a', enabled: true, script: true),
				self::automation(uuid: 'b', enabled: true, script: true),
				self::automation(uuid: 'c', enabled: true, script: false),
				self::automation(uuid: 'd', enabled: false, script: true),
			],
			saved: $saved
		)->disableAll();

		self::assertSame(expected: ['disabled' => 2, 'failed' => 0], actual: $counts);
		self::assertSame(expected: ['a', 'b'], actual: array_keys($saved));
		self::assertFalse(condition: $saved['a']['enabled']);
		self::assertSame(expected: 'Script steps are turned off for this instance.', actual: $saved['a']['disabledReason']);
		self::assertSame(expected: 'alice', actual: $saved['a']['provenance']['scriptAuthor']);
		self::assertArrayNotHasKey(key: 'flowUuid', array: $saved['a']['provenance']);
	}//end testItDisablesEveryEnabledAutomationWithAScript()

	/**
	 * One failure is counted and does not stop the others.
	 *
	 * @return void
	 */
	public function testAFailureDoesNotStopTheOthers(): void {
		$saved  = [];
		$counts = $this->sweep(
			automations: [self::automation(uuid: 'a', enabled: true, script: true), self::automation(uuid: 'b', enabled: true, script: true)],
			saved: $saved,
			failing: 'a'
		)->disableAll();

		self::assertSame(expected: ['disabled' => 1, 'failed' => 1], actual: $counts);
		self::assertSame(expected: ['b'], actual: array_keys($saved));
	}//end testAFailureDoesNotStopTheOthers()
}//end class
