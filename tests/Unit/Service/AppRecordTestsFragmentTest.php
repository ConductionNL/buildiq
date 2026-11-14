<?php

/**
 * Unit test for the app-record-tests register fragment.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The app-record-tests fragment merges both of its schemas into the buildiq
 * register (lifecycle-release-test-gate T01). Kept in its own file so this
 * change and others that add a fragment test never edit the same lines.
 */
final class AppRecordTestsFragmentTest extends TestCase {
	/**
	 * Invoke the private static SettingsService::deepMergeConfig().
	 *
	 * @param array<mixed> $base    Base config.
	 * @param array<mixed> $overlay Fragment.
	 *
	 * @return array<mixed> Merged config.
	 */
	private function merge(array $base, array $overlay): array {
		$m = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$m->setAccessible(true);
		return $m->invoke(null, $base, $overlay);
	}//end merge()

	/**
	 * The app-record-tests fragment adds the record test and test run schemas,
	 * both listed in the buildiq register (lifecycle-release-test-gate T01).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-release-test-gate/specs/app-release-test-gate/spec.md#requirement-every-run-is-kept-req-bqrt-005
	 */
	public function testTheAppRecordTestsFragmentAddsBothSchemas(): void {
		$root = __DIR__ . '/../../../lib/Settings/';
		$base = json_decode((string)file_get_contents($root . 'openbuild_register.json'), true);
		$fragment = json_decode((string)file_get_contents($root . 'register.d/24-app-record-tests.json'), true);

		$merged = $this->merge($base, $fragment);

		$this->assertSame('app-record-test', $merged['components']['schemas']['AppRecordTest']['slug']);
		$this->assertSame('app-test-run', $merged['components']['schemas']['AppTestRun']['slug']);
		$this->assertSame(['accept', 'refuse'], $merged['components']['schemas']['AppRecordTest']['properties']['expect']['enum']);
		$this->assertSame(['manual', 'release', 'promotion'], $merged['components']['schemas']['AppTestRun']['properties']['trigger']['enum']);
		$this->assertContains('app-record-test', $merged['components']['registers']['buildiq']['schemas']);
		$this->assertContains('app-test-run', $merged['components']['registers']['buildiq']['schemas']);
	}//end testTheAppRecordTestsFragmentAddsBothSchemas()
}//end class
