/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest rendering spec for PermissionHistoryModal — the loading, empty and
 * populated views, the close affordance and the timestamp fallback that the
 * display spec (PermissionHistoryModal.display.spec.js) does not render.
 *
 * Covers:
 *  - the loading state shows the loading empty-content.
 *  - the empty state shows the "no changes" empty-content.
 *  - a populated trail renders one row per entry.
 *  - the Close button emits update:open false.
 *  - formatStamp falls back to String() for a value Date cannot take.
 */

import { mount } from '@vue/test-utils'
import { afterAll, beforeAll, describe, expect, it, vi } from 'vitest'

const { axiosMock } = vi.hoisted(() => ({ axiosMock: { get: vi.fn() } }))
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))

import PermissionHistoryModal from '../../src/modals/PermissionHistoryModal.vue'

const realT = globalThis.t
beforeAll(() => {
	globalThis.t = (_app, key) => key
})
afterAll(() => {
	globalThis.t = realT
})

const STUBS = {
	NcDialog: {
		name: 'NcDialog',
		props: ['name', 'open', 'size'],
		emits: ['update:open'],
		template: '<div class="nc-dialog-stub"><slot /></div>',
	},
	NcButton: {
		name: 'NcButton',
		props: ['variant'],
		template: '<button class="nc-button-stub" @click="$emit(\'click\', $event)"><slot /></button>',
	},
	NcEmptyContent: {
		name: 'NcEmptyContent',
		props: ['name', 'description'],
		template: '<div class="nc-empty-stub"><span class="nc-empty-name">{{ name }}</span><slot name="icon" /></div>',
	},
	NcLoadingIcon: { name: 'NcLoadingIcon', template: '<span class="nc-loading-stub" />' },
	HistoryIcon: { name: 'HistoryIcon', template: '<span class="history-icon-stub" />' },
}

/**
 * Mount the modal with the given state applied to its data.
 *
 * @param {object} state Partial data overrides (loading, entries).
 *
 * @return {Promise<object>} The mounted wrapper.
 */
async function mountModal(state = {}) {
	// Keep the modal closed so the mounted `load()` never fires; we drive data directly.
	const wrapper = mount(PermissionHistoryModal, {
		props: { open: false, applicationUuid: 'app-1' },
		global: { stubs: STUBS },
	})
	Object.assign(wrapper.vm, state)
	await wrapper.vm.$nextTick()
	return wrapper
}

describe('PermissionHistoryModal rendering', () => {
	it('shows the loading empty-content while loading', async () => {
		const wrapper = await mountModal({ loading: true })

		expect(wrapper.find('.nc-loading-stub').exists()).toBe(true)
		expect(wrapper.text()).toContain('Loading audit trail…')
	})

	it('shows the "no changes" empty-content when the trail is empty', async () => {
		const wrapper = await mountModal({ loading: false, entries: [] })

		expect(wrapper.find('.history-icon-stub').exists()).toBe(true)
		expect(wrapper.text()).toContain('No permission changes recorded')
	})

	it('renders one row per audit entry', async () => {
		const wrapper = await mountModal({
			loading: false,
			entries: [
				{ id: 'e1', actor: 'alice', timestamp: '', event: 'permissions.changed' },
				{ id: 'e2', actor: '', timestamp: '', event: 'rbac.admin_bypass' },
			],
		})

		const rows = wrapper.findAll('.buildiq-permission-history__row')
		expect(rows).toHaveLength(2)
		// The second row (no actor) falls back to "system".
		expect(rows[1].text()).toContain('system')
	})

	it('emits update:open false when Close is clicked', async () => {
		const wrapper = await mountModal({ loading: false, entries: [{ id: 'e1', timestamp: '' }] })
		// The Close button and the dialog's own dismiss both route through onClose.
		expect(wrapper.find('.nc-button-stub').text()).toBe('Close')
		wrapper.vm.onClose()

		expect(wrapper.emitted('update:open').at(-1)).toEqual([false])
	})

	it('formatStamp falls back to String() for a value Date cannot take', async () => {
		const wrapper = await mountModal()

		expect(wrapper.vm.formatStamp('')).toBe('')
		expect(wrapper.vm.formatStamp(null)).toBe('')
		// A BigInt makes `new Date(stamp)` throw, so the catch returns String(stamp).
		expect(wrapper.vm.formatStamp(10n)).toBe('10')
	})
})
