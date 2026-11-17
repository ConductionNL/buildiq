<?php

/**
 * Unit tests for AutomationRunPage.
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
 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-a-maker-reads-the-runs-of-an-automation-they-may-edit-req-bqrl-002
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\AutomationRunPage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Runs are ordered and filtered on their start time in UTC, and summarised from the newest.
 */
#[CoversClass(AutomationRunPage::class)]
final class AutomationRunPageTest extends TestCase {

	/**
	 * A run started 09:30 at +02:00 is older than one started 08:00 UTC.
	 *
	 * @return void
	 */
	public function testRunsSortAndFilterOnUtc(): void {
		$page = new AutomationRunPage();
		$runs = [
			['id' => 'a', 'startedAt' => '2026-11-14T09:30:00+02:00', 'outcome' => 'succeeded'],
			['id' => 'b', 'startedAt' => '2026-11-14T08:00:00Z', 'outcome' => 'failed', 'reason' => 'Webhook answered 500'],
			['id' => 'c', 'startedAt' => 'not a date', 'outcome' => 'failed'],
		];

		$sorted = $page->newestFirst(runs: $runs);
		$this->assertSame(['b', 'a', 'c'], array_column($sorted, 'id'));
		$this->assertSame(['b'], array_column($page->select(runs: $sorted, filters: ['since' => '2026-11-14T07:45:00Z']), 'id'));
		$this->assertSame(['a'], array_column($page->select(runs: $sorted, filters: ['until' => '2026-11-14T07:45:00Z', 'outcome' => 'succeeded']), 'id'));
		$this->assertSame(['b', 'c'], array_column($page->select(runs: $sorted, filters: ['outcome' => 'failed']), 'id'));
		$this->assertSame(['lastRunAt' => '2026-11-14T08:00:00Z', 'lastOutcome' => 'failed', 'lastError' => 'Webhook answered 500'], $page->summary(runs: $sorted));
	}//end testRunsSortAndFilterOnUtc()

	/**
	 * Only a failed last run carries an error; no runs summarise to nothing.
	 *
	 * @return void
	 */
	public function testTheSummaryCarriesAnErrorOnlyForAFailure(): void {
		$page = new AutomationRunPage();

		$this->assertSame(['lastRunAt' => null, 'lastOutcome' => null, 'lastError' => null], $page->summary(runs: []));
		$this->assertNull($page->summary(runs: [['startedAt' => 'x', 'outcome' => 'skipped', 'reason' => 'condition false']])['lastError']);
		$this->assertNull($page->utc(value: ''));
		$this->assertSame('2026-11-13T23:00:00', $page->utc(value: '2026-11-14T00:00:00+01:00'));
	}//end testTheSummaryCarriesAnErrorOnlyForAFailure()
}//end class
