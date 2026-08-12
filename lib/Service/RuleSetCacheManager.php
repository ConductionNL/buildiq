<?php

/**
 * OpenBuild RuleSetCacheManager
 *
 * Caches loaded RuleSet bundles (the RuleSet object plus its DecisionTables and
 * ConditionActionRules) in Nextcloud's distributed memory cache so the runtime
 * does not re-query OpenRegister on every evaluation. Activation of a new
 * version invalidates the cache key; the bounded TTL (design.md Decision 6, 30s)
 * guarantees a stale entry is evicted within the hot-reload window even if an
 * explicit invalidation event is missed in a multi-instance deployment.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenBuild\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/business-rules-engine/tasks.md#5.2
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Service;

use OCP\ICache;
use OCP\ICacheFactory;

/**
 * Hot-reload cache for compiled RuleSet bundles.
 */
class RuleSetCacheManager {

	/**
	 * Hot-reload TTL in seconds (design.md Decision 6).
	 */
	public const TTL_SECONDS = 30;

	/**
	 * Cache namespace prefix.
	 */
	private const NAMESPACE = 'openbuild.rules';

	/**
	 * The distributed cache instance (null when caching unavailable).
	 *
	 * @var ICache|null
	 */
	private ?ICache $cache;

	/**
	 * Constructor.
	 *
	 * @param ICacheFactory $cacheFactory Nextcloud cache factory.
	 *
	 * @return void
	 */
	public function __construct(ICacheFactory $cacheFactory) {
		$this->cache = null;
		if ($cacheFactory->isAvailable() === true) {
			$this->cache = $cacheFactory->createDistributed(self::NAMESPACE);
		}

	}//end __construct()

	/**
	 * Fetch a cached RuleSet bundle.
	 *
	 * @param string $slug The RuleSet slug.
	 * @param string|null $version Optional pinned version.
	 *
	 * @return array<string,mixed>|null The cached bundle, or null on a miss.
	 */
	public function get(string $slug, ?string $version = null): ?array {
		if ($this->cache === null) {
			return null;
		}

		$cached = $this->cache->get($this->key(slug: $slug, version: $version));
		if (is_array($cached) === true) {
			return $cached;
		}

		return null;
	}//end get()

	/**
	 * Store a RuleSet bundle with the bounded hot-reload TTL.
	 *
	 * @param string $slug The RuleSet slug.
	 * @param array<string,mixed> $bundle The compiled bundle.
	 * @param string|null $version Optional pinned version.
	 *
	 * @return void
	 */
	public function set(string $slug, array $bundle, ?string $version = null): void {
		if ($this->cache === null) {
			return;
		}

		$this->cache->set($this->key(slug: $slug, version: $version), $bundle, self::TTL_SECONDS);

	}//end set()

	/**
	 * Invalidate all cached entries for a RuleSet slug.
	 *
	 * @param string $slug The RuleSet slug.
	 *
	 * @return void
	 */
	public function invalidate(string $slug): void {
		if ($this->cache === null) {
			return;
		}

		$this->cache->remove($this->key(slug: $slug, version: null));

	}//end invalidate()

	/**
	 * Build a cache key for a slug + optional version.
	 *
	 * @param string $slug The RuleSet slug.
	 * @param string|null $version Optional version.
	 *
	 * @return string
	 */
	private function key(string $slug, ?string $version): string {
		return $slug . '@' . ($version ?? 'active');
	}//end key()
}//end class
