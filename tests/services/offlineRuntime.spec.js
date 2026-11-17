/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What a running app does about offline when it boots
 * (pages-installable-offline-and-mobile T05): with a fake service-worker
 * container and a fake cache, through the library's own registration.
 */

import { registerOfflineWorker } from '@conduction/nextcloud-vue/src/offline/registerOfflineWorker.js'
import { describe, expect, it, vi } from 'vitest'
import { collectionFor, startOffline, takeAlongQuery, workerUrls } from '../../src/services/offlineRuntime.js'

const generateUrl = (path) => '/index.php' + path

const manifest = {
	offline: {
		takeAlong: [{ schema: 'inspection', filter: { assignee: '@me', planned: 'today' }, limit: 2, ttlHours: 12 }],
		forms: ['report'],
	},
}

/**
 * The boot facts and fakes, with overrides.
 *
 * @param {object} overrides - what this case changes.
 * @return {{args: object, container: object, stored: Array<object>, cleared: Array<string>}}
 */
function setup(overrides = {}) {
	const container = { register: vi.fn(async (url, options) => ({ url, options })) }
	const stored = []
	const cleared = []
	const args = {
		slug: 'toezicht',
		manifest,
		allowed: true,
		signedIn: true,
		register: 'toezicht',
		generateUrl,
		registerOfflineWorker: (options) => registerOfflineWorker({ ...options, container }),
		storePlanning: async (planning) => { stored.push(planning) },
		fetchObjects: vi.fn(async () => [{ id: 'a' }, { id: 'b' }, { id: 'c' }]),
		clearCollection: async (collection) => { cleared.push(collection) },
		...overrides,
	}
	return { args, container, stored, cleared }
}

describe('startOffline', () => {
	it('registers the worker under the app run scope only', async () => {
		const { args, container } = setup()
		const result = await startOffline(args)
		expect(result.registered).toBe(true)
		expect(container.register).toHaveBeenCalledWith(
			'/index.php/apps/buildiq/run/toezicht/offline-worker.js',
			{ scope: '/index.php/apps/buildiq/run/toezicht/' },
		)
	})

	it('stores each take-along with its filter, limit and expiry', async () => {
		const { args, stored } = setup()
		const result = await startOffline(args)
		expect(args.fetchObjects).toHaveBeenCalledWith({
			register: 'toezicht',
			schema: 'inspection',
			query: { assignee: '@me', planned: 'today', _limit: 2 },
		})
		expect(stored).toEqual([{
			register: 'toezicht',
			schema: 'inspection',
			items: [{ id: 'a' }, { id: 'b' }],
			collection: 'buildiq:toezicht',
			ttlMs: 12 * 60 * 60 * 1000,
		}])
		expect(result.stored).toBe(2)
	})

	it('does nothing while the instance does not allow offline', async () => {
		const { args, container, stored, cleared } = setup({ allowed: false })
		expect(await startOffline(args)).toEqual({ registered: false, stored: 0, cleared: false })
		expect(container.register).not.toHaveBeenCalled()
		expect(stored).toEqual([])
		expect(cleared).toEqual([])
	})

	it('does nothing for an app without an offline block', async () => {
		const { args, container } = setup({ manifest: { pages: [] } })
		expect((await startOffline(args)).registered).toBe(false)
		expect(container.register).not.toHaveBeenCalled()
	})

	it('clears the app records and registers nothing when it opens without a session', async () => {
		const { args, container, stored, cleared } = setup({ signedIn: false })
		expect(await startOffline(args)).toEqual({ registered: false, stored: 0, cleared: true })
		expect(cleared).toEqual(['buildiq:toezicht'])
		expect(container.register).not.toHaveBeenCalled()
		expect(stored).toEqual([])
	})

	it('keeps the other take-alongs when one cannot be read', async () => {
		const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
		const twoSchemas = { offline: { takeAlong: [{ schema: 'gone', limit: 5, ttlHours: 1 }, { schema: 'inspection', limit: 5, ttlHours: 1 }] } }
		const fetchObjects = vi.fn(async ({ schema }) => {
			if (schema === 'gone') {
				throw new Error('404')
			}
			return [{ id: 'x' }]
		})
		const { args, stored } = setup({ manifest: twoSchemas, fetchObjects })
		expect((await startOffline(args)).stored).toBe(1)
		expect(stored.map((s) => s.schema)).toEqual(['inspection'])
		warn.mockRestore()
	})

	it('counts a refused registration as not registered, and still takes records along', async () => {
		const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
		const container = { register: vi.fn(async () => { throw new Error('refused') }) }
		const { args, stored } = setup({ registerOfflineWorker: (options) => registerOfflineWorker({ ...options, container }) })
		const result = await startOffline(args)
		expect(result.registered).toBe(false)
		expect(stored).toHaveLength(1)
		warn.mockRestore()
	})
})

describe('helpers', () => {
	it('names one cache collection per app', () => {
		expect(collectionFor('toezicht')).toBe('buildiq:toezicht')
	})

	it('puts the worker and its scope under the run path', () => {
		expect(workerUrls('a b', generateUrl)).toEqual({
			scriptUrl: '/index.php/apps/buildiq/run/a%20b/offline-worker.js',
			scope: '/index.php/apps/buildiq/run/a%20b/',
		})
	})

	it('queries with the filter and the limit', () => {
		expect(takeAlongQuery({ filter: { a: 1 }, limit: 7 })).toEqual({ a: 1, _limit: 7 })
	})
})
