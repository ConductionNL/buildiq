/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for the edges of useConnectorDataSource: a binding without an
 * endpoint never calls out, the query and a slash-led endpoint reach the
 * call as intended, a fresh result is served from the cache, an expired one
 * is served stale when the endpoint then fails, and any rejection becomes
 * an Error.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))

import { useConnectorDataSource } from '../../src/composables/useConnectorDataSource.js'
import { clearConnectorCache } from '../../src/services/connectorCache.js'

function source(binding, client, appId = 'app1') {
	return useConnectorDataSource({ appId, binding, client })
}

describe('useConnectorDataSource edges', () => {
	beforeEach(() => clearConnectorCache())
	afterEach(() => vi.useRealTimers())

	it('refuses a binding without an endpoint path and never calls out', async () => {
		const client = { get: vi.fn() }
		const { load, error, loading, data } = source({ itemsPath: 'x' }, client)

		await load()

		expect(client.get).not.toHaveBeenCalled()
		expect(error.value).toBeInstanceOf(Error)
		expect(error.value.message).toBe('connector binding missing endpointPath')
		expect(loading.value).toBe(false)
		expect(data.value).toBeNull()
	})

	it('strips leading slashes from the endpoint and passes the query as params', async () => {
		const client = { get: vi.fn().mockResolvedValue({ data: [{ a: 1 }] }) }

		await source({ endpointPath: '//kvk/search', query: { q: 'acme', page: 2 }, fields: { a: 'a' } }, client).load()

		expect(client.get).toHaveBeenCalledWith('/apps/integriq/api/endpoint/kvk/search', { params: { q: 'acme', page: 2 } })
	})

	it('uses the response itself, as one item, when it carries no data member', async () => {
		const client = { get: vi.fn().mockResolvedValue({ naam: 'Acme' }) }
		const { load, data } = source({ endpointPath: 'kvk', fields: { name: 'naam' } }, client)

		await load()

		expect(data.value).toEqual([{ name: 'Acme' }])
	})

	it('serves a fresh result from the cache without calling out again', async () => {
		const client = { get: vi.fn().mockResolvedValue({ data: { n: 1 } }) }
		const binding = { endpointPath: 'kvk', fields: { n: 'n' }, cacheTtl: 60 }

		await source(binding, client).load()
		const second = source(binding, client)
		await second.load()

		expect(client.get).toHaveBeenCalledTimes(1)
		expect(second.data.value).toEqual([{ n: 1 }])
		expect(second.isStale.value).toBe(false)
	})

	it('keeps the cache per app and per query', async () => {
		const client = { get: vi.fn().mockResolvedValue({ data: [] }) }

		await source({ endpointPath: 'kvk', query: { q: 'a' } }, client, 'app1').load()
		await source({ endpointPath: 'kvk', query: { q: 'b' } }, client, 'app1').load()
		await source({ endpointPath: 'kvk', query: { q: 'a' } }, client, 'app2').load()

		expect(client.get).toHaveBeenCalledTimes(3)
	})

	it('serves an expired result as stale when the endpoint then fails', async () => {
		vi.useFakeTimers()
		vi.setSystemTime(new Date('2026-01-01T10:00:00Z'))
		const client = {
			get: vi.fn()
				.mockResolvedValueOnce({ data: { n: 1 } })
				.mockRejectedValueOnce(new Error('endpoint down')),
		}
		const binding = { endpointPath: 'kvk', fields: { n: 'n' }, cacheTtl: 1 }
		await source(binding, client).load()

		vi.setSystemTime(new Date('2026-01-01T10:00:05Z'))
		const later = source(binding, client)
		await later.load()

		expect(client.get).toHaveBeenCalledTimes(2)
		expect(later.data.value).toEqual([{ n: 1 }])
		expect(later.isStale.value).toBe(true)
		expect(later.error.value).toBeNull()
	})

	it('turns a rejection that is not an Error into one, and clears the data', async () => {
		const client = { get: vi.fn().mockRejectedValue('gateway timeout') }
		const { load, error, data, isStale, loading } = source({ endpointPath: 'kvk', cacheTtl: 0 }, client)

		await load()

		expect(error.value).toBeInstanceOf(Error)
		expect(error.value.message).toBe('gateway timeout')
		expect(data.value).toBeNull()
		expect(isStale.value).toBe(false)
		expect(loading.value).toBe(false)
	})
})
