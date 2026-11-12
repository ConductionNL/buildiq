/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * `initializeStores` wires the object store to OpenRegister's objects and
 * schemas endpoints, loads the settings once, and hands both stores back;
 * a settings load that fails reaches the caller.
 *
 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-26-frontend-foundation/tasks.md#task-3
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const stores = vi.hoisted(() => ({ object: null, settings: null }))

vi.mock('@nextcloud/router', () => ({
	generateUrl: (path) => '/index.php' + path,
}))
vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => stores.object,
}))
vi.mock('../../src/store/modules/settings.js', () => ({
	useSettingsStore: () => stores.settings,
}))

const { initializeStores, useObjectStore, useSettingsStore } = await import('../../src/store/store.js')

beforeEach(() => {
	stores.object = { configure: vi.fn() }
	stores.settings = { fetchSettings: vi.fn(async () => undefined) }
})

describe('initializeStores', () => {
	it('points the object store at OpenRegister and loads the settings', async () => {
		const result = await initializeStores()

		expect(stores.object.configure).toHaveBeenCalledTimes(1)
		expect(stores.object.configure).toHaveBeenCalledWith({
			baseUrl: '/index.php/apps/openregister/api/objects',
			schemaBaseUrl: '/index.php/apps/openregister/api/schemas',
		})
		expect(stores.settings.fetchSettings).toHaveBeenCalledTimes(1)
		expect(result).toEqual({ settingsStore: stores.settings, objectStore: stores.object })
		expect(result.settingsStore).toBe(stores.settings)
		expect(result.objectStore).toBe(stores.object)
	})

	it('configures the object store before the settings load finishes', async () => {
		const order = []
		stores.object.configure = vi.fn(() => order.push('configure'))
		stores.settings.fetchSettings = vi.fn(async () => {
			order.push('fetchSettings')
		})

		await initializeStores()

		expect(order).toEqual(['configure', 'fetchSettings'])
	})

	it('lets a failed settings load reach the caller', async () => {
		stores.settings.fetchSettings = vi.fn(async () => {
			throw new Error('settings unavailable')
		})

		await expect(initializeStores()).rejects.toThrow('settings unavailable')
		expect(stores.object.configure).toHaveBeenCalledTimes(1)
	})

	it('re-exports the store factories', () => {
		expect(useObjectStore()).toBe(stores.object)
		expect(useSettingsStore()).toBe(stores.settings)
	})
})
