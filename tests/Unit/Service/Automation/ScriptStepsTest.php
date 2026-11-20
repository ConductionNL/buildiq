<?php

/**
 * Tests for the pure reads of an automation's script steps.
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
 * @spec openspec/changes/logic-script-step/specs/automation-script-step/spec.md#requirement-only-app-owners-change-script-steps-req-bqss-002
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service\Automation;

use OCA\Buildiq\Service\Automation\ScriptSteps;
use PHPUnit\Framework\TestCase;

/**
 * Which saves touch a script step, and who is named as its author.
 *
 * @spec openspec/changes/logic-script-step/specs/automation-script-step/spec.md#requirement-only-app-owners-change-script-steps-req-bqss-002
 */
final class ScriptStepsTest extends TestCase {

	/**
	 * An automation with the given actions.
	 *
	 * @param array<int, array<string, mixed>> $actions The actions.
	 *
	 * @return array<string, mixed>
	 */
	private static function automation(array $actions): array {
		return ['slug' => 'join-names', 'actions' => $actions];
	}//end automation()

	/**
	 * Only script steps are read, with the default timeout filled in.
	 *
	 * @return void
	 */
	public function testItReadsOnlyTheScriptSteps(): void {
		$actions = [['type' => 'send-notification'], ['type' => 'script-step', 'code' => 'return items;'], 'not-an-action'];

		self::assertSame(
			expected: [['code' => 'return items;', 'timeoutSeconds' => 10, 'note' => '']],
			actual: ScriptSteps::stepsOf(actions: $actions)
		);
		self::assertTrue(condition: ScriptSteps::holds(automation: self::automation(actions: $actions)));
		self::assertFalse(condition: ScriptSteps::holds(automation: self::automation(actions: [['type' => 'webhook']])));
		self::assertFalse(condition: ScriptSteps::holds(automation: ['actions' => 'broken']));
	}//end testItReadsOnlyTheScriptSteps()

	/**
	 * Adding, changing (code, timeout or note) and removing a script are changes; other edits are not.
	 *
	 * @return void
	 */
	public function testItSeesAddChangeAndRemove(): void {
		$script = ['type' => 'script-step', 'code' => 'return items;', 'timeoutSeconds' => 10, 'note' => 'Join'];
		$stored = self::automation(actions: [$script]);

		self::assertTrue(condition: ScriptSteps::changed(before: [], after: $stored));
		self::assertTrue(condition: ScriptSteps::changed(before: $stored, after: self::automation(actions: [])));
		self::assertTrue(condition: ScriptSteps::changed(before: $stored, after: self::automation(actions: [['code' => 'return [];'] + $script])));
		self::assertTrue(condition: ScriptSteps::changed(before: $stored, after: self::automation(actions: [['timeoutSeconds' => 30] + $script])));
		self::assertFalse(condition: ScriptSteps::changed(before: $stored, after: ['slug' => 'renamed'] + $stored));
		self::assertFalse(
			condition: ScriptSteps::changed(before: $stored, after: self::automation(actions: [['type' => 'send-notification'], $script]))
		);
	}//end testItSeesAddChangeAndRemove()

	/**
	 * Stamping names the caller on a change and leaves an automation that never had a script alone.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/logic-script-step/specs/automation-script-step/spec.md#requirement-the-script-runs-only-in-the-code-runner-req-bqss-003
	 */
	public function testStampingNamesTheCallerOnlyOnAChange(): void {
		$script = ['type' => 'script-step', 'code' => 'return items;'];

		$created = ScriptSteps::stamp(payload: self::automation(actions: [$script]), before: [], caller: 'alice');
		self::assertSame(expected: ['scriptAuthor' => 'alice'], actual: $created['provenance']);

		$plain = self::automation(actions: [['type' => 'webhook']]);
		self::assertSame(expected: $plain, actual: ScriptSteps::stamp(payload: $plain, before: $plain, caller: 'bob'));
	}//end testStampingNamesTheCallerOnlyOnAChange()

	/**
	 * The caller is named for a change, the stored author otherwise, and the body's word never.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/logic-script-step/specs/automation-script-step/spec.md#requirement-the-script-runs-only-in-the-code-runner-req-bqss-003
	 */
	public function testTheAuthorIsNeverTheBodysWord(): void {
		$forged = ['compiledHash' => 'h', 'scriptAuthor' => 'mallory'];

		self::assertSame(
			expected: ['compiledHash' => 'h', 'scriptAuthor' => 'alice'],
			actual: ScriptSteps::authored(provenance: $forged, stored: 'carol', caller: 'alice')
		);
		self::assertSame(
			expected: ['compiledHash' => 'h', 'scriptAuthor' => 'carol'],
			actual: ScriptSteps::authored(provenance: $forged, stored: 'carol', caller: null)
		);
		self::assertSame(expected: ['compiledHash' => 'h'], actual: ScriptSteps::authored(provenance: $forged, stored: '', caller: null));
	}//end testTheAuthorIsNeverTheBodysWord()
}//end class
