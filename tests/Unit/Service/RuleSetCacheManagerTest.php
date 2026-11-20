<?php

/**
 * Unit tests for RuleSetCacheManager: keys per slug and version, the TTL,
 * invalidation of the active bundle, and a server without a distributed cache.
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

use OCA\Buildiq\Service\RuleSetCacheManager;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the rule-set bundle cache.
 *
 * @covers \OCA\Buildiq\Service\RuleSetCacheManager
 */
final class RuleSetCacheManagerTest extends TestCase {

	/**
	 * A manager over a cache mock, created under the rules namespace.
	 *
	 * @param ICache $cache The cache.
	 *
	 * @return RuleSetCacheManager
	 */
	private function manager(ICache $cache): RuleSetCacheManager {
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(true);
		$factory->expects($this->once())->method('createDistributed')->with('buildiq.rules')->willReturn($cache);
		return new RuleSetCacheManager($factory);
	}//end manager()

	/**
	 * A bundle is stored under slug@version for thirty seconds; without a version under slug@active.
	 *
	 * @return void
	 */
	public function testSetStoresUnderSlugAndVersionWithTheTtl(): void {
		$cache = $this->createMock(ICache::class);
		$cache->expects($this->exactly(2))->method('set')->willReturnCallback(
			function (string $key, mixed $value, int $ttl) use (&$calls): bool {
				$calls[] = [$key, $value, $ttl];
				return true;
			}
		);
		$calls = [];

		$manager = $this->manager($cache);
		$manager->set('loan-rules', ['rules' => [1]], '2.0.0');
		$manager->set('loan-rules', ['rules' => [2]]);

		self::assertSame(
			[
				['loan-rules@2.0.0', ['rules' => [1]], 30],
				['loan-rules@active', ['rules' => [2]], 30],
			],
			$calls
		);
	}//end testSetStoresUnderSlugAndVersionWithTheTtl()

	/**
	 * An array hit is returned; anything else in the cache reads as a miss.
	 *
	 * @return void
	 */
	public function testGetReturnsOnlyArrayHits(): void {
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnMap(
			[
				['loan-rules@active', ['rules' => []]],
				['loan-rules@1.0.0', 'corrupt'],
				['other@active', null],
			]
		);

		$manager = $this->manager($cache);

		self::assertSame(['rules' => []], $manager->get('loan-rules'));
		self::assertNull($manager->get('loan-rules', '1.0.0'));
		self::assertNull($manager->get('other'));
	}//end testGetReturnsOnlyArrayHits()

	/**
	 * Invalidating a slug removes its active bundle.
	 *
	 * @return void
	 */
	public function testInvalidateRemovesTheActiveBundle(): void {
		$cache = $this->createMock(ICache::class);
		$cache->expects($this->once())->method('remove')->with('loan-rules@active');

		$this->manager($cache)->invalidate('loan-rules');
	}//end testInvalidateRemovesTheActiveBundle()

	/**
	 * Without a distributed cache nothing is created, every read misses and writes are no-ops.
	 *
	 * @return void
	 */
	public function testWithoutACacheEverythingIsANoOp(): void {
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(false);
		$factory->expects($this->never())->method('createDistributed');

		$manager = new RuleSetCacheManager($factory);
		$manager->set('loan-rules', ['rules' => []]);
		$manager->invalidate('loan-rules');

		self::assertNull($manager->get('loan-rules'));
	}//end testWithoutACacheEverythingIsANoOp()
}//end class
