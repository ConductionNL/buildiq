// SPDX-License-Identifier: EUPL-1.2
/**
 * FormFieldBuilder — the "Product list" field (forms-multi-product-request
 * T03, D4): the sub-objects widget with a product column, detail columns and a
 * maximum number of rows of at most 25.
 *
 * @spec openspec/changes/forms-multi-product-request/specs/journey-designer/spec.md#requirement-the-parts-can-hang-under-one-bundle-req-bqmp-003
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import FormFieldBuilder from '../../../src/components/page-editor/fields/FormFieldBuilder.vue'
import { productListField } from '../../../src/components/page-editor/fields/fieldTypes.js'

function lastEmit(wrapper) {
	const emits = wrapper.emitted('update:modelValue')
	return emits[emits.length - 1][0]
}

describe('FormFieldBuilder product list', () => {
	it('adds a product list from the palette as the sub-objects widget', async () => {
		const wrapper = mount(FormFieldBuilder, { propsData: { modelValue: [], showLogic: true } })

		await wrapper.find('[data-testid="palette-product-list"]').trigger('click')

		expect(lastEmit(wrapper)).toEqual([{
			key: '',
			label: '',
			type: 'product-list',
			widget: 'sub-objects',
			columns: [{ key: 'product', label: 'Product', options: [] }],
			maxItems: 10,
		}])
	})

	it('sets the products, the details and the most rows', async () => {
		const field = productListField({ key: 'producten', label: 'Producten' })
		const wrapper = mount(FormFieldBuilder, { propsData: { modelValue: [field], showLogic: true } })
		await wrapper.find('.form-field-builder__disclosure').trigger('click')

		await wrapper.find('[data-testid="product-list-products"]').setValue('parkeervergunning, afvalcontainer,')
		expect(lastEmit(wrapper)[0].columns[0].options).toEqual(['parkeervergunning', 'afvalcontainer'])

		await wrapper.setProps({ modelValue: [lastEmit(wrapper)[0]] })
		await wrapper.find('[data-testid="product-list-details"]').setValue('toelichting, kenteken')
		const withDetails = lastEmit(wrapper)[0]
		expect(withDetails.columns.map((col) => col.key)).toEqual(['product', 'toelichting', 'kenteken'])
		expect(withDetails.columns[0].options).toEqual(['parkeervergunning', 'afvalcontainer'])

		await wrapper.setProps({ modelValue: [withDetails] })
		await wrapper.find('[data-testid="product-list-max-items"]').setValue('40')
		expect(lastEmit(wrapper)[0].maxItems).toBe(25)
	})

	it('keeps the most rows between 1 and 25', () => {
		expect(productListField({}, { maxItems: 0 }).maxItems).toBe(1)
		expect(productListField({}, { maxItems: 7 }).maxItems).toBe(7)
		expect(productListField({}, { maxItems: 'many' }).maxItems).toBe(10)
		expect(productListField({ maxItems: 12 }).maxItems).toBe(12)
	})
})
