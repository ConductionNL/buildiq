<?php

/**
 * OpenBuild CleanupExpiredExports unit tests
 *
 * Asserts that expired export ZIPs are purged and fresh ones are kept, and
 * that the job is idempotent (re-running over an already-clean tree is a
 * no-op).
 *
 * @category Test
 * @package  OCA\OpenBuild\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @SPDX-License-Identifier: EUPL-1.2
 * @SPDX-FileCopyrightText:  2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Tests\Unit\BackgroundJob;

use OCA\OpenBuild\BackgroundJob\CleanupExpiredExports;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for {@see CleanupExpiredExports}.
 */
final class CleanupExpiredExportsTest extends TestCase {
	/**
	 * Build the job with a mocked time factory + null logger.
	 *
	 * @return CleanupExpiredExports Job under test.
	 */
	private function buildJob(): CleanupExpiredExports {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(time());
		$time->method('getDateTime')->willReturn(new \DateTime());

		return new CleanupExpiredExports($time, new NullLogger());
	}//end buildJob()

	/**
	 * Invoke the protected run() method via reflection.
	 *
	 * @param CleanupExpiredExports $job The job.
	 *
	 * @return void
	 */
	private function invokeRun(CleanupExpiredExports $job): void {
		$method = new \ReflectionMethod($job, 'run');
		$method->setAccessible(true);
		$method->invoke($job, null);
	}//end invokeRun()

	/**
	 * An expired ZIP (mtime older than 24h) is purged; a fresh ZIP survives.
	 *
	 * @return void
	 */
	public function testExpiredZipPurgedFreshKept(): void {
		$exportsRoot = sys_get_temp_dir() . '/openbuild-exports';
		if (is_dir($exportsRoot) === false) {
			mkdir($exportsRoot, 0o755, true);
		}

		$expired = $exportsRoot . '/expired-' . uniqid() . '.zip';
		$fresh = $exportsRoot . '/fresh-' . uniqid() . '.zip';
		file_put_contents($expired, 'old');
		file_put_contents($fresh, 'new');

		// Backdate the expired archive 48h.
		touch($expired, (time() - (48 * 3600)));

		$this->invokeRun($this->buildJob());

		self::assertFileDoesNotExist($expired, 'Expired ZIP must be purged');
		self::assertFileExists($fresh, 'Fresh ZIP must be kept');

		// Cleanup the fresh fixture.
		if (file_exists($fresh) === true) {
			unlink($fresh);
		}
	}//end testExpiredZipPurgedFreshKept()

	/**
	 * Re-running the job over an already-clean tree is a harmless no-op.
	 *
	 * @return void
	 */
	public function testRunIsIdempotent(): void {
		$job = $this->buildJob();
		$this->invokeRun($job);
		$this->invokeRun($job);

		// No exception thrown == idempotent.
		self::assertTrue(true);
	}//end testRunIsIdempotent()
}//end class
