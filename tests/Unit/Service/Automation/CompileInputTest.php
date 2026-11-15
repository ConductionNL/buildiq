<?php

/**
 * Unit tests for CompileInput: the automation fields the compiler reads,
 * with their defaults when a field is missing or of the wrong type.
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
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service\Automation;

use OCA\Buildiq\Service\Automation\CompileInput;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CompileInput.
 *
 * @covers \OCA\Buildiq\Service\Automation\CompileInput
 */
final class CompileInputTest extends TestCase {

	/**
	 * A complete automation is read field by field, and the actions are re-indexed as a list.
	 *
	 * @return void
	 */
	public function testACompleteAutomationIsRead(): void {
		$automation = [
			'slug'      => 'notify-on-loan',
			'trigger'   => ['type' => 'object.created', 'schema' => 'loan'],
			'condition' => ['==' => [['var' => 'status'], 'open']],
			'actions'   => ['first' => ['type' => 'notify'], 'second' => ['type' => 'webhook']],
			'enabled'   => false,
		];

		$input = new CompileInput(automation: $automation);

		self::assertSame($automation, $input->automation);
		self::assertSame('notify-on-loan', $input->slug);
		self::assertSame(['type' => 'object.created', 'schema' => 'loan'], $input->trigger);
		self::assertSame('object.created', $input->triggerType);
		self::assertSame(['==' => [['var' => 'status'], 'open']], $input->condition);
		self::assertSame([['type' => 'notify'], ['type' => 'webhook']], $input->actions);
		self::assertFalse($input->enabled);
	}//end testACompleteAutomationIsRead()

	/**
	 * An empty automation gets the defaults: no slug, no trigger, no condition, no actions, enabled.
	 *
	 * @return void
	 */
	public function testAnEmptyAutomationGetsTheDefaults(): void {
		$input = new CompileInput(automation: []);

		self::assertSame('', $input->slug);
		self::assertSame([], $input->trigger);
		self::assertSame('', $input->triggerType);
		self::assertNull($input->condition);
		self::assertSame([], $input->actions);
		self::assertTrue($input->enabled);
	}//end testAnEmptyAutomationGetsTheDefaults()

	/**
	 * A trigger or action list that is not an array reads as empty.
	 *
	 * @return void
	 */
	public function testNonArrayTriggerAndActionsReadAsEmpty(): void {
		$input = new CompileInput(automation: ['trigger' => 'object.created', 'actions' => 'notify']);

		self::assertSame([], $input->trigger);
		self::assertSame('', $input->triggerType);
		self::assertSame([], $input->actions);
	}//end testNonArrayTriggerAndActionsReadAsEmpty()

	/**
	 * Scalar slug, trigger type and enabled flag are cast to their types.
	 *
	 * @return void
	 */
	public function testScalarsAreCast(): void {
		$input = new CompileInput(
			automation: ['slug' => 42, 'trigger' => ['type' => 7], 'enabled' => 0, 'condition' => 'true']
		);

		self::assertSame('42', $input->slug);
		self::assertSame('7', $input->triggerType);
		self::assertFalse($input->enabled);
		self::assertSame('true', $input->condition);
	}//end testScalarsAreCast()

	/**
	 * A null enabled flag falls back to enabled.
	 *
	 * @return void
	 */
	public function testANullEnabledFlagMeansEnabled(): void {
		self::assertTrue((new CompileInput(automation: ['enabled' => null]))->enabled);
	}//end testANullEnabledFlagMeansEnabled()
}//end class
