<?php

/**
 * Golden test for ChannelApplyReport::adoptCounts().
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\ChannelApplyReport;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins the report after a source's counts are adopted into a channel that was
 * declared larger, equal, smaller or not at all, with and without the
 * source's truncation flag and breakdown, and after a second adoption. Set
 * GOLDEN_RECORD=1 to write a missing fixture.
 *
 * @covers \OCA\Buildiq\Service\ChannelApplyReport
 */
class ChannelAdoptCountsGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/channel-adopt-counts.golden.json';

	/**
	 * Adopt one set of counts through the public entry.
	 *
	 * @param ChannelApplyReport  $report The report.
	 * @param array<string,mixed> $c      Channel, created, skipped, failed, truncated, sourceCounts.
	 *
	 * @return void
	 */
	private function adopt(ChannelApplyReport $report, array $c): void {
		$channel = $c['channel'];
		unset($c['channel']);
		$report->adoptCounts(channel: $channel, outcome: $c);
	}//end adopt()

	/**
	 * Every case matches its recorded report.
	 *
	 * @return void
	 */
	public function testAdoptCountsMatchesGolden(): void {
		$declared = ['none' => null, 'zero' => 0, 'three' => 3, 'ten' => 10];
		$counts = [
			'empty' => [0, 0, 0],
			'all created' => [3, 0, 0],
			'mixed' => [1, 1, 1],
			'short' => [2, 1, 0],
			'over' => [8, 4, 2],
		];
		$breakdowns = ['none' => [], 'some' => ['installed' => 1, 'updated' => 0, 'unchanged' => 2]];

		$actual = [];
		foreach ($declared as $dn => $declaredCount) {
			foreach ($counts as $cn => [$created, $skipped, $failed]) {
				foreach ([false, true] as $truncated) {
					foreach ($breakdowns as $bn => $breakdown) {
						foreach (['once' => 1, 'twice' => 2] as $rn => $rounds) {
							$report = new ChannelApplyReport();
							if ($declaredCount !== null) {
								$report->declareChannel(channel: 'skills', declared: $declaredCount);
							}

							for ($i = 0; $i < $rounds; $i++) {
								$this->adopt(
									report: $report,
									c: [
										'channel' => 'skills',
										'created' => $created,
										'skipped' => $skipped,
										'failed' => $failed,
										'truncated' => $truncated,
										'sourceCounts' => $breakdown,
									]
								);
							}

							$t = 'truncated ' . var_export($truncated, true);
							try {
								$actual["declared $dn / $cn / $t / breakdown $bn / $rn"] = $report->toArray();
							} catch (RuntimeException $e) {
								$actual["declared $dn / $cn / $t / breakdown $bn / $rn"] = ['refused' => $e->getMessage()];
							}
						}//end foreach
					}//end foreach
				}//end foreach
			}//end foreach
		}//end foreach

		if (file_exists(self::FIXTURE) === false && getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		foreach ($actual as $name => $result) {
			self::assertSame($expected[$name] ?? null, json_decode((string)json_encode($result), true), $name);
		}

		self::assertSame(array_keys($expected), array_keys($actual));
	}//end testAdoptCountsMatchesGolden()
}//end class
