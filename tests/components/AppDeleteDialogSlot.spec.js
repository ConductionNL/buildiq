/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for `src/components/AppDeleteDialogSlot.vue` — the
 * component that fills CnIndexPage's `#delete-dialog` slot on the
 * applications index and wires DeleteAppDialog's confirm to OpenBuild's
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

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

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

const AppDeleteDialogSlot = (await import('../../src/components/AppDeleteDialogSlot.vue')).default

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
		objects: { [APP_TYPE]: { 'u-app': { id: 'u-app' }, other: { id: 'other' } } },
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
			'/apps/openbuild/api/applications/u-app',
			{ params: { deleteData: 0 } },
		)
	})

	it('calls destroy with deleteData=1 only when the owner opts into a data purge', async () => {
		const wrapper = mountSlot()
		dialog(wrapper).vm.$emit('confirm', true)
		await flush()

		expect(axiosDeleteMock).toHaveBeenCalledWith(
			'/apps/openbuild/api/applications/u-app',
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
		const wrapper = mountSlot({ item: { '@self': { id: 'self-id' }, name: 'Demo' } })
		dialog(wrapper).vm.$emit('confirm', false)
		await flush()
		expect(axiosDeleteMock).toHaveBeenCalledWith(
			'/apps/openbuild/api/applications/self-id',
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

	it('closes via the dialog\'s update:open when no delete is in flight', async () => {
		const close = vi.fn()
		const wrapper = mountSlot({ close })
		dialog(wrapper).vm.$emit('update:open', false)
		await flush()
		expect(close).toHaveBeenCalledOnce()
	})
})
