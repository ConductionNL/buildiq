/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for `src/modals/AppSettingsModal.vue`'s "Data registers"
 * section (data-registers-runtime task 5.1).
 *
 * Covers:
 *   - existing dataRegisters bindings render as editable rows
 *   - addRow() appends an empty row (not yet emitted — no register slug)
 *   - editing a row's register slug emits update:data-registers with the
 *     full array
 *   - removeRow() drops the row and emits the shortened array
 *   - an empty label is omitted from the emitted payload rather than sent
 *     as ''
 *   - the dataRegisters prop changing (e.g. after obPatchApp()'s response)
 *     re-syncs the displayed rows
 */

import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import AppSettingsModal from '../../src/modals/AppSettingsModal.vue'

const baseStubs = {
	NcModal: {
		name: 'NcModal',
		props: ['name'],
		template: '<div class="nc-modal-stub"><slot /></div>',
	},
	// `emits: ['click']` is load-bearing under Vue 3: an undeclared emit leaves
	// the parent's `@click` in `$attrs`, it falls through onto the root
	// <button>, and one click runs the handler twice.
	NcButton: {
		name: 'NcButton',
		props: ['type', 'disabled'],
		emits: ['click'],
		template: '<button :disabled="disabled" :data-type="type" @click="$emit(\'click\', $event)"><slot /></button>',
	},
	// Vue 3 model API throughout: `modelValue` in, `update:modelValue` out.
	// The Vue 2 `checked` / `value` props receive nothing from the component's
	// `:model-value` bindings, which is why every field rendered empty.
	NcCheckboxRadioSwitch: {
		name: 'NcCheckboxRadioSwitch',
		props: ['modelValue', 'type', 'disabled'],
		emits: ['update:modelValue'],
		template: '<input type="checkbox" :checked="modelValue" :disabled="disabled" @change="$emit(\'update:modelValue\', $event.target.checked)" />',
	},
	NcTextField: {
		name: 'NcTextField',
		props: ['modelValue', 'label', 'disabled'],
		emits: ['update:modelValue'],
		template: '<input class="nc-textfield-stub" :data-label="label" :value="modelValue" :disabled="disabled" @input="$emit(\'update:modelValue\', $event.target.value)" />',
	},
}

function mountModal(propsData = {}) {
	return mount(AppSettingsModal, {
		propsData: { open: true, ...propsData },
		stubs: baseStubs,
	})
}

describe('AppSettingsModal — Data registers section (data-registers-runtime task 5.1)', () => {
	it('renders one row per existing dataRegisters binding', () => {
		const wrapper = mountModal({
			dataRegisters: [
				{ register: 'spectr', label: 'Spectr market intelligence data' },
				{ register: 'bag-adressen' },
			],
		})
		const rows = wrapper.findAll('.app-settings__data-register-row')
		expect(rows).toHaveLength(2)
		const textFields = wrapper.findAll('.nc-textfield-stub')
		expect(textFields.at(0).element.value).toBe('spectr')
		expect(textFields.at(1).element.value).toBe('Spectr market intelligence data')
		expect(textFields.at(2).element.value).toBe('bag-adressen')
		expect(textFields.at(3).element.value).toBe('')
	})

	it('renders no rows when dataRegisters is empty', () => {
		const wrapper = mountModal({ dataRegisters: [] })
		expect(wrapper.findAll('.app-settings__data-register-row')).toHaveLength(0)
	})

	it('addRow() appends an empty row without emitting (no register slug yet)', async () => {
		const wrapper = mountModal({ dataRegisters: [] })
		await wrapper.vm.addRow()
		expect(wrapper.vm.rows).toHaveLength(1)
		expect(wrapper.emitted('update:data-registers')[0][0]).toEqual([])
	})

	it('typing a register slug on a new row emits the full array', async () => {
		const wrapper = mountModal({ dataRegisters: [] })
		await wrapper.vm.addRow()
		wrapper.vm.updateRow(0, 'register', 'spectr')
		await wrapper.vm.$nextTick()
		const emitted = wrapper.emitted('update:data-registers')
		const last = emitted[emitted.length - 1][0]
		expect(last).toEqual([{ register: 'spectr' }])
	})

	it('a non-empty label is included; an empty label is omitted from the payload', async () => {
		const wrapper = mountModal({ dataRegisters: [{ register: 'spectr' }] })
		wrapper.vm.updateRow(0, 'label', 'Spectr market intelligence data')
		await wrapper.vm.$nextTick()
		let last = wrapper.emitted('update:data-registers').pop()[0]
		expect(last).toEqual([{ register: 'spectr', label: 'Spectr market intelligence data' }])

		wrapper.vm.updateRow(0, 'label', '')
		await wrapper.vm.$nextTick()
		last = wrapper.emitted('update:data-registers').pop()[0]
		expect(last).toEqual([{ register: 'spectr' }])
	})

	it('removeRow() drops the row and emits the shortened array', async () => {
		const wrapper = mountModal({
			dataRegisters: [{ register: 'spectr' }, { register: 'bag-adressen' }],
		})
		wrapper.vm.removeRow(0)
		await wrapper.vm.$nextTick()
		const last = wrapper.emitted('update:data-registers').pop()[0]
		expect(last).toEqual([{ register: 'bag-adressen' }])
		expect(wrapper.vm.rows).toHaveLength(1)
	})

	it('re-syncs displayed rows when the dataRegisters prop changes', async () => {
		const wrapper = mountModal({ dataRegisters: [] })
		expect(wrapper.vm.rows).toEqual([])
		await wrapper.setProps({ dataRegisters: [{ register: 'spectr', label: 'Spectr' }] })
		expect(wrapper.vm.rows).toEqual([{ register: 'spectr', label: 'Spectr' }])
	})

	it('a row mid-edit with no register slug is dropped from the emitted payload', async () => {
		const wrapper = mountModal({ dataRegisters: [{ register: 'spectr' }] })
		await wrapper.vm.addRow()
		wrapper.vm.updateRow(1, 'label', 'still typing the slug')
		await wrapper.vm.$nextTick()
		const last = wrapper.emitted('update:data-registers').pop()[0]
		expect(last).toEqual([{ register: 'spectr' }])
	})
})
