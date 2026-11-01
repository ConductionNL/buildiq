<?php

/**
 * Buildiq's part of the Zuiddrecht example set gives the demo employee an app
 * of his own, as the BqApp boards draw it: the hybrid Pipelinq app, published,
 * with a production, a development and an older version, owned by Pieter.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Settings
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link https://conduction.nl
 * @spec exclude example-set data integrity; no requirement covers the demo fragment
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Reads the shipped example-set fragment from disk.
 */
class ExampleSetOwnedAppTest extends TestCase {

	private const FRAGMENT = __DIR__ . '/../../../lib/Settings/example-sets/zuiddrecht.json';

	/**
	 * The fragment.
	 *
	 * @return array<string, mixed> The fragment
	 */
	private static function fragment(): array {
		self::assertFileExists(filename: self::FRAGMENT);
		return json_decode((string)file_get_contents(self::FRAGMENT), true);
	}//end fragment()

	/**
	 * The fragment's objects by key, in file order.
	 *
	 * @return array<string, array<string, mixed>> The objects
	 */
	private static function objects(): array {
		$byKey = [];
		foreach (self::fragment()['objects'] as $object) {
			$byKey[$object['key']] = $object;
		}

		return $byKey;
	}//end objects()

	/**
	 * Pieter owns the app, by his user principal from the cast.
	 *
	 * @return void
	 */
	public function testPieterOwnsAPublishedHybridApp(): void {
		$app = self::objects()['app-pipelinq'] ?? null;

		$this->assertNotNull(actual: $app);
		$this->assertSame(expected: ['buildiq', 'built-app'], actual: [$app['register'], $app['schema']]);
		$this->assertSame(expected: ['hybrid', 'published'], actual: [$app['data']['appType'], $app['data']['status']]);
		$this->assertSame(expected: ['kind' => 'fleet-app', 'id' => 'pipelinq'], actual: $app['data']['baseRef']);
		$this->assertSame(expected: ['user:{{cast:pieter.uid}}'], actual: $app['data']['permissions']['owners']);
	}//end testPieterOwnsAPublishedHybridApp()

	/**
	 * Production 1.1.0, development 1.1.1 that promotes to it, and an older
	 * 1.0.4 to roll back to; every version belongs to the app.
	 *
	 * @return void
	 */
	public function testTheVersionsPromoteAndRollBack(): void {
		$versions = [];
		foreach (self::objects() as $object) {
			if ($object['schema'] === 'applicationVersion') {
				$this->assertSame(expected: '{{object:app-pipelinq.id}}', actual: $object['data']['application']);
				$versions[$object['data']['semver']] = $object['data'];
			}
		}

		ksort($versions);
		$this->assertSame(expected: ['1.0.4', '1.1.0', '1.1.1'], actual: array_keys($versions));
		$this->assertSame(expected: 'draft', actual: $versions['1.1.1']['status']);
		$this->assertSame(expected: '{{object:version-pipelinq-1-1-0.id}}', actual: $versions['1.1.1']['promotesTo']);
		$this->assertSame(expected: [], actual: $versions['1.1.0']['manifestDelta']);
	}//end testTheVersionsPromoteAndRollBack()

	/**
	 * The production version is linked after the versions exist, by a change.
	 *
	 * @return void
	 */
	public function testTheProductionVersionIsLinkedByAChange(): void {
		$changes = self::fragment()['changes'] ?? [];

		$this->assertCount(expectedCount: 1, haystack: $changes);
		$this->assertSame(expected: 'app-pipelinq', actual: $changes[0]['object']);
		$this->assertSame(expected: ['productionVersion' => '{{object:version-pipelinq-1-1-0.id}}'], actual: $changes[0]['set']);
	}//end testTheProductionVersionIsLinkedByAChange()

	/**
	 * The app has an automation that can be tested, switched off until compiled.
	 *
	 * @return void
	 */
	public function testTheAppHasAnAutomationToTest(): void {
		$automation = self::objects()['automation-pipelinq-new-lead'] ?? null;

		$this->assertNotNull(actual: $automation);
		$this->assertSame(expected: 'pipelinq', actual: $automation['data']['applicationSlug']);
		$this->assertSame(expected: '{{object:version-pipelinq-1-1-1.id}}', actual: $automation['data']['versionUuid']);
		$this->assertFalse(condition: $automation['data']['enabled']);
	}//end testTheAppHasAnAutomationToTest()
	/**
	 * Every version carries a manifest with the three fields an app manifest requires: OpenRegister refuses an
	 * empty one, and with it the whole example set.
	 *
	 * @return void
	 */
	public function testEveryVersionHasAManifestTheSchemaAccepts(): void {
		foreach (self::objects() as $key => $object) {
			if ($object['schema'] !== 'applicationVersion') {
				continue;
			}

			$manifest = $object['data']['manifest'] ?? [];
			self::assertSame(expected: $object['data']['semver'], actual: ($manifest['version'] ?? null), message: $key);
			self::assertIsArray(actual: ($manifest['menu'] ?? null), message: $key);
			self::assertIsArray(actual: ($manifest['pages'] ?? null), message: $key);
		}
	}//end testEveryVersionHasAManifestTheSchemaAccepts()
}//end class
