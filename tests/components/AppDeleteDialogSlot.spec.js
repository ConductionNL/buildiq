/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for `src/components/AppDeleteDialogSlot.vue` — the
 * component that fills CnIndexPage's `#delete-dialog` slot on the
 * applications index and wires DeleteAppDialog's confirm to Buildiq's
 * `destroy` endpoint.
 *
 * The slot maps the dialog's boolean `deleteData` choice onto the API's
 * `deleteData` query param (1 / 0) and, on success, evicts the row from the
 * shared object store so the self-fetch table updates. Because this is the
 * most destructive path in the app, these tests assert:
 *   - the default (box unticked) path calls destroy with deleteData=0
 *   - the opt-in (box ticked) path calls destroy with deleteData=1
 *   - a successful delete evicts the app from the store cache and closes
 *   - a missing app id short-circuits (no API call)
 *   - an API failure surfaces an error and does NOT close the dialog
 */

import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const axiosDeleteMock = vi.fn()
vi.mock('@nextcloud/axios', () => ({
	default: { delete: (...args) => axiosDeleteMock(...args) },
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (p) => p,
}))

const showErrorMock = vi.fn()
vi.mock('@nextcloud/dialogs', () => ({
	showError: (...args) => showErrorMock(...args),
}))

// The shared object store the slot evicts from after a successful delete.
// A fresh instance per test keeps the eviction assertions independent.
let store
vi.mock('@conduction/nextcloud-vue', () => ({
	useObjectStore: () => store,
}))

const AppDeleteDialogSlot = (
	await import('../../src/components/AppDeleteDialogSlot.vue')
).default

// Stub the child dialog: these tests exercise the slot's endpoint wiring, not
// the dialog's rendered markup (covered in DeleteAppDialog.spec.js).
const DeleteAppDialogStub = {
	name: 'DeleteAppDialog',
	props: ['open', 'appName', 'busy'],
	template: '<div class="delete-app-dialog-stub" />',
}

const APP_TYPE = 'openbuild-application'

function freshStore() {
	return {
		collections: { [APP_TYPE]: [{ id: 'u-app' }, { id: 'other' }] },
		objects: {
			[APP_TYPE]: { 'u-app': { id: 'u-app' }, other: { id: 'other' } },
		},
	}
}

/** Drain the async onConfirm handler (awaits axios + reactive updates). */
async function flush() {
	await new Promise((resolve) => setTimeout(resolve))
	await new Promise((resolve) => setTimeout(resolve))
}

function mountSlot(propsData = {}) {
	return mount(AppDeleteDialogSlot, {
		propsData: {
			item: { id: 'u-app', name: 'Demo App' },
			close: vi.fn(),
			...propsData,
		},
		stubs: { DeleteAppDialog: DeleteAppDialogStub },
	})
}

function dialog(wrapper) {
	return wrapper.findComponent({ name: 'DeleteAppDialog' })
}

describe('AppDeleteDialogSlot — destroy-endpoint wiring', () => {
	beforeEach(() => {
		axiosDeleteMock.mockReset()
		axiosDeleteMock.mockResolvedValue({ data: {} })
		showErrorMock.mockReset()
		store = freshStore()
	})

	it('passes the targeted app name and closed/open state down to the dialog', () => {
		const wrapper = mountSlot({ item: { slug: 'store-app' } })
		expect(dialog(wrapper).props('appName')).toBe('store-app')
		expect(dialog(wrapper).props('open')).toBe(true)

		const closed = mountSlot({ item: null })
		expect(dialog(closed).props('open')).toBe(false)
	})

	it('calls destroy with deleteData=0 on the default (data-preserving) path', async () => {
		const wrapper = mountSlot()
		dialog(wrapper).vm.$emit('confirm', false)
		await flush()

		expect(axiosDeleteMock).toHaveBeenCalledWith(
			'/apps/buildiq/api/applications/u-app',
			{ params: { deleteData: 0 } },
		)
	})

	it('calls destroy with deleteData=1 only when the owner opts into a data purge', async () => {
		const wrapper = mountSlot()
		dialog(wrapper).vm.$emit('confirm', true)
		await flush()

		expect(axiosDeleteMock).toHaveBeenCalledWith(
			'/apps/buildiq/api/applications/u-app',
			{ params: { deleteData: 1 } },
		)
	})

	it('evicts the deleted app from the store cache and closes on success', async () => {
		const close = vi.fn()
		const wrapper = mountSlot({ close })
		dialog(wrapper).vm.$emit('confirm', false)
		await flush()

		expect(store.collections[APP_TYPE].map((o) => o.id)).toEqual(['other'])
		expect(store.objects[APP_TYPE]).not.toHaveProperty('u-app')
		expect(close).toHaveBeenCalledOnce()
	})

	it('short-circuits with no API call when the app has no resolvable id', async () => {
		const wrapper = mountSlot({ item: { name: 'No Id App' } })
		dialog(wrapper).vm.$emit('confirm', true)
		await flush()
		expect(axiosDeleteMock).not.toHaveBeenCalled()
	})

	it('resolves the app id from the @self envelope when no top-level id is present', async () => {
		const wrapper = mountSlot({
			item: { '@self': { id: 'self-id' }, name: 'Demo' },
		})
		dialog(wrapper).vm.$emit('confirm', false)
		await flush()
		expect(axiosDeleteMock).toHaveBeenCalledWith(
			'/apps/buildiq/api/applications/self-id',
			{ params: { deleteData: 0 } },
		)
	})

	it('surfaces an error and keeps the dialog open when destroy fails', async () => {
		axiosDeleteMock.mockRejectedValue({ response: { data: { detail: 'boom' } } })
		const close = vi.fn()
		const wrapper = mountSlot({ close })
		dialog(wrapper).vm.$emit('confirm', false)
		await flush()

		expect(showErrorMock).toHaveBeenCalledOnce()
		expect(close).not.toHaveBeenCalled()
		expect(wrapper.vm.busy).toBe(false)
	})

	it("closes via the dialog's update:open when no delete is in flight", async () => {
		const close = vi.fn()
		const wrapper = mountSlot({ close })
		dialog(wrapper).vm.$emit('update:open', false)
		await flush()
		expect(close).toHaveBeenCalledOnce()
	})
	describe('guard, id and failure branches', () => {
		it('does not start a second delete while one is in flight', async () => {
			const wrapper = mountSlot()
			wrapper.vm.busy = true
			await wrapper.vm.onConfirm(false)
			expect(axiosDeleteMock).not.toHaveBeenCalled()
		})

		it.each([
			[{ id: 'a', '@self': { id: 'b' }, uuid: 'c' }, 'a'],
			[{ '@self': { id: 'b' }, uuid: 'c' }, 'b'],
			[{ '@self': {}, uuid: 'c' }, 'c'],
			[{ id: 7 }, '7'],
			[{}, ''],
		])('resolves the id of %j as %j', (item, uuid) => {
			expect(mountSlot({ item }).vm.appUuid).toBe(uuid)
		})

		it('has no id without an item', () => {
			expect(mountSlot({ item: null }).vm.appUuid).toBe('')
		})

		it('deletes without a close callback', async () => {
			const wrapper = mountSlot({ close: null })
			await wrapper.vm.onConfirm(true)
			expect(axiosDeleteMock).toHaveBeenCalledWith(
				'/apps/buildiq/api/applications/u-app',
				{ params: { deleteData: 1 } },
			)
			expect(store.collections[APP_TYPE]).toEqual([{ id: 'other' }])
		})

		it.each([
			[{ response: { data: { detail: 'D' } }, message: 'M' }, 'D'],
			[{ response: {}, message: 'M' }, 'M'],
			['raw', 'raw'],
		])('reports the failure %j with %j', async (err, detail) => {
			axiosDeleteMock.mockRejectedValueOnce(err)
			const wrapper = mount(AppDeleteDialogSlot, {
				propsData: { item: { id: 'u-app' }, close: vi.fn() },
				stubs: { DeleteAppDialog: DeleteAppDialogStub },
				mocks: {
					t: (app, key, vars) => key.replace('{error}', String(vars.error)),
				},
			})
			await wrapper.vm.onConfirm(false)
			expect(showErrorMock).toHaveBeenCalledWith(`Delete failed: ${detail}`)
		})

		it('evicts only from the caches that hold the type', () => {
			store = { collections: {}, objects: null }
			const wrapper = mountSlot()
			wrapper.vm.evictFromList('u-app')
			expect(store).toEqual({ collections: {}, objects: null })
		})

		it('evicts from the objects map when there is no collection', () => {
			store = { objects: { [APP_TYPE]: { 'u-app': {}, other: {} } } }
			const wrapper = mountSlot()
			wrapper.vm.evictFromList('u-app')
			expect(store.objects[APP_TYPE]).toEqual({ other: {} })
			expect(store.collections).toBeUndefined()
		})

		it('warns instead of failing when the store is unavailable', () => {
			store = null
			const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
			const wrapper = mountSlot()
			expect(() => wrapper.vm.evictFromList('u-app')).not.toThrow()
			expect(warn).toHaveBeenCalledWith(
				'[buildiq] could not evict deleted app from list cache',
				expect.any(TypeError),
			)
			warn.mockRestore()
		})
	})
})
