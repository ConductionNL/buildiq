/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Vitest unit tests for `src/views/VersionHistory.vue` — the version-history
 * sibling panel rendering ApplicationVersion rows + the rollback flow.
 *
 * Covers four scenarios called out in REQ-OBR-008 (version-history panel)
 * and REQ-OBR-009 (rollback action):
 *   - lists rows fetched from OR REST (newest first, applicationUuid filter)
 *   - clicking "Roll back" opens the RollbackConfirmModal seeded with the row
 *   - Cancel inside the modal closes it and emits nothing
 *   - Confirm emits `rollback` with the chosen version blob
 *
 * The RollbackConfirmModal is rendered as a stub (declared inline via
 * vi.mock) so we can probe its open prop + buttons without dragging in
 * @nextcloud/vue.
 */

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

// Mock axios so the component never hits the network. The fn is allocated
// inside vi.hoisted so it's available when vi.mock's factory runs (vi.mock
// is hoisted above the import statements by vitest).
const { axiosGetMock } = vi.hoisted(() => ({ axiosGetMock: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({
	default: { get: axiosGetMock },
}))

vi.mock('@nextcloud/router', () => ({
	generateUrl: (path) => path,
}))

// RollbackConfirmModal — replace with a transparent stub so we can read
// :open and click the inner buttons by class. The stub re-emits the parent
// contract verbatim.
vi.mock('../../../src/modals/RollbackConfirmModal.vue', () => ({
	default: {
		name: 'RollbackConfirmModal',
		props: ['open', 'version'],
		emits: ['confirm', 'cancel', 'update:open'],
		render(h) {
			return h(
				'div',
				{ class: 'rollback-confirm-modal-stub', attrs: { 'data-open': this.open ? 'true' : 'false' } },
				[
					h(
						'button',
						{
							class: 'rollback-confirm-modal-stub__confirm',
							on: { click: () => this.$emit('confirm', this.version) },
						},
						'Confirm',
					),
					h(
						'button',
						{
							class: 'rollback-confirm-modal-stub__cancel',
							on: { click: () => this.$emit('cancel') },
						},
						'Cancel',
					),
				],
			)
		},
	},
}))

import VersionHistory from '../../../src/views/VersionHistory.vue'

/**
 * Wait one micro-tick + Vue render cycle so the async fetch resolves.
 *
 * @param wrapper The vue-test-utils mount wrapper.
 * @return Promise<void>
 */
async function flushFetch(wrapper) {
	await Promise.resolve()
	await wrapper.vm.$nextTick()
	await Promise.resolve()
	await wrapper.vm.$nextTick()
}

describe('VersionHistory — REQ-OBR-008 / REQ-OBR-009', () => {
	const APP_UUID = 'app-uuid-1'

	beforeEach(() => {
		axiosGetMock.mockReset()
	})

	it('lists versions newest-first and renders one row per ApplicationVersion', async () => {
		axiosGetMock.mockResolvedValueOnce({
			data: {
				results: [
					{
						'@self': { id: 'snap-old' },
						applicationUuid: APP_UUID,
						version: '1.0.0',
						publishedAt: '2026-05-01T10:00:00Z',
						publishedBy: 'alice',
						manifest: { v: 1 },
					},
					{
						'@self': { id: 'snap-new' },
						applicationUuid: APP_UUID,
						version: '1.1.0',
						publishedAt: '2026-05-05T10:00:00Z',
						publishedBy: 'bob',
						manifest: { v: 2 },
					},
					// A snapshot for a DIFFERENT application — must be filtered
					// out client-side per the IDOR defence-in-depth.
					{
						'@self': { id: 'snap-foreign' },
						applicationUuid: 'app-uuid-2',
						version: '9.9.9',
						publishedAt: '2026-06-01T10:00:00Z',
						manifest: { leaked: true },
					},
				],
			},
		})

		const wrapper = mount(VersionHistory, {
			propsData: { applicationUuid: APP_UUID, currentVersionUuid: 'snap-new' },
		})
		await flushFetch(wrapper)

		const rows = wrapper.findAll('.version-history__row')
		expect(rows.length).toBe(2)
		// Newest first.
		expect(rows.at(0).text()).toContain('1.1.0')
		expect(rows.at(1).text()).toContain('1.0.0')
		// Foreign snapshot must not appear.
		expect(wrapper.text()).not.toContain('9.9.9')
		expect(wrapper.text()).not.toContain('leaked')
	})

	it('clicking Roll back opens the RollbackConfirmModal seeded with the row', async () => {
		axiosGetMock.mockResolvedValueOnce({
			data: {
				results: [
					{
						'@self': { id: 'snap-1' },
						applicationUuid: APP_UUID,
						version: '1.0.0',
						publishedAt: '2026-05-01T10:00:00Z',
						publishedBy: 'alice',
						manifest: { v: 1 },
					},
				],
			},
		})

		const wrapper = mount(VersionHistory, {
			propsData: { applicationUuid: APP_UUID },
		})
		await flushFetch(wrapper)

		// Initially closed.
		const modal = wrapper.find('.rollback-confirm-modal-stub')
		expect(modal.exists()).toBe(true)
		expect(modal.attributes('data-open')).toBe('false')

		// Find the danger button (Rollback) by class.
		const rollbackBtn = wrapper.find('.version-history__btn--danger')
		expect(rollbackBtn.exists()).toBe(true)
		await rollbackBtn.trigger('click')
		await wrapper.vm.$nextTick()

		expect(wrapper.find('.rollback-confirm-modal-stub').attributes('data-open')).toBe('true')
		// rollbackTarget is seeded with the row's blob.
		expect(wrapper.vm.rollbackTarget).toMatchObject({
			uuid: 'snap-1',
			version: '1.0.0',
			manifest: { v: 1 },
		})
	})

	it("Cancel inside the modal dismisses it and emits no 'rollback'", async () => {
		axiosGetMock.mockResolvedValueOnce({
			data: {
				results: [
					{
						'@self': { id: 'snap-1' },
						applicationUuid: APP_UUID,
						version: '1.0.0',
						publishedAt: '2026-05-01T10:00:00Z',
						manifest: { v: 1 },
					},
				],
			},
		})

		const wrapper = mount(VersionHistory, {
			propsData: { applicationUuid: APP_UUID },
		})
		await flushFetch(wrapper)

		await wrapper.find('.version-history__btn--danger').trigger('click')
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.rollback-confirm-modal-stub').attributes('data-open')).toBe('true')

		await wrapper.find('.rollback-confirm-modal-stub__cancel').trigger('click')
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.rollback-confirm-modal-stub').attributes('data-open')).toBe('false')
		// No `rollback` event emitted on cancel.
		expect(wrapper.emitted('rollback')).toBeFalsy()
		// Target cleared so the next open isn't pre-seeded with the prior pick.
		expect(wrapper.vm.rollbackTarget).toBeNull()
	})

	it("Confirm emits 'rollback' with the chosen version blob (PUT-rollback contract)", async () => {
		axiosGetMock.mockResolvedValueOnce({
			data: {
				results: [
					{
						'@self': { id: 'snap-1' },
						applicationUuid: APP_UUID,
						version: '1.0.0',
						publishedAt: '2026-05-01T10:00:00Z',
						manifest: { v: 1, pages: [] },
					},
				],
			},
		})

		const wrapper = mount(VersionHistory, {
			propsData: { applicationUuid: APP_UUID },
		})
		await flushFetch(wrapper)

		await wrapper.find('.version-history__btn--danger').trigger('click')
		await wrapper.vm.$nextTick()

		await wrapper.find('.rollback-confirm-modal-stub__confirm').trigger('click')
		await wrapper.vm.$nextTick()

		// `rollback` emitted with the seeded target — the parent component is
		// responsible for issuing the PUT-to-rollback-endpoint side-effect.
		expect(wrapper.emitted('rollback')).toBeTruthy()
		const payload = wrapper.emitted('rollback')[0][0]
		expect(payload).toMatchObject({
			uuid: 'snap-1',
			version: '1.0.0',
			manifest: { v: 1, pages: [] },
		})
		// Modal closed after confirm.
		expect(wrapper.find('.rollback-confirm-modal-stub').attributes('data-open')).toBe('false')
	})
})
