<?php

/**
 * Unit tests for the rule-set callers in the business-rules register fragment.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Settings
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/rules-evaluation-for-other-apps/specs/rules-evaluation-for-other-apps/spec.md#requirement-a-rule-set-names-the-apps-that-may-call-it-req-bqrx-001
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * A rule set names the apps that may call it, and an execution log names the app that did.
 */
final class RuleCallersFragmentTest extends TestCase {

	/**
	 * The fragment's schemas.
	 *
	 * @return array<string,mixed>
	 */
	private static function schemas(): array {
		$raw = (string)file_get_contents(__DIR__ . '/../../../lib/Settings/register.d/10-business-rules.json');
		return json_decode($raw, true, flags: JSON_THROW_ON_ERROR)['components']['schemas'];
	}//end schemas()

	/**
	 * `callableBy` is a list of app ids, empty unless set, with a title the editor shows.
	 *
	 * @return void
	 */
	public function testARuleSetListsTheAppsThatMayCallIt(): void {
		$ruleSet = self::schemas()['RuleSet'];
		$callableBy = $ruleSet['properties']['callableBy'];

		$this->assertSame('Callable by', $callableBy['title']);
		$this->assertSame('array', $callableBy['type']);
		$this->assertSame(['type' => 'string'], $callableBy['items']);
		$this->assertSame([], $callableBy['default']);
		$this->assertNotContains('callableBy', ($ruleSet['required'] ?? []));
		$this->assertTrue(version_compare($ruleSet['version'], '1.1.0', '>='));
	}//end testARuleSetListsTheAppsThatMayCallIt()

	/**
	 * The execution log carries the calling app beside the consumer app it already had.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-evaluation-for-other-apps/specs/rules-evaluation-for-other-apps/spec.md#requirement-another-app-evaluates-a-rule-set-through-a-typed-command-req-bqrx-002
	 */
	public function testAnExecutionLogNamesTheCallingApp(): void {
		$log = self::schemas()['RuleExecutionLog'];

		$this->assertSame(['title' => 'Caller app', 'type' => 'string'], array_intersect_key($log['properties']['callerApp'], ['title' => 1, 'type' => 1]));
		$this->assertArrayHasKey('consumerApp', $log['properties']);
		$this->assertTrue(version_compare($log['version'], '1.1.0', '>='));
	}//end testAnExecutionLogNamesTheCallingApp()
}//end class
