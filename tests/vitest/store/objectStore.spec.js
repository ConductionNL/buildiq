/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The generic OpenRegister object store: endpoint configuration, type
 * registration, and what `fetchObjects` requests, caches and returns. Only
 * `fetch` and the Nextcloud auth global are mocked.
 */

import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/auth', () => ({ getRequestToken: () => 'tok-xyz' }))

import { useObjectStore } from '../../../src/store/modules/object.js'

const okResponse = (body) => ({ ok: true, json: async () => body })

describe('useObjectStore', () => {
	let fetchMock
	let errorSpy
	let warnSpy

	beforeEach(() => {
		setActivePinia(createPinia())
		fetchMock = vi.fn()
		vi.stubGlobal('fetch', fetchMock)
		errorSpy = vi.spyOn(console, 'error').mockImplementation(() => {})
		warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {})
	})

	afterEach(() => {
		vi.unstubAllGlobals()
		errorSpy.mockRestore()
		warnSpy.mockRestore()
	})

	const configured = () => {
		const store = useObjectStore()
		store.configure({
			baseUrl: '/index.php/apps/openregister/api/objects',
			schemaBaseUrl: '/index.php/apps/openregister/api/schemas',
		})
		store.registerObjectType('application', 'app-schema', 'app-register')
		return store
	}

	const requestedUrl = () => new URL(fetchMock.mock.calls[0][0])

	it('configure stores both API roots', () => {
		const store = useObjectStore()
		store.configure({ baseUrl: '/objects', schemaBaseUrl: '/schemas' })

		expect(store.baseUrl).toBe('/objects')
		expect(store.schemaBaseUrl).toBe('/schemas')
	})

	it('registerObjectType binds the schema and register and seeds an empty bucket', () => {
		const store = useObjectStore()
		store.registerObjectType('page', 's', 'r')

		expect(store.objectTypes.page).toEqual({ schema: 's', register: 'r' })
		expect(store.objects.page).toEqual([])
	})

	it('re-registering a type overwrites the binding but keeps cached objects', () => {
		const store = useObjectStore()
		store.registerObjectType('page', 's1', 'r1')
		store.objects.page = [{ id: 1 }]

		store.registerObjectType('page', 's2', 'r2')

		expect(store.objectTypes.page).toEqual({ schema: 's2', register: 'r2' })
		expect(store.objects.page).toEqual([{ id: 1 }])
	})

	it('fetchObjects warns and returns [] for an unregistered type without a request', async () => {
		const store = useObjectStore()

		const result = await store.fetchObjects('missing')

		expect(result).toEqual([])
		expect(fetchMock).not.toHaveBeenCalled()
		expect(warnSpy).toHaveBeenCalledWith('Object type "missing" is not registered')
		expect(store.loading.missing).toBeUndefined()
	})

	it('fetchObjects requests register, schema and extra params with the request token', async () => {
		fetchMock.mockResolvedValue(okResponse({ results: [] }))
		const store = configured()

		await store.fetchObjects('application', { _limit: 8, _page: 2 })

		expect(fetchMock).toHaveBeenCalledTimes(1)
		expect(fetchMock.mock.calls[0][1]).toEqual({ headers: { requesttoken: 'tok-xyz' } })
		const url = requestedUrl()
		expect(url.origin).toBe(window.location.origin)
		expect(url.pathname).toBe('/index.php/apps/openregister/api/objects')
		expect(url.searchParams.get('register')).toBe('app-register')
		expect(url.searchParams.get('schema')).toBe('app-schema')
		expect(url.searchParams.get('_limit')).toBe('8')
		expect(url.searchParams.get('_page')).toBe('2')
	})

	it('an extra param named register or schema overrides the binding', async () => {
		fetchMock.mockResolvedValue(okResponse({ results: [] }))
		const store = configured()

		await store.fetchObjects('application', { schema: 'other' })

		expect(requestedUrl().searchParams.get('schema')).toBe('other')
	})

	it('fetchObjects unwraps the results envelope and caches it', async () => {
		const results = [{ id: 'a' }, { id: 'b' }]
		fetchMock.mockResolvedValue(okResponse({ results, total: 2, page: 1, pages: 1 }))
		const store = configured()

		const returned = await store.fetchObjects('application')

		expect(returned).toEqual(results)
		expect(store.objects.application).toEqual(results)
		expect(store.loading.application).toBe(false)
	})

	it('fetchObjects caches a bare array body as-is', async () => {
		const bare = [{ id: 'x' }]
		fetchMock.mockResolvedValue(okResponse(bare))
		const store = configured()

		const returned = await store.fetchObjects('application')

		expect(returned).toEqual(bare)
		expect(store.objects.application).toEqual(bare)
	})

	it('fetchObjects returns [] and keeps the cache on a non-ok response', async () => {
		fetchMock.mockResolvedValue({ ok: false, json: vi.fn() })
		const store = configured()
		store.objects.application = [{ id: 'cached' }]

		const returned = await store.fetchObjects('application')

		expect(returned).toEqual([])
		expect(store.objects.application).toEqual([{ id: 'cached' }])
		expect(store.loading.application).toBe(false)
		expect(errorSpy).not.toHaveBeenCalled()
	})

	it('fetchObjects logs and returns [] when the request rejects', async () => {
		const failure = new Error('down')
		fetchMock.mockRejectedValue(failure)
		const store = configured()
		store.objects.application = [{ id: 'cached' }]

		const returned = await store.fetchObjects('application')

		expect(returned).toEqual([])
		expect(store.objects.application).toEqual([{ id: 'cached' }])
		expect(store.loading.application).toBe(false)
		expect(errorSpy).toHaveBeenCalledWith('Failed to fetch application objects:', failure)
	})

	it('fetchObjects marks the type loading while the request is in flight', async () => {
		let resolve
		fetchMock.mockReturnValue(new Promise((r) => { resolve = r }))
		const store = configured()

		const pending = store.fetchObjects('application')
		expect(store.loading.application).toBe(true)

		resolve(okResponse({ results: [] }))
		await pending
		expect(store.loading.application).toBe(false)
	})
})
