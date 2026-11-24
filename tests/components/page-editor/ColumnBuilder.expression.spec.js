/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for the fx toggle of ColumnBuilder (pages-custom-code
 * REQ-BQCC-002): a column switched to fx becomes `{ key, label?, expression }`,
 * its name is typed rather than picked, the label survives the switch both
 * ways, and switching off drops the expression.
 *
 * @spec openspec/changes/archive/2026-11-24-pages-custom-code/specs/page-custom-code/spec.md#requirement-an-owner-writes-expressions-for-columns-and-form-fields-req-bqcc-002
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import ColumnBuilder from '../../../src/components/page-editor/fields/ColumnBuilder.vue'

const schemaProperties = { aantal: { type: 'number' }, prijs: { type: 'number' } }

/**
 * Mount the builder.
 *
 * @param {Array} modelValue The columns.
 * @return {import('@vue/test-utils').VueWrapper}
 */
function render(modelValue) {
	return mount(ColumnBuilder, { props: { modelValue, schemaProperties } })
}

/**
 * The list emitted last.
 *
 * @param {import('@vue/test-utils').VueWrapper} wrapper The builder.
 * @return {Array}
 */
function lastEmitted(wrapper) {
	return wrapper.emitted('update:modelValue').at(-1)[0]
}

describe('ColumnBuilder fx', () => {
	it('switching a shorthand column to fx keeps its key and starts an empty expression', async () => {
		const wrapper = render(['aantal'])
		await wrapper.find('.column-builder__fx').trigger('click')
		expect(lastEmitted(wrapper)).toEqual([{ key: 'aantal', expression: '' }])
	})

	it('a computed column shows a name input and its expression instead of the property picker', () => {
		const wrapper = render([{ key: 'totaal', label: 'Total', expression: 'row.aantal * row.prijs' }])
		expect(wrapper.find('select.column-builder__key').exists()).toBe(false)
		expect(wrapper.find('input.column-builder__key').element.value).toBe('totaal')
		expect(wrapper.find('.column-builder__expression').element.value).toBe('row.aantal * row.prijs')
		expect(wrapper.find('.column-builder__fx').attributes('aria-pressed')).toBe('true')
	})

	it('typing an expression writes columns[].expression and keeps key and label', async () => {
		const wrapper = render([{ key: 'totaal', label: 'Total', expression: '' }])
		await wrapper.find('.column-builder__expression').setValue('row.aantal * row.prijs')
		expect(lastEmitted(wrapper)).toEqual([{ key: 'totaal', label: 'Total', expression: 'row.aantal * row.prijs' }])
	})

	it('typing the name of a computed column keeps its expression', async () => {
		const wrapper = render([{ key: '', expression: 'row.aantal * row.prijs' }])
		await wrapper.find('input.column-builder__key').setValue('totaal')
		expect(lastEmitted(wrapper)).toEqual([{ key: 'totaal', expression: 'row.aantal * row.prijs' }])
	})

	it('a label typed on a computed column keeps its expression', async () => {
		const wrapper = render([{ key: 'totaal', expression: 'row.aantal * row.prijs' }])
		await wrapper.find('.column-builder__label').setValue('Total')
		expect(lastEmitted(wrapper)).toEqual([{ key: 'totaal', label: 'Total', expression: 'row.aantal * row.prijs' }])
	})

	it('switching fx off drops the expression, keeping a label, else collapsing to the key', async () => {
		const labelled = render([{ key: 'totaal', label: 'Total', expression: 'row.aantal' }])
		await labelled.find('.column-builder__fx').trigger('click')
		expect(lastEmitted(labelled)).toEqual([{ key: 'totaal', label: 'Total' }])

		const bare = render([{ key: 'aantal', expression: 'row.aantal' }])
		await bare.find('.column-builder__fx').trigger('click')
		expect(lastEmitted(bare)).toEqual(['aantal'])
	})

	it('only the toggled row changes', async () => {
		const wrapper = render(['aantal', 'prijs'])
		await wrapper.findAll('.column-builder__fx')[1].trigger('click')
		expect(lastEmitted(wrapper)).toEqual(['aantal', { key: 'prijs', expression: '' }])
	})
})
