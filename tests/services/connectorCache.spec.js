/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for the connector response cache (TTL, dedupe, stale-on-error).
 *
 * Spec: openconnector-api-sources (REQ-OCAS-006).
 */
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { cacheKey, stableQueryHash, ttlToMs, readThrough, clearConnectorCache } from '../../src/services/connectorCache.js'

describe('connectorCache helpers', () => {
	it('hashes queries order-independently', () => {
		expect(stableQueryHash({ a: 1, b: 2 })).toBe(stableQueryHash({ b: 2, a: 1 }))
	})
	it('builds a namespaced key', () => {
		expect(cacheKey('app1', 'kvk/x', { city: 'U' })).toBe('app1::kvk/x::city=U')
	})
	it('clamps cacheTtl to 0–3600 s', () => {
		expect(ttlToMs(60)).toBe(60000)
		expect(ttlToMs(99999)).toBe(3600000)
		expect(ttlToMs(-5)).toBe(0)
		expect(ttlToMs(undefined)).toBe(60000)
	})
})

describe('readThrough', () => {
	beforeEach(() => clearConnectorCache())

	it('dedupes concurrent loads into one request', async () => {
		const loader = vi.fn().mockResolvedValue({ ok: 1 })
		const [a, b, c] = await Promise.all([
			readThrough('k', 1000, loader),
			readThrough('k', 1000, loader),
			readThrough('k', 1000, loader),
		])
		expect(loader).toHaveBeenCalledTimes(1)
		expect(a.data).toEqual({ ok: 1 })
		expect(b.isStale).toBe(false)
		expect(c.isStale).toBe(false)
	})

	it('serves a fresh entry without refetching, refetches after TTL', async () => {
		let clock = 1000
		const now = () => clock
		const loader = vi.fn().mockResolvedValue('v1')
		await readThrough('k', 1000, loader, now)
		clock = 1500 // within TTL
		await readThrough('k', 1000, loader, now)
		expect(loader).toHaveBeenCalledTimes(1)
		clock = 3000 // past TTL
		loader.mockResolvedValue('v2')
		const r = await readThrough('k', 1000, loader, now)
		expect(loader).toHaveBeenCalledTimes(2)
		expect(r.data).toBe('v2')
	})

	it('serves stale on refresh error within 10x TTL', async () => {
		let clock = 1000
		const now = () => clock
		const loader = vi.fn().mockResolvedValueOnce('good')
		await readThrough('k', 1000, loader, now)
		clock = 5000 // past TTL but within 10x
		loader.mockRejectedValueOnce(new Error('boom'))
		const r = await readThrough('k', 1000, loader, now)
		expect(r.isStale).toBe(true)
		expect(r.data).toBe('good')
	})

	it('throws when refresh fails and no cache entry exists', async () => {
		const loader = vi.fn().mockRejectedValue(new Error('nope'))
		await expect(readThrough('k', 1000, loader)).rejects.toThrow('nope')
	})
})
