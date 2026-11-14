/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for FormFieldBuilder.vue: the flat row an author types into —
 * key, label, type, required, pattern — and the details area's default and
 * calculation writes, plus collapsing a details area again.
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import FormFieldBuilder from '../../../src/components/page-editor/fields/FormFieldBuilder.vue'

/**
 * Mount the builder over fields.
 *
 * @param {Array<object>} modelValue - the fields.
 * @param {boolean} showLogic - whether the details area is offered.
 * @return {object} The wrapper.
 */
function mountBuilder(modelValue, showLogic = false) {
	return mount(FormFieldBuilder, { props: { modelValue, showLogic } })
}

/**
 * The last emitted fields.
 *
 * @param {object} wrapper - the wrapper.
 * @return {Array<object>} The fields.
 */
function last(wrapper) {
	const emitted = wrapper.emitted('update:modelValue')
	return emitted[emitted.length - 1][0]
}

describe('FormFieldBuilder rows', () => {
	it('writes the key, label and type; an emptied key or label stays as an empty string', async () => {
		const wrapper = mountBuilder([{ key: 'naam', label: 'Naam', type: 'string' }])
		const [key, label] = wrapper.findAll('input[type="text"]')

		await key.setValue('voornaam')
		expect(last(wrapper)).toEqual([{ key: 'voornaam', label: 'Naam', type: 'string' }])

		await label.setValue('')
		expect(last(wrapper)).toEqual([{ key: 'naam', label: '', type: 'string' }])

		await wrapper.find('select').setValue('number')
		expect(last(wrapper)[0].type).toBe('number')
	})

	it('ticks required and writes a pattern; clearing either drops the key', async () => {
		const wrapper = mountBuilder([{ key: 'postcode', label: 'Postcode', type: 'string', required: true, pattern: 'x' }])
		const pattern = wrapper.findAll('input[type="text"]').at(2)
		const required = wrapper.find('input[type="checkbox"]')

		expect(required.element.checked).toBe(true)
		await required.setValue(false)
		expect(last(wrapper)[0]).not.toHaveProperty('required')

		await pattern.setValue('^[0-9]{4}[A-Z]{2}$')
		expect(last(wrapper)[0].pattern).toBe('^[0-9]{4}[A-Z]{2}$')

		await pattern.setValue('')
		expect(last(wrapper)[0]).not.toHaveProperty('pattern')
	})

	it('the add and remove buttons append an empty string field and drop one', async () => {
		const wrapper = mountBuilder([{ key: 'a', label: 'A', type: 'string' }])

		await wrapper.find('.form-field-builder__add').trigger('click')
		expect(last(wrapper)).toEqual([
			{ key: 'a', label: 'A', type: 'string' },
			{ key: '', label: '', type: 'string' },
		])

		await wrapper.find('.form-field-builder__remove').trigger('click')
		expect(last(wrapper)).toEqual([])
	})

	it('a default and a calculation are written, and a null one removes the key', async () => {
		const wrapper = mountBuilder(
			[{ key: 'total', label: 'Total', type: 'number', calculate: { op: 'sum' }, extra: 'kept' }],
			true,
		)
		await wrapper.find('.form-field-builder__disclosure').trigger('click')

		const defaults = wrapper.findComponent({ name: 'FieldDefaultBuilder' })
		expect(defaults.props('modelValue')).toBeNull()
		defaults.vm.$emit('update:modelValue', { source: 'today' })
		expect(last(wrapper)[0].default).toEqual({ source: 'today' })

		const calculation = wrapper.findComponent({ name: 'FieldCalculationBuilder' })
		calculation.vm.$emit('update:modelValue', null)
		expect(last(wrapper)[0]).toEqual({ key: 'total', label: 'Total', type: 'number', extra: 'kept' })
	})

	it('a second click on the disclosure closes the details again', async () => {
		const wrapper = mountBuilder([{ key: 'a', label: 'A', type: 'string' }], true)
		const disclosure = wrapper.find('.form-field-builder__disclosure')

		await disclosure.trigger('click')
		expect(disclosure.attributes('aria-expanded')).toBe('true')

		await disclosure.trigger('click')
		expect(disclosure.attributes('aria-expanded')).toBe('false')
		expect(wrapper.find('.form-field-builder__details').exists()).toBe(false)
	})
})
