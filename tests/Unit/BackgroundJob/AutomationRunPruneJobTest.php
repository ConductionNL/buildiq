<?php

/**
 * Unit tests for AutomationRunPruneJob.
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-old-runs-are-pruned-and-the-last-run-stays-req-bqrl-005
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\BackgroundJob;

use OCA\Buildiq\BackgroundJob\AutomationRunPruneJob;
use OCA\Buildiq\Kernel\Contracts\AutomationRunRecorderInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The daily job prunes the run log at the clock's time.
 */
#[CoversClass(AutomationRunPruneJob::class)]
final class AutomationRunPruneJobTest extends TestCase {

	/**
	 * A run of the job prunes with the time of the clock, once a day.
	 *
	 * @return void
	 */
	public function testTheJobPrunesAtTheClocksTime(): void {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1794650400);
		$runs = $this->createMock(AutomationRunRecorderInterface::class);
		$runs->expects($this->once())->method('prune')->with(1794650400)->willReturn(3);

		$job = new AutomationRunPruneJob($time, $runs);
		(new ReflectionMethod($job, 'run'))->invoke($job, null);

		$this->assertSame(86400, $job->getInterval());
	}//end testTheJobPrunesAtTheClocksTime()
}//end class
