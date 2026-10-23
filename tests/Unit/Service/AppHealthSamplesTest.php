<?php

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\AppHealthSamples;
use PHPUnit\Framework\TestCase;

/**
 * REQ-BQDM-004, REQ-BQDM-005, REQ-BQDM-006: what a sample may hold and what a window adds up to.
 */
final class AppHealthSamplesTest extends TestCase {
	public function testASampleKeepsOnlyCappedCountsAndAValidPageId(): void {
		$kept = (new AppHealthSamples())->sanitise(pages: [
			['pageId' => 'permits', 'requests' => 3, 'failures5xx' => 1, 'loads' => 3, 'userId' => 'anna', 'message' => 'boom', 'path' => '/objects/x?id=1'],
			['pageId' => 'big', 'requests' => 10_000_000, 'loadMsSum' => -5],
			['pageId' => 'not a page id!', 'requests' => 1],
			['pageId' => 'idle'],
			'junk',
		]);

		self::assertSame(['permits', 'big'], array_column($kept, 'pageId'));
		self::assertArrayNotHasKey('userId', $kept[0]);
		self::assertArrayNotHasKey('message', $kept[0]);
		self::assertArrayNotHasKey('path', $kept[0]);
		self::assertSame(1, $kept[0]['failures5xx']);
		self::assertSame(AppHealthSamples::MAX_COUNT, $kept[1]['requests']);
		self::assertSame(0, $kept[1]['loadMsSum']);
	}//end testASampleKeepsOnlyCappedCountsAndAValidPageId()

	public function testAtMostFiftyPagesPerPost(): void {
		$pages = array_map(static fn (int $i): array => ['pageId' => 'p' . $i, 'loads' => 1], range(1, 80));

		self::assertCount(AppHealthSamples::MAX_PAGES, (new AppHealthSamples())->sanitise(pages: $pages));
	}//end testAtMostFiftyPagesPerPost()

	public function testTheSlowestPageComesFirstWithItsAverage(): void {
		$health = (new AppHealthSamples())->aggregate(samples: [
			['pageId' => 'permit-detail', 'loads' => 5, 'loadMsSum' => 12000, 'slowLoads' => 2, 'requests' => 20, 'failures5xx' => 1, 'durationUnder3000' => 4],
			['pageId' => 'permits', 'loads' => 10, 'loadMsSum' => 8000, 'requests' => 30, 'failures4xx' => 2, 'durationOver3000' => 1],
			['pageId' => 'permit-detail', 'loads' => 5, 'loadMsSum' => 12000, 'requests' => 10],
		]);

		self::assertSame('permit-detail', $health['slowestPages'][0]['pageId']);
		self::assertSame(2400, $health['slowestPages'][0]['averageLoadMs']);
		self::assertSame(0.2, $health['slowestPages'][0]['slowLoadShare']);
		self::assertSame(60, $health['requests']);
		self::assertSame(3, $health['failures']);
		self::assertSame(0.05, $health['failureShare']);
		self::assertSame(round(5 / 60, 4), $health['slowRequestShare']);
		self::assertSame(1600, $health['averageLoadMs']);
	}//end testTheSlowestPageComesFirstWithItsAverage()

	public function testNoSamplesGiveNoShares(): void {
		$health = (new AppHealthSamples())->aggregate(samples: []);

		self::assertNull($health['failureShare']);
		self::assertNull($health['averageLoadMs']);
		self::assertSame([], $health['slowestPages']);
	}//end testNoSamplesGiveNoShares()
}//end class
