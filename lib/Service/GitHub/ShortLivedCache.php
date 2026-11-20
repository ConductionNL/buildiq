<?php

/**
 * Buildiq Short-Lived Cache
 *
 * A distributed cache entry lifetime bound to one kind of answer, so a caller
 * names the answer rather than its TTL on every write. Without a cache backend
 * every read misses and every write is a no-op, which is how the catalogues
 * behave on an instance without memcache. Built by GitHubCatalogService (search
 * results, descriptors) and GitHubFormCatalogService (form search results).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Buildiq\Service\GitHub
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

namespace OCA\Buildiq\Service\GitHub;

use OCP\ICache;

/**
 * Reads and writes one kind of answer with a fixed TTL.
 *
 * @spec openspec/changes/github-shop-catalogue/specs/github-shop-catalogue/spec.md
 */
final class ShortLivedCache {
	/**
	 * Constructor.
	 *
	 * @param ICache|null $cache The distributed cache, or null when no backend is available.
	 * @param int $ttl The lifetime of every entry written, in seconds.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ?ICache $cache,
		private readonly int $ttl,
	) {
	}//end __construct()

	/**
	 * Read a value (null on a miss or without a backend).
	 *
	 * @param string $key The cache key.
	 *
	 * @return mixed The cached value, or null.
	 *
	 * @spec openspec/changes/github-shop-catalogue/specs/github-shop-catalogue/spec.md
	 */
	public function get(string $key): mixed {
		if ($this->cache === null) {
			return null;
		}

		return $this->cache->get($key);
	}//end get()

	/**
	 * Write a value for this cache's TTL (no-op without a backend).
	 *
	 * @param string $key The cache key.
	 * @param mixed $value The value.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/github-shop-catalogue/specs/github-shop-catalogue/spec.md
	 */
	public function set(string $key, mixed $value): void {
		if ($this->cache === null) {
			return;
		}

		$this->cache->set($key, $value, $this->ttl);
	}//end set()
}//end class
