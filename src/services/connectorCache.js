// SPDX-License-Identifier: EUPL-1.2
/**
 * connectorCache — module-scoped, session-lifetime cache for OpenConnector
 * data-source responses. Keyed by `appId + endpointPath + stable query hash`
 * so two widgets binding the same endpoint with the same query share one
 * entry (and one in-flight request).
 *
 * Cache semantics (REQ-OCAS-006):
 *  - `cacheTtl` (seconds) governs freshness; a fresh entry is served without
 *    a network call.
 *  - Concurrent reads within the TTL share the single in-flight promise
 *    (dedupe) so three mounting widgets issue one request.
 *  - On a refresh failure, an entry no older than 10× TTL is served stale.
 *
 * The cache holds no credential material — only the projected response —
 * consistent with REQ-OCAS-004 (auth lives entirely in OpenConnector).
 *
 * @spec openspec/changes/archive/2026-06-14-openconnector-api-sources/tasks.md#task-4.2
 */

/** @type {Map<string, {data: *, fetchedAt: number, ttlMs: number, inflight: Promise<*>|null}>} */
const store = new Map()

/**
 * Produce a stable string for a query object so key ordering does not matter
 * (`{a:1,b:2}` and `{b:2,a:1}` hash identically).
 *
 * @param {object} [query] - query parameter map.
 * @return {string}
 * @spec openspec/specs/openconnector-api-sources/spec.md#req-ocas-006
 */
export function stableQueryHash(query) {
	if (!query || typeof query !== 'object') {
		return ''
	}
	const keys = Object.keys(query).sort()
	return keys.map((k) => `${k}=${String(query[k])}`).join('&')
}

/**
 * Build the cache key for a binding.
 *
 * @param {string} appId - the virtual app id (namespacing).
 * @param {string} endpointPath - the OpenConnector endpoint path.
 * @param {object} [query] - query parameter map.
 * @return {string}
 * @spec openspec/specs/openconnector-api-sources/spec.md#req-ocas-006
 */
export function cacheKey(appId, endpointPath, query) {
	return `${appId || ''}::${endpointPath || ''}::${stableQueryHash(query)}`
}

/**
 * Clamp a `cacheTtl` (seconds) to the spec bounds and convert to ms.
 *
 * @param {number} [ttlSeconds] - raw cacheTtl in seconds.
 * @return {number} - TTL in milliseconds (0–3600 s clamped).
 * @spec openspec/specs/openconnector-api-sources/spec.md#req-ocas-006
 */
export function ttlToMs(ttlSeconds) {
	const DEFAULT = 60
	const n = Number.isFinite(ttlSeconds) ? ttlSeconds : DEFAULT
	const clamped = Math.max(0, Math.min(3600, n))
	return clamped * 1000
}

/**
 * Whether an entry holds data loaded within its own TTL.
 *
 * @param {object|undefined} entry - stored cache entry.
 * @param {number} t - current time in ms.
 * @return {boolean}
 * @spec openspec/specs/openconnector-api-sources/spec.md#req-ocas-006
 */
function isFresh(entry, t) {
	return hasData(entry) && t - entry.fetchedAt < entry.ttlMs
}

/**
 * Whether an entry exists and holds loaded data.
 *
 * @param {object|undefined} entry - stored cache entry.
 * @return {boolean}
 * @spec openspec/specs/openconnector-api-sources/spec.md#req-ocas-006
 */
function hasData(entry) {
	return Boolean(entry) && entry.data !== undefined
}

/**
 * The data and load time an entry carries over, stored under a new TTL.
 *
 * @param {object|undefined} entry - stored cache entry.
 * @param {number} ttlMs - freshness window in ms.
 * @return {{data: unknown, fetchedAt: number, ttlMs: number}}
 * @spec openspec/specs/openconnector-api-sources/spec.md#req-ocas-006
 */
function carriedOver(entry, ttlMs) {
	return {
		data: entry ? entry.data : undefined,
		fetchedAt: entry ? entry.fetchedAt : 0,
		ttlMs,
	}
}

/**
 * Run the loader and keep its promise in flight on the entry.
 *
 * @param {{key: string, ttlMs: number, now: () => number}} request - the read.
 * @param {{entry: (object|undefined), loader: () => Promise<unknown>}} source - the current entry and the loader.
 * @return {Promise<unknown>} the loader's promise.
 * @spec openspec/specs/openconnector-api-sources/spec.md#req-ocas-006
 */
function startLoad(request, source) {
	const { entry, loader } = source
	const inflight = loader()
	store.set(request.key, { ...carriedOver(entry, request.ttlMs), inflight })
	return inflight
}

/**
 * Store freshly loaded data.
 *
 * @param {{key: string, ttlMs: number, now: () => number}} request - the read.
 * @param {unknown} data - the loaded data.
 * @return {{data: unknown, isStale: boolean}}
 * @spec openspec/specs/openconnector-api-sources/spec.md#req-ocas-006
 */
function storeLoaded(request, data) {
	const { key, ttlMs, now } = request
	store.set(key, { data, fetchedAt: now(), ttlMs, inflight: null })
	return { data, isStale: false }
}

/**
 * Stale-on-error: settle the entry and serve its data when it is no older
 * than 10x TTL, otherwise rethrow the load error.
 *
 * @param {{key: string, ttlMs: number, now: () => number}} request - the read.
 * @param {Error} err - the load error.
 * @return {{data: unknown, isStale: boolean}}
 * @spec openspec/specs/openconnector-api-sources/spec.md#req-ocas-006
 */
function serveStale(request, err) {
	const { key, ttlMs, now } = request
	const prev = store.get(key)
	store.set(key, { ...carriedOver(prev, ttlMs), inflight: null })
	if (hasData(prev) && now() - prev.fetchedAt < ttlMs * 10) {
		return { data: prev.data, isStale: true }
	}
	throw err
}

/**
 * Read-through cache with in-flight dedupe and stale-on-error fallback.
 *
 * @param {string} key - cache key from `cacheKey`.
 * @param {number} ttlMs - freshness window in ms.
 * @param {Function} loader - `() => Promise<*>` that performs the network call.
 * @param {Function} [now] - clock injection for tests (default `Date.now`).
 * @return {Promise<{data: *, isStale: boolean}>}
 * @spec openspec/specs/openconnector-api-sources/spec.md#req-ocas-006
 */
export async function readThrough(key, ttlMs, loader, now = Date.now) {
	const entry = store.get(key)

	if (isFresh(entry, now())) {
		return { data: entry.data, isStale: false }
	}

	// In-flight dedupe: a concurrent caller already triggered the load.
	if (entry?.inflight) {
		const data = await entry.inflight
		return { data, isStale: false }
	}

	const request = { key, ttlMs, now }
	const inflight = startLoad(request, { entry, loader })
	try {
		return storeLoaded(request, await inflight)
	} catch (err) {
		return serveStale(request, err)
	}
}

/**
 * Test / teardown helper — drop all cached entries.
 *
 * @spec openspec/specs/openconnector-api-sources/spec.md#req-ocas-006
 */
export function clearConnectorCache() {
	store.clear()
}
