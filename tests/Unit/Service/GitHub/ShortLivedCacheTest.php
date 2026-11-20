<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tests the optional short-lived cache in front of GitHub lookups.
 *
 * @category Test
 * @package  Buildiq
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl
 * @link     https://github.com/ConductionNL/buildiq
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service\GitHub;

use OCA\Buildiq\Service\GitHub\ShortLivedCache;
use OCP\ICache;
use PHPUnit\Framework\TestCase;

/**
 * With a cache, reads and writes go through it with the configured lifetime;
 * without one, every read misses and writes are dropped.
 *
 * @covers \OCA\Buildiq\Service\GitHub\ShortLivedCache
 */
class ShortLivedCacheTest extends TestCase {
	/**
	 * A read returns what the cache holds under the key.
	 *
	 * @return void
	 */
	public function testAReadAsksTheCache(): void {
		$cache = $this->createMock(originalClassName: ICache::class);
		$cache->expects($this->once())->method('get')->with('repo:1')->willReturn(['id' => 1]);

		$this->assertSame(expected: ['id' => 1], actual: (new ShortLivedCache(cache: $cache, ttl: 30))->get(key: 'repo:1'));
	}//end testAReadAsksTheCache()

	/**
	 * A write stores the value with the configured lifetime.
	 *
	 * @return void
	 */
	public function testAWriteUsesTheConfiguredLifetime(): void {
		$cache = $this->createMock(originalClassName: ICache::class);
		$cache->expects($this->once())->method('set')->with('repo:1', 'value-1', 45);

		(new ShortLivedCache(cache: $cache, ttl: 45))->set(key: 'repo:1', value: 'value-1');
	}//end testAWriteUsesTheConfiguredLifetime()

	/**
	 * Without a cache a read misses and a write is a no-op.
	 *
	 * @return void
	 */
	public function testWithoutACacheNothingIsKept(): void {
		$none = new ShortLivedCache(cache: null, ttl: 30);
		$none->set(key: 'repo:1', value: 'value-1');

		$this->assertNull(actual: $none->get(key: 'repo:1'));
	}//end testWithoutACacheNothingIsKept()
}//end class
