<?php

/**
 * Unit tests for AutomationOutcomeKeys.
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/automation-designer/spec.md#requirement-a-step-that-openregister-executes-is-labelled-never-invented-req-bqrl-004
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\AutomationOutcomeKeys;
use PHPUnit\Framework\TestCase;

/**
 * An automation's key is its uuid; a notification adds its position; a key of another automation never matches.
 */
final class AutomationOutcomeKeysTest extends TestCase {

	/**
	 * The key is `aut-` and the id, or the uuid when there is no id.
	 *
	 * @return void
	 */
	public function testTheAutomationKeyIsItsUuid(): void {
		$this->assertSame(expected: 'aut-7f3c', actual: (new AutomationOutcomeKeys())->forAutomation(automation: ['id' => '7f3c', 'uuid' => 'other']));
		$this->assertSame(expected: 'aut-9a9a', actual: (new AutomationOutcomeKeys())->forAutomation(automation: ['uuid' => '9a9a']));
		$this->assertSame(expected: '', actual: (new AutomationOutcomeKeys())->forAutomation(automation: ['slug' => 'no-id']));
	}//end testTheAutomationKeyIsItsUuid()

	/**
	 * A notification's key adds its position; without an automation key there is none.
	 *
	 * @return void
	 */
	public function testANotificationKeyAddsItsPosition(): void {
		$this->assertSame(expected: 'aut-7f3c-2', actual: (new AutomationOutcomeKeys())->notification(automationKey: 'aut-7f3c', position: 2));
		$this->assertSame(expected: '', actual: (new AutomationOutcomeKeys())->notification(automationKey: '', position: 1));
	}//end testANotificationKeyAddsItsPosition()

	/**
	 * The automation's own key and its notifications match; a longer uuid sharing the prefix does not.
	 *
	 * @return void
	 */
	public function testOnlyTheAutomationsOwnKeysMatch(): void {
		$this->assertTrue(condition: (new AutomationOutcomeKeys())->belongsTo(key: 'aut-1234', automationKey: 'aut-1234'));
		$this->assertTrue(condition: (new AutomationOutcomeKeys())->belongsTo(key: 'aut-1234-3', automationKey: 'aut-1234'));
		$this->assertFalse(condition: (new AutomationOutcomeKeys())->belongsTo(key: 'aut-12345-1', automationKey: 'aut-1234'));
		$this->assertFalse(condition: (new AutomationOutcomeKeys())->belongsTo(key: 'aut-1234-reminder', automationKey: 'aut-1234'));
		$this->assertFalse(condition: (new AutomationOutcomeKeys())->belongsTo(key: 'aut-1234', automationKey: ''));
	}//end testOnlyTheAutomationsOwnKeysMatch()
}//end class
