/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The `scan` field type of FormFieldBuilder (pages-qr-and-barcodes,
 * REQ-BQQR-002): picking it accepts every code format, the formats can be
 * narrowed, and leaving the type drops them.
 *
 * @spec openspec/changes/pages-qr-and-barcodes/specs/page-codes/spec.md#requirement-forms-can-fill-a-field-from-a-scan-req-bqqr-002
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import FormFieldBuilder from '../../../src/components/page-editor/fields/FormFieldBuilder.vue'

/**
 * Mount the builder over the given fields.
 *
 * @param {Array<object>} modelValue - the fields.
 * @return {object} The wrapper.
 */
function mountBuilder(modelValue) {
	return mount(FormFieldBuilder, { props: { modelValue } })
}

/**
 * The fields of the last emitted update.
 *
 * @param {object} wrapper - the wrapper.
 * @return {Array<object>}
 */
function last(wrapper) {
	const emitted = wrapper.emitted('update:modelValue')
	return emitted[emitted.length - 1][0]
}

describe('FormFieldBuilder scan field', () => {
	it('offers scan as a type and, once picked, accepts every code format', async () => {
		const wrapper = mountBuilder([{ key: 'trackingcode', label: 'Trackingcode', type: 'string' }])
		const types = wrapper.findAll('select option').map((o) => o.attributes('value'))
		expect(types).toContain('scan')

		await wrapper.find('select').setValue('scan')

		expect(last(wrapper)).toEqual([{ key: 'trackingcode', label: 'Trackingcode', type: 'scan', formats: ['qr', 'code128', 'ean13'] }])
	})

	it('shows the formats only on a scan field and narrows them in listed order', async () => {
		const wrapper = mountBuilder([
			{ key: 'naam', type: 'string' },
			{ key: 'trackingcode', type: 'scan', formats: ['ean13', 'qr'] },
		])
		const groups = wrapper.findAll('[data-testid="form-field-scan-formats"]')
		expect(groups).toHaveLength(1)
		const boxes = groups[0].findAll('input[type="checkbox"]')
		expect(boxes.map((b) => [b.attributes('value'), b.element.checked])).toEqual([['qr', true], ['code128', false], ['ean13', true]])

		await boxes[1].setValue(true)
		expect(last(wrapper)[1].formats).toEqual(['qr', 'code128', 'ean13'])

		await boxes[0].setValue(false)
		expect(last(wrapper)[1].formats).toEqual(['ean13'])
	})

	it('keeps formats chosen before when the type returns to scan, and drops them for another type', async () => {
		const wrapper = mountBuilder([{ key: 'code', type: 'scan', formats: ['code128'] }])

		await wrapper.find('select').setValue('string')
		expect(last(wrapper)).toEqual([{ key: 'code', type: 'string' }])

		const again = mountBuilder([{ key: 'code', type: 'string', formats: ['code128'] }])
		await again.find('select').setValue('scan')
		expect(last(again)[0].formats).toEqual(['code128'])
	})
})
