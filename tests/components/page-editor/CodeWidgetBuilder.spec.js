/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * CodeWidgetBuilder (pages-qr-and-barcodes, REQ-BQQR-003): code widgets of a
 * detail page, edited in place while other widgets stay as they are.
 *
 * @spec openspec/changes/pages-qr-and-barcodes/specs/page-codes/spec.md#requirement-detail-pages-can-show-a-code-req-bqqr-003
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import CodeWidgetBuilder from '../../../src/components/page-editor/fields/CodeWidgetBuilder.vue'

/**
 * The widget list of the last emitted update.
 *
 * @param {object} wrapper - the wrapper.
 * @return {Array<object>}
 */
function last(wrapper) {
	const emitted = wrapper.emitted('update:modelValue')
	return emitted[emitted.length - 1][0]
}

const NOTES = { id: 'notes', type: 'custom', title: 'Notities' }

describe('CodeWidgetBuilder', () => {
	it('lists only the code widgets', () => {
		const wrapper = mount(CodeWidgetBuilder, {
			props: { modelValue: [NOTES, { id: 'qr', type: 'code', format: 'qr', source: 'address' }] },
		})

		expect(wrapper.findAll('[data-testid="code-widget-row"]')).toHaveLength(1)
	})

	it('adds a QR code of the address with an id not yet taken, after the other widgets', async () => {
		const wrapper = mount(CodeWidgetBuilder, { props: { modelValue: [NOTES, { id: 'code-1', type: 'code', format: 'qr', source: 'address' }] } })

		await wrapper.find('[data-testid="code-widget-add"]').trigger('click')

		expect(last(wrapper)[2]).toEqual({ id: 'code-2', type: 'code', format: 'qr', source: 'address' })
		expect(last(wrapper)[0]).toEqual(NOTES)
	})

	it('sets the format and the field source with its field, in place', async () => {
		const wrapper = mount(CodeWidgetBuilder, { props: { modelValue: [NOTES, { id: 'c', type: 'code', format: 'qr', source: 'field', field: 'serienummer' }] } })

		await wrapper.find('[data-testid="code-widget-format"]').setValue('code128')
		expect(last(wrapper)).toEqual([NOTES, { id: 'c', type: 'code', format: 'code128', source: 'field', field: 'serienummer' }])

		await wrapper.find('[data-testid="code-widget-field"]').setValue('artikelnummer')
		expect(last(wrapper)[1].field).toBe('artikelnummer')
	})

	it('drops the field when the source becomes the address, and shows no field input then', async () => {
		const wrapper = mount(CodeWidgetBuilder, { props: { modelValue: [{ id: 'c', type: 'code', format: 'qr', source: 'field', field: 'x' }] } })

		await wrapper.find('[data-testid="code-widget-source"]').setValue('address')
		expect(last(wrapper)).toEqual([{ id: 'c', type: 'code', format: 'qr', source: 'address' }])

		await wrapper.setProps({ modelValue: last(wrapper) })
		expect(wrapper.find('[data-testid="code-widget-field"]').exists()).toBe(false)
	})

	it('removes one code widget and keeps the rest', async () => {
		const wrapper = mount(CodeWidgetBuilder, { props: { modelValue: [NOTES, { id: 'c', type: 'code', format: 'qr', source: 'address' }] } })

		await wrapper.find('.code-widget-builder__remove').trigger('click')

		expect(last(wrapper)).toEqual([NOTES])
	})
})
