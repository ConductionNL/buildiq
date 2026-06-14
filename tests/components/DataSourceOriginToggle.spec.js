/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for DataSourceOriginToggle.vue (origin switch + confirm-clear).
 *
 * Spec: openconnector-api-sources (REQ-OCAS-002).
 */
import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn().mockResolvedValue({ data: {} }) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))

import DataSourceOriginToggle from '../../src/components/page-editor/DataSourceOriginToggle.vue'

const stubs = {
	ConnectorSourcePicker: { name: 'ConnectorSourcePicker', template: '<div class="picker-stub" />' },
	ConnectorFieldMapper: { name: 'ConnectorFieldMapper', template: '<div class="mapper-stub" />' },
}

const factory = (dataSource) => mount(DataSourceOriginToggle, { propsData: { dataSource }, stubs })

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

	it('switching back to OpenRegister with a mapping prompts confirm and clears on accept', async () => {
		window.confirm = vi.fn(() => true)
		const wrapper = factory({ connector: { endpointPath: 'x', fields: { a: 'a' } } })
		const radios = wrapper.findAll('input[type="radio"]')
		await radios.at(0).setChecked() // OpenRegister
		expect(window.confirm).toHaveBeenCalled()
		const emitted = wrapper.emitted()['update:dataSource'][0][0]
		expect(emitted.connector).toBeUndefined()
	})

	it('cancelling the confirm keeps the connector binding', async () => {
		window.confirm = vi.fn(() => false)
		const wrapper = factory({ connector: { endpointPath: 'x', fields: { a: 'a' } } })
		const radios = wrapper.findAll('input[type="radio"]')
		await radios.at(0).setChecked()
		expect(wrapper.emitted()['update:dataSource']).toBeUndefined()
	})
})
