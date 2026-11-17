<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The built offline worker, stamped with its version (pages-installable-offline-and-mobile T05).
 *
 * @category Test
 * @package  Buildiq
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl
 * @link     https://github.com/ConductionNL/buildiq
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service\Offline;

use OCA\Buildiq\Service\Offline\OfflineWorkerScript;
use PHPUnit\Framework\TestCase;

/**
 * Tests for OfflineWorkerScript.
 *
 * @covers \OCA\Buildiq\Service\Offline\OfflineWorkerScript
 */
class OfflineWorkerScriptTest extends TestCase {

	/**
	 * A temporary bundle file.
	 *
	 * @var string
	 */
	private string $file;

	/**
	 * A fresh temporary path.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->file = sys_get_temp_dir() . '/buildiq-worker-' . bin2hex(random_bytes(4)) . '.js';
	}//end setUp()

	/**
	 * Remove the temporary file.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if (is_file($this->file) === true) {
			unlink($this->file);
		}
	}//end tearDown()

	/**
	 * The bundle is served after a version line that follows its content.
	 *
	 * @return void
	 */
	public function testTheBundleIsStampedWithAVersionOfItsContent(): void {
		file_put_contents($this->file, 'self.addEventListener("fetch", () => {})');
		$version = substr(hash('sha256', 'self.addEventListener("fetch", () => {})'), 0, 16);

		self::assertSame(
			'self.CN_OFFLINE_BUNDLE_VERSION = "' . $version . "\";\nself.addEventListener(\"fetch\", () => {})",
			(new OfflineWorkerScript(path: $this->file))->script()
		);
	}//end testTheBundleIsStampedWithAVersionOfItsContent()

	/**
	 * Another build gets another version, so the device replaces its cached shell.
	 *
	 * @return void
	 */
	public function testANewBuildGetsANewVersion(): void {
		file_put_contents($this->file, 'one');
		$first = (new OfflineWorkerScript(path: $this->file))->script();
		file_put_contents($this->file, 'two');
		$second = (new OfflineWorkerScript(path: $this->file))->script();

		self::assertNotSame(strtok((string)$first, "\n"), strtok((string)$second, "\n"));
	}//end testANewBuildGetsANewVersion()

	/**
	 * A missing or empty bundle serves nothing.
	 *
	 * @return void
	 */
	public function testAMissingOrEmptyBundleServesNothing(): void {
		self::assertNull((new OfflineWorkerScript(path: $this->file))->script());

		file_put_contents($this->file, '');
		self::assertNull((new OfflineWorkerScript(path: $this->file))->script());
	}//end testAMissingOrEmptyBundleServesNothing()

	/**
	 * Without a path it reads the app's own build output.
	 *
	 * @return void
	 */
	public function testTheDefaultIsTheAppsOwnBuild(): void {
		$built = dirname(__DIR__, 4) . '/' . OfflineWorkerScript::BUNDLE;
		$expected = null;
		if (is_file($built) === true && filesize($built) > 0) {
			$expected = (new OfflineWorkerScript(path: $built))->script();
		}

		self::assertSame($expected, (new OfflineWorkerScript())->script());
	}//end testTheDefaultIsTheAppsOwnBuild()
}//end class
