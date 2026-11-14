/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for IndexViewModesBuilder (REQ-PEC-008, REQ-PEC-010).
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import IndexViewModesBuilder from '../../../src/components/page-editor/fields/IndexViewModesBuilder.vue'

const NcSelectStub = {
	name: 'NcSelect',
	props: ['modelValue', 'options', 'clearable', 'placeholder', 'inputLabel'],
	emits: ['update:modelValue'],
	template: '<div class="ncselect-stub" :data-input-label="inputLabel" />',
}

const schemaProperties = {
	titel: { type: 'string' },
	inspectiedatum: { type: 'string', format: 'date' },
	status: { type: 'string', enum: ['open', 'gesloten'] },
	behandelaar: { type: 'string' },
}

function mountBuilder(config = {}, properties = schemaProperties) {
	return mount(IndexViewModesBuilder, {
		props: { config, schemaProperties: properties },
		global: { stubs: { NcSelect: NcSelectStub } },
	})
}

function lastConfig(wrapper) {
	return wrapper.emitted('update:config').at(-1)[0]
}

function select(wrapper, field) {
	return wrapper.findAllComponents(NcSelectStub).find((s) => s.attributes('data-field') === field)
}

describe('IndexViewModesBuilder', () => {
	it('ticking Calendar writes viewModes with table kept', async () => {
		const wrapper = mountBuilder({ columns: ['titel'] })
		await wrapper.find('input[data-mode="calendar"]').setValue(true)
		expect(lastConfig(wrapper)).toEqual({ columns: ['titel'], viewModes: ['table', 'calendar'] })
	})

	it('picking the date field writes calendar.dateField, offered from the date properties', async () => {
		const wrapper = mountBuilder({ viewModes: ['table', 'calendar'] })
		const date = select(wrapper, 'calendar.dateField')
		expect(date.props('options')).toEqual(['inspectiedatum'])
		expect(date.props('inputLabel')).toBe('Calendar date field')
		date.vm.$emit('update:modelValue', 'inspectiedatum')
		expect(lastConfig(wrapper)).toEqual({ viewModes: ['table', 'calendar'], calendar: { dateField: 'inspectiedatum' } })
	})

	it('a calendar without a date field shows the finding', () => {
		const wrapper = mountBuilder({ viewModes: ['table', 'calendar'] })
		expect(wrapper.text()).toContain('The calendar needs a date field.')
	})

	it('a field the schema lacks shows the finding', () => {
		const wrapper = mountBuilder({ viewModes: ['table', 'calendar'], calendar: { dateField: 'gone' } })
		expect(wrapper.text()).toContain('The schema has no field with this name.')
	})

	it('unticking Calendar removes config.calendar and the viewModes key', async () => {
		const wrapper = mountBuilder({ viewModes: ['table', 'calendar'], calendar: { dateField: 'inspectiedatum' } })
		await wrapper.find('input[data-mode="calendar"]').setValue(false)
		expect(lastConfig(wrapper)).toEqual({})
	})

	it('board offers status fields and shows its finding until one is picked', async () => {
		const wrapper = mountBuilder({ viewModes: ['table', 'board'] })
		expect(wrapper.text()).toContain('The board needs a status field.')
		const status = select(wrapper, 'board.statusField')
		expect(status.props('options')).toEqual(['status'])
		status.vm.$emit('update:modelValue', 'status')
		expect(lastConfig(wrapper)).toEqual({ viewModes: ['table', 'board'], board: { statusField: 'status' } })
	})

	it('date axis needs both dates, and clearing the last field drops the block', async () => {
		const wrapper = mountBuilder({ viewModes: ['table', 'dateAxis'], dateAxis: { startField: 'inspectiedatum' } })
		expect(wrapper.text()).toContain('The date axis needs an end date field.')
		expect(wrapper.text()).not.toContain('The date axis needs a start date field.')
		select(wrapper, 'dateAxis.startField').vm.$emit('update:modelValue', null)
		expect(lastConfig(wrapper)).toEqual({ viewModes: ['table', 'dateAxis'] })
	})

	it('keeps the editor order when several views are ticked', async () => {
		const wrapper = mountBuilder({ viewModes: ['table', 'dateAxis'] })
		await wrapper.find('input[data-mode="cards"]').setValue(true)
		expect(lastConfig(wrapper).viewModes).toEqual(['table', 'cards', 'dateAxis'])
	})
})
