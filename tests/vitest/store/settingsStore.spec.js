/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The settings store: what `fetchSettings` and `saveSettings` send to the
 * settings endpoint, what they cache, and that a failure resolves to `null`
 * instead of throwing. Only `fetch` and the Nextcloud globals are mocked.
 */

import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/auth', () => ({ getRequestToken: () => 'tok-123' }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => `/index.php${path}` }))

import { useSettingsStore } from '../../../src/store/modules/settings.js'

const okResponse = (body) => ({ ok: true, json: async () => body })

describe('useSettingsStore', () => {
	let fetchMock
	let errorSpy

	beforeEach(() => {
		setActivePinia(createPinia())
		fetchMock = vi.fn()
		vi.stubGlobal('fetch', fetchMock)
		errorSpy = vi.spyOn(console, 'error').mockImplementation(() => {})
	})

	afterEach(() => {
		vi.unstubAllGlobals()
		errorSpy.mockRestore()
	})

	it('starts empty, not loading and not admin', () => {
		const store = useSettingsStore()
		expect(store.settings).toEqual({})
		expect(store.loading).toBe(false)
		expect(store.hasOpenRegisters).toBe(false)
		expect(store.getIsAdmin).toBe(false)
		expect(store.getSettings).toEqual({})
	})

	it('fetchSettings GETs the settings endpoint with the request token and caches the body', async () => {
		const body = { register: 'buildiq', openregisters: true, isAdmin: true }
		fetchMock.mockResolvedValue(okResponse(body))
		const store = useSettingsStore()

		const result = await store.fetchSettings()

		expect(fetchMock).toHaveBeenCalledTimes(1)
		expect(fetchMock).toHaveBeenCalledWith('/index.php/apps/buildiq/api/settings', {
			headers: { requesttoken: 'tok-123' },
		})
		expect(result).toEqual(body)
		expect(store.getSettings).toEqual(body)
		expect(store.hasOpenRegisters).toBe(true)
		expect(store.getIsAdmin).toBe(true)
		expect(store.loading).toBe(false)
	})

	it('fetchSettings coerces missing or falsy flags to false', async () => {
		fetchMock.mockResolvedValue(okResponse({ openregisters: 0 }))
		const store = useSettingsStore()

		await store.fetchSettings()

		expect(store.hasOpenRegisters).toBe(false)
		expect(store.isAdmin).toBe(false)
	})

	it('fetchSettings tolerates a null body', async () => {
		fetchMock.mockResolvedValue(okResponse(null))
		const store = useSettingsStore()

		const result = await store.fetchSettings()

		expect(result).toBeNull()
		expect(store.settings).toBeNull()
		expect(store.hasOpenRegisters).toBe(false)
		expect(store.isAdmin).toBe(false)
	})

	it('fetchSettings returns null and keeps the cache on a non-ok response', async () => {
		const json = vi.fn()
		fetchMock.mockResolvedValue({ ok: false, json })
		const store = useSettingsStore()
		store.settings = { kept: true }

		const result = await store.fetchSettings()

		expect(result).toBeNull()
		expect(json).not.toHaveBeenCalled()
		expect(store.settings).toEqual({ kept: true })
		expect(store.loading).toBe(false)
		expect(errorSpy).not.toHaveBeenCalled()
	})

	it('fetchSettings logs and returns null when the request rejects', async () => {
		const failure = new Error('offline')
		fetchMock.mockRejectedValue(failure)
		const store = useSettingsStore()

		const result = await store.fetchSettings()

		expect(result).toBeNull()
		expect(store.loading).toBe(false)
		expect(errorSpy).toHaveBeenCalledWith('Failed to fetch settings:', failure)
	})

	it('fetchSettings is loading while the request is in flight', async () => {
		let resolve
		fetchMock.mockReturnValue(new Promise((r) => { resolve = r }))
		const store = useSettingsStore()

		const pending = store.fetchSettings()
		expect(store.loading).toBe(true)

		resolve(okResponse({}))
		await pending
		expect(store.loading).toBe(false)
	})

	it('saveSettings POSTs the settings as JSON and caches what the server echoes', async () => {
		const settings = { register: 'r', registry_url: 'u', registry_register: 'rr' }
		const echoed = { ...settings, registry_token_set: true }
		fetchMock.mockResolvedValue(okResponse(echoed))
		const store = useSettingsStore()
		store.hasOpenRegisters = true

		const result = await store.saveSettings(settings)

		expect(fetchMock).toHaveBeenCalledWith('/index.php/apps/buildiq/api/settings', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				requesttoken: 'tok-123',
			},
			body: JSON.stringify(settings),
		})
		expect(result).toEqual(echoed)
		expect(store.settings).toEqual(echoed)
		// Saving does not re-derive the flags fetchSettings sets.
		expect(store.hasOpenRegisters).toBe(true)
		expect(store.loading).toBe(false)
	})

	it('saveSettings returns null and keeps the cache on a non-ok response', async () => {
		fetchMock.mockResolvedValue({ ok: false, json: vi.fn() })
		const store = useSettingsStore()
		store.settings = { before: 1 }

		const result = await store.saveSettings({ register: 'x' })

		expect(result).toBeNull()
		expect(store.settings).toEqual({ before: 1 })
		expect(store.loading).toBe(false)
	})

	it('saveSettings logs and returns null when the request rejects', async () => {
		const failure = new TypeError('network')
		fetchMock.mockRejectedValue(failure)
		const store = useSettingsStore()

		const result = await store.saveSettings({})

		expect(result).toBeNull()
		expect(store.loading).toBe(false)
		expect(errorSpy).toHaveBeenCalledWith('Failed to save settings:', failure)
	})
})
