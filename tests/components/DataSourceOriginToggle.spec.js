import { mount } from '@vue/test-utils'
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for DataSourceOriginToggle.vue (origin switch + confirm-clear).
 *
 * Spec: openconnector-api-sources (REQ-OCAS-002).
 */
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn().mockResolvedValue({ data: {} }) },
}))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))

import axios from '@nextcloud/axios'
import DataSourceOriginToggle from '../../src/components/page-editor/DataSourceOriginToggle.vue'
import { fleetAppPath } from '../../src/services/fleetAppId.js'

const stubs = {
	ConnectorSourcePicker: {
		name: 'ConnectorSourcePicker',
		template: '<div class="picker-stub" />',
	},
	ConnectorFieldMapper: {
		name: 'ConnectorFieldMapper',
		template: '<div class="mapper-stub" />',
	},
}

function factory(dataSource) {
	return mount(DataSourceOriginToggle, { propsData: { dataSource }, stubs })
}

describe('DataSourceOriginToggle', () => {
	it('derives OpenRegister origin for a register binding', () => {
		const wrapper = factory({ register: 'r', schema: 's' })
		expect(wrapper.find('.picker-stub').exists()).toBe(false)
	})

	it('mounts the picker + mapper for a connector binding', () => {
		const wrapper = factory({ connector: { endpointPath: 'x', fields: {} } })
		expect(wrapper.find('.picker-stub').exists()).toBe(true)
		expect(wrapper.find('.mapper-stub').exists()).toBe(true)
	})

	it('switching to OpenConnector seeds an empty connector block', async () => {
		const wrapper = factory({})
		const radios = wrapper.findAll('input[type="radio"]')
		await radios.at(1).setChecked() // OpenConnector
		const emitted = wrapper.emitted()['update:dataSource'][0][0]
		expect(emitted.connector).toEqual({ endpointPath: '', fields: {} })
	})

	it('switching back to OpenRegister with a mapping asks first and clears on accept', async () => {
		const wrapper = factory({
			connector: { endpointPath: 'x', fields: { a: 'a' } },
		})
		const radios = wrapper.findAll('input[type="radio"]')
		await radios.at(0).setChecked() // OpenRegister
		// The mapping is still intact at this point — only the dialog opened.
		expect(wrapper.vm.confirmSwitchOpen).toBe(true)
		expect(wrapper.emitted()['update:dataSource']).toBeUndefined()

		wrapper.vm.onConfirmSwitch()
		const emitted = wrapper.emitted()['update:dataSource'][0][0]
		expect(emitted.connector).toBeUndefined()
	})

	it('cancelling the confirm keeps the connector binding', async () => {
		const wrapper = factory({
			connector: { endpointPath: 'x', fields: { a: 'a' } },
		})
		const radios = wrapper.findAll('input[type="radio"]')
		await radios.at(0).setChecked()
		// Simulate dismissing the dialog: onConfirmSwitch is never called.
		wrapper.vm.confirmSwitchOpen = false
		expect(wrapper.emitted()['update:dataSource']).toBeUndefined()
	})

	it('switching back with NO mapping to lose does not ask at all', async () => {
		const wrapper = factory({ connector: { endpointPath: '', fields: {} } })
		const radios = wrapper.findAll('input[type="radio"]')
		await radios.at(0).setChecked()
		expect(wrapper.vm.confirmSwitchOpen).toBe(false)
		const emitted = wrapper.emitted()['update:dataSource'][0][0]
		expect(emitted.connector).toBeUndefined()
	})
	describe('selectOrigin and onSampleFetch branches', () => {
		it('ignores a switch to the current origin', () => {
			const wrapper = factory({ connector: { endpointPath: 'x' } })
			wrapper.vm.selectOrigin('openconnector')
			factory({}).vm.selectOrigin('openregister')
			expect(wrapper.emitted('update:dataSource')).toBeUndefined()
		})

		it.each([
			[{ endpointPath: 'x' }, true],
			[{ endpointPath: '', fields: { a: 'a' } }, true],
			[{ endpointPath: '', fields: {} }, false],
			[{}, false],
		])('asks before dropping the connector %j: %s', (connector, asks) => {
			const wrapper = factory({ register: 'r', connector })
			wrapper.vm.selectOrigin('openregister')
			expect(wrapper.vm.confirmSwitchOpen).toBe(asks)
			if (asks) {
				expect(wrapper.emitted('update:dataSource')).toBeUndefined()
			} else {
				expect(wrapper.emitted('update:dataSource')[0][0]).toEqual({ register: 'r' })
			}
		})

		it('does not fetch a sample without an endpoint', async () => {
			axios.get.mockClear()
			const wrapper = factory({ connector: {} })
			await wrapper.vm.onSampleFetch('')
			expect(axios.get).not.toHaveBeenCalled()
		})

		it.each([
			[{ data: { rows: [1] } }, { rows: [1] }],
			[{ rows: [2] }, { rows: [2] }],
			[{ data: null }, null],
			[null, null],
		])('reads the sample response %j', async (data, sample) => {
			axios.get.mockClear()
			axios.get.mockResolvedValueOnce({ data })
			const wrapper = factory({ connector: { query: { page: 2 } } })
			await wrapper.vm.onSampleFetch('//items/list')
			expect(axios.get).toHaveBeenCalledWith(
				fleetAppPath('integriq', 'api/endpoint/items/list'),
				{ params: { page: 2 } },
			)
			expect(wrapper.vm.sample).toEqual(sample)
			expect(wrapper.vm.sampleLoading).toBe(false)
		})

		it('sends no query params when the connector has none, and clears the sample on failure', async () => {
			axios.get.mockClear()
			axios.get.mockRejectedValueOnce(new Error('down'))
			const wrapper = factory({ connector: {} })
			wrapper.vm.sample = { old: true }
			await wrapper.vm.onSampleFetch('items')
			expect(axios.get.mock.calls[0][1]).toEqual({ params: {} })
			expect(wrapper.vm.sample).toBeNull()
			expect(wrapper.vm.sampleLoading).toBe(false)
		})
	})
})
