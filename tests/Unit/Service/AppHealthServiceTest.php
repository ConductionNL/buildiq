<?php

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Buildiq\Service\AppHealthSamples;
use OCA\Buildiq\Service\AppHealthService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * REQ-BQDM-005, REQ-BQDM-006: samples are stored with the server's ids and read per window.
 */
final class AppHealthServiceTest extends TestCase {
	public function testRecordStoresOneSamplePerPageWithTheServersIds(): void {
		$saved = [];
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('saveObject')->willReturnCallback(function (array $object, ...$args) use (&$saved) {
			$saved[] = ['object' => $object, 'args' => $args];
			return $this->createMock(\OCA\OpenRegister\Contract\ObjectEntityInterface::class);
		});

		$stored = (new AppHealthService($objects, new AppHealthSamples(), new NullLogger()))
			->record(applicationUuid: 'app-1', versionUuid: 'ver-1', pages: [
				['pageId' => 'permits', 'loads' => 3, 'applicationUuid' => 'someone-elses-app'],
				['pageId' => 'idle'],
			]);

		self::assertSame(1, $stored);
		self::assertSame('app-1', $saved[0]['object']['applicationUuid']);
		self::assertSame('ver-1', $saved[0]['object']['versionUuid']);
		self::assertSame('permits', $saved[0]['object']['pageId']);
		// Positional after the object: extend, register, schema, uuid, _rbac, _multitenancy.
		self::assertSame(['buildiq', 'app-health-sample', null, false, false], array_slice($saved[0]['args'], 1, 5));
	}//end testRecordStoresOneSamplePerPageWithTheServersIds()

	public function testHealthReadsTheVersionsSamplesInsideTheWindow(): void {
		$queries = [];
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(static function (array $config) use (&$queries): array {
			$queries[] = $config;
			return [
				['pageId' => 'permits', 'loads' => 2, 'loadMsSum' => 2000, 'windowStart' => '2026-10-20T10:00:00+00:00'],
				['pageId' => 'permits', 'loads' => 9, 'loadMsSum' => 90000, 'windowStart' => '2026-09-01T10:00:00+00:00'],
			];
		});

		$health = (new AppHealthService($objects, new AppHealthSamples(), new NullLogger()))
			->computeHealth(applicationUuid: 'app-1', versionUuid: 'ver-1', window: '7d', now: new DateTimeImmutable('2026-10-23T00:00:00+00:00'));

		self::assertSame('app-1', $queries[0]['filters']['applicationUuid']);
		self::assertSame('ver-1', $queries[0]['filters']['versionUuid']);
		self::assertArrayHasKey('limit', $queries[0]);
		self::assertSame(2, $health['loads']);
		self::assertSame(1000, $health['averageLoadMs']);
		self::assertNull((new AppHealthService($objects, new AppHealthSamples(), new NullLogger()))->computeHealth('a', 'v', '1y'));
	}//end testHealthReadsTheVersionsSamplesInsideTheWindow()
}//end class
