<?php

/**
 * Buildiq App Health Samples
 *
 * Pure functions for the health samples of running built apps: what a posted
 * batch may hold, and what a window of samples adds up to.
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Buildiq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/operate-debug-log-and-monitoring/specs/built-app-health-monitoring/spec.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

/**
 * Sanitises posted samples and aggregates stored ones. No I/O.
 *
 * @spec openspec/changes/operate-debug-log-and-monitoring/specs/built-app-health-monitoring/spec.md
 */
class AppHealthSamples {
	/**
	 * The counts a sample may carry; everything else is dropped.
	 */
	public const COUNTS = [
		'requests',
		'failures4xx',
		'failures5xx',
		'durationUnder250',
		'durationUnder1000',
		'durationUnder3000',
		'durationOver3000',
		'loads',
		'loadMsSum',
		'slowLoads',
		'clientErrors',
	];

	/**
	 * The highest value one count may hold in one sample.
	 */
	public const MAX_COUNT = 10000;

	/**
	 * The most pages one post may carry.
	 */
	public const MAX_PAGES = 50;

	/**
	 * How many slowest pages the health section names.
	 */
	public const SLOWEST = 5;

	/**
	 * The page ids a sample may name.
	 */
	private const PAGE_ID = '/^[A-Za-z0-9_.:-]{1,128}$/';

	/**
	 * The samples a posted body may become: known counts only, capped, at most MAX_PAGES pages.
	 *
	 * @param mixed $pages The posted `pages` list.
	 *
	 * @return list<array<string, int|string>> One `{pageId, ...counts}` per kept page.
	 *
	 * @spec openspec/changes/operate-debug-log-and-monitoring/specs/built-app-health-monitoring/spec.md#requirement-the-server-scopes-and-bounds-samples-req-bqdm-005
	 */
	public function sanitise(mixed $pages): array {
		if (is_array($pages) === false) {
			return [];
		}

		$kept = [];
		foreach (array_slice(array_values($pages), 0, self::MAX_PAGES) as $page) {
			if (is_array($page) === false || is_string($page['pageId'] ?? null) === false || preg_match(self::PAGE_ID, $page['pageId']) !== 1) {
				continue;
			}

			$sample = ['pageId' => $page['pageId']];
			$total = 0;
			foreach (self::COUNTS as $count) {
				$sample[$count] = $this->count(value: $page[$count] ?? 0, max: $this->maxFor(count: $count));
				$total += $sample[$count];
			}

			if ($total > 0) {
				$kept[] = $sample;
			}
		}

		return $kept;
	}//end sanitise()

	/**
	 * What a window of stored samples adds up to.
	 *
	 * @param iterable<array<string, mixed>> $samples The stored samples.
	 *
	 * @return array<string, mixed> Totals, shares and the slowest pages.
	 *
	 * @spec openspec/changes/operate-debug-log-and-monitoring/specs/built-app-health-monitoring/spec.md#requirement-the-app-detail-page-shows-the-app-s-health-req-bqdm-006
	 */
	public function aggregate(iterable $samples): array {
		$total = array_fill_keys(self::COUNTS, 0);
		$pages = [];
		foreach ($samples as $sample) {
			$pageId = (string)($sample['pageId'] ?? '');
			$pages[$pageId] ??= array_fill_keys(self::COUNTS, 0);
			foreach (self::COUNTS as $count) {
				$value = (int)($sample[$count] ?? 0);
				$total[$count] += $value;
				$pages[$pageId][$count] += $value;
			}
		}

		$rows = [];
		foreach ($pages as $pageId => $counts) {
			if ($pageId === '' || $counts['loads'] === 0) {
				continue;
			}

			$rows[] = [
				'pageId' => $pageId,
				'loads' => $counts['loads'],
				'averageLoadMs' => (int)round($counts['loadMsSum'] / $counts['loads']),
				'slowLoadShare' => $this->share(part: $counts['slowLoads'], whole: $counts['loads']),
				'failures' => $counts['failures4xx'] + $counts['failures5xx'],
			];
		}

		usort($rows, static fn (array $a, array $b): int => $b['averageLoadMs'] <=> $a['averageLoadMs']);

		$failures = $total['failures4xx'] + $total['failures5xx'];
		$overOneSecond = $total['durationUnder3000'] + $total['durationOver3000'];

		return [
			'requests' => $total['requests'],
			'failures' => $failures,
			'failureShare' => $this->share(part: $failures, whole: $total['requests']),
			'slowRequestShare' => $this->share(part: $overOneSecond, whole: $total['requests']),
			'loads' => $total['loads'],
			'averageLoadMs' => $this->average(sum: $total['loadMsSum'], count: $total['loads']),
			'slowLoadShare' => $this->share(part: $total['slowLoads'], whole: $total['loads']),
			'clientErrors' => $total['clientErrors'],
			'slowestPages' => array_slice($rows, 0, self::SLOWEST),
		];
	}//end aggregate()

	/**
	 * The cap of one count: the total load time may be MAX_COUNT loads of a minute.
	 *
	 * @param string $count The count.
	 *
	 * @return int The cap.
	 */
	private function maxFor(string $count): int {
		if ($count === 'loadMsSum') {
			return self::MAX_COUNT * 60000;
		}

		return self::MAX_COUNT;
	}//end maxFor()

	/**
	 * A count as a whole number between 0 and the cap.
	 *
	 * @param mixed $value The posted value.
	 * @param int $max The cap.
	 *
	 * @return int The count.
	 */
	private function count(mixed $value, int $max): int {
		if (is_int($value) === false && (is_float($value) === false || is_finite($value) === false)) {
			return 0;
		}

		return max(0, min($max, (int)$value));
	}//end count()

	/**
	 * part / whole between 0 and 1, or null when there is nothing to measure.
	 *
	 * @param int $part The part.
	 * @param int $whole The whole.
	 *
	 * @return float|null The share, rounded to four decimals.
	 */
	private function share(int $part, int $whole): ?float {
		if ($whole <= 0) {
			return null;
		}

		return round($part / $whole, 4);
	}//end share()

	/**
	 * The average, or null without a count.
	 *
	 * @param int $sum The sum.
	 * @param int $count The count.
	 *
	 * @return int|null The rounded average.
	 */
	private function average(int $sum, int $count): ?int {
		if ($count <= 0) {
			return null;
		}

		return (int)round($sum / $count);
	}//end average()
}//end class
