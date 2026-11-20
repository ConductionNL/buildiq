<?php

/**
 * Buildiq AutomationRunPruneJob
 *
 * Deletes automation runs older than 90 days, once a day.
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category BackgroundJob
 * @package  OCA\Buildiq\BackgroundJob
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

namespace OCA\Buildiq\BackgroundJob;

use OCA\Buildiq\Kernel\Contracts\AutomationRunRecorderInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Daily retention for automation-run records (logic-automation-run-log, design D5).
 *
 * The summary of the runs endpoint is read from the newest remaining run, so
 * an automation that still runs keeps its last run; one that has not run for
 * 90 days shows no run, which is itself the useful fact.
 *
 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-old-runs-are-pruned-and-the-last-run-stays-req-bqrl-005
 */
class AutomationRunPruneJob extends TimedJob {

	/**
	 * Run interval: one day.
	 */
	private const INTERVAL_SECONDS = 86400;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory                   $time Time factory.
	 * @param AutomationRunRecorderInterface $runs The run log.
	 *
	 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-old-runs-are-pruned-and-the-last-run-stays-req-bqrl-005
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly AutomationRunRecorderInterface $runs,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
	}//end __construct()

	/**
	 * Delete the runs older than the retention.
	 *
	 * @param mixed $argument Job argument (unused).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-old-runs-are-pruned-and-the-last-run-stays-req-bqrl-005
	 */
	protected function run($argument): void {
		unset($argument);
		$this->runs->prune(now: $this->time->getTime());
	}//end run()
}//end class
