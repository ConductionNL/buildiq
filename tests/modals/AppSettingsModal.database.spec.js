/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for the "Connect a database" picker in
 * `src/modals/AppSettingsModal.vue` (data-external-database-sources T02).
 *
 * @spec openspec/changes/data-external-database-sources/specs/external-database-sources/spec.md#requirement-an-app-binds-a-connected-database-req-bqdb-001
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const fetchConnectedDatabases = vi.fn()
vi.mock('../../src/composables/useConnectedDatabases.js', () => ({
	fetchConnectedDatabases: (...args) => fetchConnectedDatabases(...args),
}))

const { default: AppSettingsModal } = await import('../../src/modals/AppSettingsModal.vue')

const stubs = {
	NcModal: { name: 'NcModal', template: '<div><slot /></div>' },
	NcButton: { name: 'NcButton', props: ['disabled'], emits: ['click'], template: '<button @click="$emit(\'click\')"><slot /></button>' },
	NcCheckboxRadioSwitch: { name: 'NcCheckboxRadioSwitch', template: '<input type="checkbox" />' },
	NcTextField: { name: 'NcTextField', props: ['modelValue', 'label'], template: '<input class="nc-textfield-stub" :value="modelValue" />' },
	NcSelect: { name: 'NcSelect', props: ['modelValue', 'options', 'inputLabel', 'disabled', 'loading', 'multiple'], emits: ['update:modelValue'], template: '<div class="nc-select-stub" />' },
}

const DATABASES = [
	{ register: 'permits-db', label: 'permits-db', source: '7', writable: false },
	{ register: 'crm-db', label: 'CRM', source: '8', writable: true },
]

function mountModal(props = {}) {
	return mount(AppSettingsModal, { propsData: { open: true, ...props }, stubs })
}

function databasePicker(wrapper) {
	return wrapper.findAllComponents({ name: 'NcSelect' }).find((select) => select.props('inputLabel') === 'Connect a database')
}

describe('AppSettingsModal — Connect a database (REQ-BQDB-001)', () => {
	beforeEach(() => {
		fetchConnectedDatabases.mockReset()
		fetchConnectedDatabases.mockResolvedValue(DATABASES)
	})

	it('reads the connected databases from OpenRegister when it opens', async () => {
		const wrapper = mountModal()
		await flushPromises()

		expect(fetchConnectedDatabases).toHaveBeenCalledTimes(1)
		expect(databasePicker(wrapper).props('options')).toEqual([
			{ label: 'permits-db', value: 'permits-db' },
			{ label: 'CRM', value: 'crm-db' },
		])
	})

	it('reads them again on every open', async () => {
		const wrapper = mountModal({ open: false })
		await flushPromises()
		expect(fetchConnectedDatabases).not.toHaveBeenCalled()

		await wrapper.setProps({ open: true })
		await flushPromises()
		await wrapper.setProps({ open: false })
		await wrapper.setProps({ open: true })
		await flushPromises()

		expect(fetchConnectedDatabases).toHaveBeenCalledTimes(2)
	})

	it('picking a database emits update:data-registers with the new binding appended', async () => {
		const wrapper = mountModal({ dataRegisters: [{ register: 'bag-adressen' }] })
		await flushPromises()

		databasePicker(wrapper).vm.$emit('update:modelValue', { label: 'permits-db', value: 'permits-db' })
		await flushPromises()

		const emitted = wrapper.emitted('update:data-registers')
		expect(emitted).toHaveLength(1)
		expect(emitted[0][0]).toEqual([
			{ register: 'bag-adressen' },
			{ register: 'permits-db', label: 'permits-db' },
		])
	})

	it('does not offer a database that is already bound', async () => {
		const wrapper = mountModal({ dataRegisters: [{ register: 'crm-db', label: 'CRM' }] })
		await flushPromises()

		expect(databasePicker(wrapper).props('options')).toEqual([{ label: 'permits-db', value: 'permits-db' }])
	})

	it('clearing the picker emits nothing', async () => {
		const wrapper = mountModal()
		await flushPromises()

		databasePicker(wrapper).vm.$emit('update:modelValue', null)

		expect(wrapper.emitted('update:data-registers')).toBeUndefined()
	})

	it('says so when no connected database is left to add', async () => {
		fetchConnectedDatabases.mockResolvedValue([])
		const wrapper = mountModal()
		await flushPromises()

		expect(wrapper.text()).toContain('No connected database to add.')
	})
})
