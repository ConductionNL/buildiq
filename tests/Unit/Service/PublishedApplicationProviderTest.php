<?php

/**
 * Unit tests for PublishedApplicationProvider.
 *
 * Covers the single per-request read that serves both the top-bar nav entries
 * and the promoted dashboard widgets.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\PublishedApplicationProvider;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see PublishedApplicationProvider}.
 */
class PublishedApplicationProviderTest extends TestCase {
	/**
	 * Mock object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Build the mock.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
	}//end setUp()

	/**
	 * Happy path: one query, filtered to published Applications, with the
	 * production version extended so the manifest arrives in the same read.
	 *
	 * @return void
	 */
	public function testQueriesPublishedApplicationsWithProductionVersionExtended(): void {
		$captured = null;
		$this->objectService
			->expects($this->once())
			->method('findAll')
			->willReturnCallback(function (array $config) use (&$captured): array {
				$captured = $config;
				return [['slug' => 'pet-store', 'status' => 'published']];
			});

		$provider = new PublishedApplicationProvider(objectService: $this->objectService);
		$applications = $provider->getPublishedApplications();

		$this->assertCount(1, $applications);
		$this->assertSame('pet-store', $applications[0]['slug']);
		$this->assertSame('published', $captured['filters']['status']);
		$this->assertSame('buildiq', $captured['filters']['register']);
		$this->assertSame('built-app', $captured['filters']['schema']);
		$this->assertContains(
			'productionVersion',
			$captured['extend'],
			'The production version must be extended in this same read. Fetching it per '
			. 'application afterwards is the N+1 this provider exists to prevent.'
		);
	}//end testQueriesPublishedApplicationsWithProductionVersionExtended()

	/**
	 * The per-request cache: a second call inside one request re-reads nothing.
	 *
	 * @return void
	 */
	public function testSecondCallIsServedFromTheRequestCache(): void {
		$this->objectService
			->expects($this->once())
			->method('findAll')
			->willReturn([['slug' => 'pet-store']]);

		$provider = new PublishedApplicationProvider(objectService: $this->objectService);
		$first = $provider->getPublishedApplications();
		$second = $provider->getPublishedApplications();

		$this->assertSame($first, $second);
	}//end testSecondCallIsServedFromTheRequestCache()

	/**
	 * Error handling: a failing object service propagates, so each caller can
	 * decide for itself whether to log-and-continue.
	 *
	 * @return void
	 */
	public function testAFailingObjectServicePropagates(): void {
		$this->objectService
			->method('findAll')
			->willThrowException(new \RuntimeException('OpenRegister is down'));

		$provider = new PublishedApplicationProvider(objectService: $this->objectService);

		$this->expectException(\RuntimeException::class);
		$provider->getPublishedApplications();
	}//end testAFailingObjectServicePropagates()

	/**
	 * Edge case: OpenRegister answers with entity objects rather than arrays.
	 *
	 * @return void
	 */
	public function testEntityResultsAreNormalisedToArrays(): void {
		$entity = new class {
			/**
			 * Serialise like an OpenRegister ObjectEntity.
			 *
			 * @return array<string,mixed>
			 */
			public function jsonSerialize(): array {
				return ['slug' => 'library-desk', 'name' => 'Library Desk'];
			}
		};

		$this->objectService->method('findAll')->willReturn([$entity]);

		$provider = new PublishedApplicationProvider(objectService: $this->objectService);
		$applications = $provider->getPublishedApplications();

		$this->assertSame(
			[['slug' => 'library-desk', 'name' => 'Library Desk']],
			$applications
		);
	}//end testEntityResultsAreNormalisedToArrays()

	/**
	 * A cache factory over an in-memory distributed cache shared by every provider built with it.
	 *
	 * @param array<string,mixed> $store The shared store, by reference.
	 *
	 * @return ICacheFactory The factory.
	 */
	private function cacheFactory(array &$store): ICacheFactory {
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(static function (string $key) use (&$store) {
			return ($store[$key] ?? null);
		});
		$cache->method('set')->willReturnCallback(static function (string $key, $value) use (&$store): bool {
			$store[$key] = $value;
			return true;
		});
		$cache->method('remove')->willReturnCallback(static function (string $key) use (&$store): bool {
			unset($store[$key]);
			return true;
		});

		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(true);
		$factory->method('createDistributed')
			->with(PublishedApplicationProvider::CACHE_NAMESPACE)
			->willReturn($cache);
		return $factory;
	}//end cacheFactory()

	/**
	 * Boot runs on every request: a later request reads the distributed cache, not OpenRegister,
	 * and does not even build the object service.
	 *
	 * @return void
	 */
	public function testALaterRequestIsServedFromTheDistributedCache(): void {
		$store = [];
		$this->objectService
			->expects($this->once())
			->method('findAll')
			->willReturn([['slug' => 'pet-store']]);

		$first = new PublishedApplicationProvider(objectService: $this->objectService, cacheFactory: $this->cacheFactory($store));
		$this->assertSame([['slug' => 'pet-store']], $first->getPublishedApplications());

		$built = 0;
		$later = new PublishedApplicationProvider(
			objectService: function () use (&$built): ObjectServiceInterface {
				$built++;
				return $this->objectService;
			},
			cacheFactory: $this->cacheFactory($store)
		);
		$this->assertSame([['slug' => 'pet-store']], $later->getPublishedApplications());
		$this->assertSame(0, $built);
	}//end testALaterRequestIsServedFromTheDistributedCache()

	/**
	 * After invalidate() the next read asks OpenRegister again, in this request and in a later one.
	 *
	 * @return void
	 */
	public function testInvalidateDropsTheCachedRead(): void {
		$store = [];
		$this->objectService
			->expects($this->exactly(2))
			->method('findAll')
			->willReturnOnConsecutiveCalls([['slug' => 'pet-store']], []);

		$provider = new PublishedApplicationProvider(objectService: $this->objectService, cacheFactory: $this->cacheFactory($store));
		$provider->getPublishedApplications();
		$provider->invalidate();
		$this->assertSame([], $provider->getPublishedApplications());

		$later = new PublishedApplicationProvider(objectService: $this->objectService, cacheFactory: $this->cacheFactory($store));
		$this->assertSame([], $later->getPublishedApplications());
	}//end testInvalidateDropsTheCachedRead()

	/**
	 * A closure for the object service is called only on a cache miss.
	 *
	 * @return void
	 */
	public function testAClosureObjectServiceIsResolvedOnAMiss(): void {
		$this->objectService->method('findAll')->willReturn([['slug' => 'pet-store']]);

		$provider = new PublishedApplicationProvider(objectService: fn (): ObjectServiceInterface => $this->objectService);

		$this->assertSame([['slug' => 'pet-store']], $provider->getPublishedApplications());
	}//end testAClosureObjectServiceIsResolvedOnAMiss()
}//end class
