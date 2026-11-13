/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest coverage of `ProcestCaseStatusPanel`'s render states (loading,
 * no access, error with retry, unlinked with start, linked case), its
 * mount-time detail load, the status-history labels and the deep link.
 */
import { shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'

const { state } = vi.hoisted(() => ({ state: { current: null } }))

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (path) => `/index.php${path}`,
}))

vi.mock('../../../src/composables/useProcestCase.js', () => ({
	useProcestCase: () => state.current,
}))

import ProcestCaseStatusPanel from '../../../src/components/runtime/ProcestCaseStatusPanel.vue'

const UUID = '0b6d1c2e-3f4a-4b5c-8d7e-9f0a1b2c3d4e'

/**
 * Build a fresh fake `useProcestCase` result.
 *
 * @param {object} overrides Initial ref values.
 * @return {object}
 */
function fakeProcest(overrides = {}) {
	const values = {
		loadingDetail: false,
		detailError: '',
		noAccess: false,
		starting: false,
		startError: '',
		statusHistory: [],
		caseDetail: null,
		...overrides,
	}
	const out = {}
	for (const [key, value] of Object.entries(values)) {
		out[key] = ref(value)
	}
	out.loadDetail = vi.fn()
	out.reconcileOrStart = vi.fn().mockResolvedValue(undefined)
	return out
}

/**
 * Mount the panel.
 *
 * @param {object} props Component props.
 * @return {import('@vue/test-utils').Wrapper}
 */
function mountPanel(props = {}) {
	return shallowMount(ProcestCaseStatusPanel, {
		props: { object: {}, attachment: {}, ...props },
		global: {
			stubs: {
				NcButton: {
					props: ['disabled', 'variant'],
					emits: ['click'],
					template:
						'<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
				},
			},
		},
	})
}

describe('ProcestCaseStatusPanel states', () => {
	beforeEach(() => {
		state.current = fakeProcest()
	})

	it('loads the linked case on mount when the object carries a reference', () => {
		mountPanel({
			object: { zaak: `https://dossiq/cases/${UUID}` },
			attachment: { linkProperty: 'zaak' },
		})
		expect(state.current.loadDetail).toHaveBeenCalledTimes(1)
		expect(state.current.loadDetail).toHaveBeenCalledWith(
			`https://dossiq/cases/${UUID}`,
		)
	})

	it('does not load anything on mount without a reference', () => {
		mountPanel({ object: { other: 'x' }, attachment: { linkProperty: 'zaak' } })
		expect(state.current.loadDetail).not.toHaveBeenCalled()
	})

	it('shows the loading state first', () => {
		state.current = fakeProcest({ loadingDetail: true, noAccess: true })
		const wrapper = mountPanel()
		expect(wrapper.text()).toBe('Loading case…')
	})

	it('shows a no-access state instead of an error on 403', () => {
		state.current = fakeProcest({ noAccess: true, detailError: 'boom' })
		const wrapper = mountPanel()
		expect(wrapper.text()).toBe('You do not have access to this case.')
		expect(wrapper.find('.procest-case-status-panel__state--error').exists()).toBe(false)
	})

	it('retries the detail load with the link reference from the error state', async () => {
		state.current = fakeProcest({ detailError: 'boom' })
		const wrapper = mountPanel({
			object: { zaak: 'ref-1' },
			attachment: { linkProperty: 'zaak' },
		})
		expect(wrapper.text()).toContain('Could not load the linked case.')
		state.current.loadDetail.mockClear()
		await wrapper.find('button').trigger('click')
		expect(state.current.loadDetail).toHaveBeenCalledWith('ref-1')
	})

	it('offers to start a case for an unlinked object and passes the object on', async () => {
		const object = { title: 'Permit' }
		const wrapper = mountPanel({ object })
		expect(wrapper.text()).toContain('No Dossiq case is linked to this object yet.')
		const button = wrapper.find('button')
		expect(button.text()).toBe('Start case')
		expect(button.attributes('disabled')).toBeUndefined()
		await button.trigger('click')
		expect(state.current.reconcileOrStart).toHaveBeenCalledWith(object)
	})

	it('disables the start button while starting and shows the start error', () => {
		state.current = fakeProcest({ starting: true, startError: 'failed' })
		const wrapper = mountPanel()
		const button = wrapper.find('button')
		expect(button.text()).toBe('Starting…')
		expect(button.attributes('disabled')).toBeDefined()
		expect(wrapper.find('.procest-case-status-panel__warn').text()).toContain(
			'Starting the case failed.',
		)
	})

	it('renders the linked case with identification, timeline and deep link', () => {
		state.current = fakeProcest({
			caseDetail: { identificatie: 'Z-2026-1', uuid: UUID, statusName: 'Open' },
			statusHistory: [
				{ statustypeOmschrijving: 'Received', datumStatusGezet: '2026-01-01' },
				{ statustype: 'Checked', created: '2026-01-02' },
				{ naam: 'Named' },
				{ status: 'Raw' },
				{},
			],
		})
		const wrapper = mountPanel()
		expect(wrapper.find('.procest-case-status-panel__title').text()).toBe('Z-2026-1')
		const names = wrapper
			.findAll('.procest-case-status-panel__status-name')
			.map((n) => n.text())
		expect(names).toEqual(['Received', 'Checked', 'Named', 'Raw', 'Status'])
		const dates = wrapper
			.findAll('.procest-case-status-panel__status-date')
			.map((n) => n.text())
		expect(dates).toEqual(['2026-01-01', '2026-01-02', '', '', ''])
		const link = wrapper.find('a.procest-case-status-panel__link')
		expect(link.attributes('href')).toBe(`/index.php/apps/dossiq/cases/${UUID}`)
		expect(link.attributes('target')).toBe('_blank')
	})

	it('hides the timeline when there is no status history', () => {
		state.current = fakeProcest({ caseDetail: { identification: 'C-9' } })
		const wrapper = mountPanel()
		expect(wrapper.find('.procest-case-status-panel__title').text()).toBe('C-9')
		expect(wrapper.find('ol').exists()).toBe(false)
		expect(wrapper.find('a').exists()).toBe(false)
	})

	it.each([
		[{ '@self': { id: UUID } }, '', `/index.php/apps/dossiq/cases/${UUID}`],
		[null, `see ${UUID} here`, `/index.php/apps/dossiq/cases/${UUID}`],
		[null, 'no-uuid-reference', ''],
	])('builds the deep link from %j / reference %j', (detail, reference, href) => {
		state.current = fakeProcest({ caseDetail: detail })
		const wrapper = mountPanel({
			object: { zaak: reference },
			attachment: { linkProperty: 'zaak' },
		})
		expect(wrapper.vm.deepLink).toBe(href)
	})

	it('falls back to a generic identification for a reference-only link', () => {
		const wrapper = mountPanel({
			object: { zaak: 'ref-only' },
			attachment: { linkProperty: 'zaak' },
		})
		expect(wrapper.vm.hasLinkedCase).toBe(true)
		expect(wrapper.find('.procest-case-status-panel__title').text()).toBe('Linked case')
	})
})
