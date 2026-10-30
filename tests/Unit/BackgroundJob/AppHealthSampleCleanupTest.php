<?php

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\BackgroundJob;

use OCA\Buildiq\BackgroundJob\AppHealthSampleCleanup;
use OCA\Buildiq\Service\AppHealthSamples;
use OCA\Buildiq\Service\AppHealthService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * REQ-BQDM-005: samples older than the retention window are deleted, newer ones stay.
 */
final class AppHealthSampleCleanupTest extends TestCase {
	public function testOldSamplesAreRemoved(): void {
		$deleted = [];
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturn([
			['id' => 'old', 'windowStart' => '2026-09-21T10:00:00+00:00'],
			['id' => 'new', 'windowStart' => '2026-10-22T10:00:00+00:00'],
		]);
		$objects->method('deleteObject')->willReturnCallback(static function (string $uuid) use (&$deleted): bool {
			$deleted[] = $uuid;
			return true;
		});
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueInt')->willReturn(30);
		$health = new AppHealthService($objects, new AppHealthSamples(), new NullLogger());

		$job = new AppHealthSampleCleanup($this->createMock(ITimeFactory::class), $objects, $health, $config, new NullLogger());
		$count = $job->purge(now: strtotime('2026-10-23T00:00:00+00:00'));

		self::assertSame(['old'], $deleted);
		self::assertSame(1, $count);
	}//end testOldSamplesAreRemoved()
}//end class
