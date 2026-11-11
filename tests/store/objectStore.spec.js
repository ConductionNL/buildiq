/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Unit coverage for the generic OpenRegister object store's `fetchObjects`:
 * the request it sends, what it caches and returns, and how it fails.
 */

import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/auth', () => ({ getRequestToken: () => 'tok' }))

const { useObjectStore } = await import('../../src/store/modules/object.js')

/**
 * A configured store with one registered type.
 *
 * @return {object}
 */
function store() {
	const s = useObjectStore()
	s.configure({ baseUrl: '/index.php/apps/openregister/api/objects', schemaBaseUrl: '' })
	s.registerObjectType('application', 'app-schema', 'buildiq')
	return s
}

describe('object store fetchObjects', () => {
	let originalFetch
	beforeEach(() => {
		setActivePinia(createPinia())
		originalFetch = global.fetch
	})
	afterEach(() => {
		global.fetch = originalFetch
		vi.restoreAllMocks()
	})

	it('requests the register, schema and params, caches the results and returns them', async () => {
		const results = [{ id: 1 }]
		global.fetch = vi.fn(async () => ({ ok: true, json: async () => ({ results, total: 1 }) }))
		const s = store()
		const pending = s.fetchObjects('application', { _limit: 8, q: 'a b' })
		expect(s.loading.application).toBe(true)
		const out = await pending
		expect(global.fetch).toHaveBeenCalledWith(
			`${window.location.origin}/index.php/apps/openregister/api/objects?register=buildiq&schema=app-schema&_limit=8&q=a+b`,
			{ headers: { requesttoken: 'tok' } },
		)
		expect(out).toEqual(results)
		expect(s.objects.application).toEqual(results)
		expect(s.loading.application).toBe(false)
	})

	it('caches a bare list answer as it is', async () => {
		global.fetch = vi.fn(async () => ({ ok: true, json: async () => [{ id: 2 }] }))
		const s = store()
		expect(await s.fetchObjects('application')).toEqual([{ id: 2 }])
	})

	it('answers [] and keeps the cache on a non-ok answer or a failure', async () => {
		const error = vi.spyOn(console, 'error').mockImplementation(() => {})
		const s = store()
		s.objects.application = [{ id: 'old' }]
		global.fetch = vi.fn(async () => ({ ok: false, json: async () => ({}) }))
		expect(await s.fetchObjects('application')).toEqual([])
		expect(error).not.toHaveBeenCalled()
		global.fetch = vi.fn(async () => {
			throw new Error('down')
		})
		expect(await s.fetchObjects('application')).toEqual([])
		expect(error).toHaveBeenCalledWith('Failed to fetch application objects:', expect.any(Error))
		global.fetch = vi.fn(async () => ({ ok: true, json: async () => null }))
		expect(await s.fetchObjects('application')).toEqual([])
		expect(s.objects.application).toEqual([{ id: 'old' }])
		expect(s.loading.application).toBe(false)
	})

	it('warns and answers [] for an unregistered type without a request', async () => {
		const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
		global.fetch = vi.fn()
		expect(await store().fetchObjects('nope')).toEqual([])
		expect(warn).toHaveBeenCalledWith('Object type "nope" is not registered')
		expect(global.fetch).not.toHaveBeenCalled()
	})
})
