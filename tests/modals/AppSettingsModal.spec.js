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

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
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
		template:
			'<button :disabled="disabled" :data-type="type" @click="$emit(\'click\', $event)"><slot /></button>',
	},
	// Vue 3 model API throughout: `modelValue` in, `update:modelValue` out.
	// The Vue 2 `checked` / `value` props receive nothing from the component's
	// `:model-value` bindings, which is why every field rendered empty.
	NcCheckboxRadioSwitch: {
		name: 'NcCheckboxRadioSwitch',
		props: ['modelValue', 'type', 'disabled'],
		emits: ['update:modelValue'],
		template:
			'<input type="checkbox" :checked="modelValue" :disabled="disabled" @change="$emit(\'update:modelValue\', $event.target.checked)" />',
	},
	// The database picker (REQ-BQDB-001) is not under test here.
	NcSelect: { name: 'NcSelect', template: '<div />' },
	NcTextField: {
		name: 'NcTextField',
		props: ['modelValue', 'label', 'disabled'],
		emits: ['update:modelValue'],
		template:
			'<input class="nc-textfield-stub" :data-label="label" :value="modelValue" :disabled="disabled" @input="$emit(\'update:modelValue\', $event.target.value)" />',
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
		expect(textFields.at(1).element.value).toBe(
			'Spectr market intelligence data',
		)
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
		expect(last).toEqual([
			{ register: 'spectr', label: 'Spectr market intelligence data' },
		])

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
		await wrapper.setProps({
			dataRegisters: [{ register: 'spectr', label: 'Spectr' }],
		})
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

describe('AppSettingsModal — Installable section (pages-installable-offline-and-mobile T02)', () => {
	it('shows the stored short name and theme colour', () => {
		const wrapper = mountModal({ shortName: 'Vergun', themeColor: '#1f6feb' })
		const section = wrapper.find('[data-test="app-settings-installable"]')
		expect(section.exists()).toBe(true)
		expect(section.find('.nc-textfield-stub').element.value).toBe('Vergun')
		expect(section.find('[data-test="app-settings-theme-color"]').element.value).toBe('#1f6feb')
	})

	it('emits a trimmed short name cut to 12 characters with the colour it holds', () => {
		const wrapper = mountModal({ themeColor: '#1F6FEB' })
		wrapper.vm.installShortName = '  Vergunningen aanvragen  '
		wrapper.vm.emitInstallable()
		expect(wrapper.emitted('update:installable')[0][0]).toEqual({ shortName: 'Vergunningen', themeColor: '#1f6feb' })
	})

	it('saves a picked colour, and sends no colour for one that is not six-digit hex', async () => {
		const wrapper = mountModal({ shortName: 'Vergun' })
		const input = wrapper.find('[data-test="app-settings-theme-color"]')
		input.element.value = '#00aa55'
		await input.trigger('change')
		wrapper.vm.onThemeColor('red')
		const emitted = wrapper.emitted('update:installable').map((call) => call[0])
		expect(emitted).toEqual([
			{ shortName: 'Vergun', themeColor: '#00aa55' },
			{ shortName: 'Vergun', themeColor: '' },
		])
	})

	it('offers "Use the browser colour" only while a colour is set, and clears it', async () => {
		const wrapper = mountModal({ themeColor: '#123456' })
		const clear = wrapper.findAll('button').filter((button) => button.text() === 'Use the browser colour')
		expect(clear).toHaveLength(1)
		await clear.at(0).trigger('click')
		expect(wrapper.emitted('update:installable')[0][0]).toEqual({ shortName: '', themeColor: '' })
		await wrapper.vm.$nextTick()
		expect(wrapper.findAll('button').filter((button) => button.text() === 'Use the browser colour')).toHaveLength(0)
	})
})

describe('AppSettingsModal — Offline section (pages-installable-offline-and-mobile T04)', () => {
	const offline = {
		takeAlong: [{ schema: 'inspection', filter: { assignee: '@me' }, limit: 50, ttlHours: 12 }],
		forms: ['report'],
	}

	it('is hidden while the instance does not let apps work offline', () => {
		const wrapper = mountModal({ offline })
		expect(wrapper.find('[data-test="app-settings-offline"]').exists()).toBe(false)
	})

	it('shows one row per take-along, with the filter as JSON', () => {
		const wrapper = mountModal({ offlineAllowed: true, offline })
		const rows = wrapper.findAll('[data-test="app-settings-take-along-row"]')
		expect(rows).toHaveLength(1)
		const fields = rows.at(0).findAll('.nc-textfield-stub')
		expect(fields.at(0).element.value).toBe('inspection')
		expect(fields.at(1).element.value).toBe('{"assignee":"@me"}')
		expect(fields.at(2).element.value).toBe('50')
		expect(fields.at(3).element.value).toBe('12')
	})

	it('emits the block capped at 500 records and 72 hours, keeping the offline forms', () => {
		const wrapper = mountModal({ offlineAllowed: true, offline })
		wrapper.vm.takeAlongRows[0].limit = '900'
		wrapper.vm.takeAlongRows[0].ttlHours = '100'
		wrapper.vm.emitOffline()
		expect(wrapper.emitted('update:offline')[0][0]).toEqual({
			takeAlong: [{ schema: 'inspection', filter: { assignee: '@me' }, limit: 500, ttlHours: 72 }],
			forms: ['report'],
		})
	})

	it('adds a row with the defaults, and does not emit one without a schema', async () => {
		const wrapper = mountModal({ offlineAllowed: true })
		await wrapper.find('[data-test="app-settings-add-take-along"]').trigger('click')
		expect(wrapper.vm.takeAlongRows).toEqual([{ schema: '', filter: '', filterInvalid: false, limit: 50, ttlHours: 24 }])
		wrapper.vm.emitOffline()
		expect(wrapper.emitted('update:offline')[0][0]).toBeNull()
	})

	it('marks a filter that is not a JSON object and saves nothing', () => {
		const wrapper = mountModal({ offlineAllowed: true, offline })
		wrapper.vm.takeAlongRows[0].filter = '["not", "an object"]'
		wrapper.vm.emitOffline()
		expect(wrapper.vm.takeAlongRows[0].filterInvalid).toBe(true)
		expect(wrapper.emitted('update:offline')).toBeUndefined()
	})

	it('removes a row and saves the rest', () => {
		const wrapper = mountModal({ offlineAllowed: true, offline })
		wrapper.vm.removeTakeAlong(0)
		expect(wrapper.emitted('update:offline')[0][0]).toEqual({ takeAlong: [], forms: ['report'] })
	})
})
