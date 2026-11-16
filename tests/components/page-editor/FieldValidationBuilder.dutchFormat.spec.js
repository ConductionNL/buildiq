// SPDX-License-Identifier: EUPL-1.2
/**
 * The "Format" select of the form designer's validation block
 * (form-dutch-field-formats T02, REQ-BQDF-001): a Dutch format is stored on
 * the field as `format`, a format disables and drops the pattern, and a
 * format the schema property carries is shown, disabled, and never dropped.
 *
 * @spec openspec/changes/form-dutch-field-formats/specs/form-dutch-field-formats/spec.md#requirement-the-form-designer-offers-dutch-formats-for-a-field-req-bqdf-001
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import FieldValidationBuilder from '../../../src/components/page-editor/fields/FieldValidationBuilder.vue'
import FormFieldBuilder from '../../../src/components/page-editor/fields/FormFieldBuilder.vue'

/**
 * Mount the validation block.
 *
 * @param {object} props The props.
 * @return {object} The wrapper.
 */
function builder(props = {}) {
	return mount(FieldValidationBuilder, { props })
}

describe('FieldValidationBuilder Dutch formats', () => {
	it('offers the eight Dutch formats after "None"', () => {
		const values = builder().findAll('[data-testid="field-validation-format"] option').map((o) => o.element.value)

		expect(values).toEqual(['', 'bsn', 'iban', 'nl-licence-plate', 'phone-nl', 'phone-international', 'postcode', 'kvk', 'kvk-branch'])
	})

	it('stores the chosen format and shows its hint', async () => {
		const wrapper = builder()

		await wrapper.find('[data-testid="field-validation-format"]').setValue('iban')

		expect(wrapper.emitted('update:format')).toEqual([['iban']])
		await wrapper.setProps({ format: 'iban' })
		expect(wrapper.find('[data-testid="field-validation-format-hint"]').text()).toBe('Checks the check digits')
	})

	it('disables the pattern while a format is set, and drops a pattern a format replaces', async () => {
		const wrapper = builder({ modelValue: { required: true, pattern: '^NL' } })

		await wrapper.find('[data-testid="field-validation-format"]').setValue('bsn')

		expect(wrapper.emitted('update:modelValue').at(-1)).toEqual([{ required: true }])
		await wrapper.setProps({ format: 'bsn', modelValue: { required: true } })
		const pattern = wrapper.find('[data-testid="field-validation-pattern"]')
		expect(pattern.attributes('disabled')).toBeDefined()
		expect(pattern.attributes('placeholder')).toBe('The format already checks this field.')

		await pattern.setValue('^x$')
		expect(wrapper.emitted('update:modelValue')).toHaveLength(1)
	})

	it('clears the format with "None"', async () => {
		const wrapper = builder({ format: 'postcode' })

		await wrapper.find('[data-testid="field-validation-format"]').setValue('')

		expect(wrapper.emitted('update:format')).toEqual([['']])
		await wrapper.setProps({ format: '' })
		expect(wrapper.find('[data-testid="field-validation-pattern"]').attributes('disabled')).toBeUndefined()
	})

	it('shows a format from the schema, disabled, and never changes it', async () => {
		const wrapper = builder({ inheritedFormat: 'bsn' })
		const select = wrapper.find('[data-testid="field-validation-format"]')

		expect(select.element.value).toBe('bsn')
		expect(select.attributes('disabled')).toBeDefined()
		expect(wrapper.find('[data-testid="field-validation-format-hint"]').text()).toBe('Comes from the schema')

		wrapper.vm.onFormatChange('iban')
		expect(wrapper.emitted('update:format')).toBeUndefined()
	})

	it('ignores a format that is not a Dutch one', () => {
		const wrapper = builder({ format: 'email' })

		expect(wrapper.find('[data-testid="field-validation-format"]').element.value).toBe('')
		wrapper.vm.onFormatChange('email')
		expect(wrapper.emitted('update:format')).toEqual([['']])
	})
})

describe('FormFieldBuilder and the Dutch format', () => {
	const schemas = [{ slug: 'aanvraag', properties: { bsn: { type: 'string', format: 'bsn' }, naam: { type: 'string' } } }]

	it('reads the format of the bound schema property', () => {
		const ctx = { schemas, parentSchema: 'aanvraag' }

		expect(FormFieldBuilder.methods.inheritedFormat.call(ctx, { key: 'bsn' })).toBe('bsn')
		expect(FormFieldBuilder.methods.inheritedFormat.call(ctx, { key: 'naam' })).toBe('')
		expect(FormFieldBuilder.methods.inheritedFormat.call({ schemas, parentSchema: 'other' }, { key: 'bsn' })).toBe('')
	})

	it('writes the format onto the field, and removes it when cleared', () => {
		const emitted = []
		const ctx = { localFields: [{ key: 'rekening', label: 'Rekening', type: 'text' }], $emit: (...args) => emitted.push(args) }

		FormFieldBuilder.methods.updateField.call(ctx, 0, 'format', 'iban')
		expect(emitted[0]).toEqual(['update:modelValue', [{ key: 'rekening', label: 'Rekening', type: 'text', format: 'iban' }]])

		ctx.localFields = emitted[0][1]
		FormFieldBuilder.methods.updateField.call(ctx, 0, 'format', '')
		expect(emitted[1][1]).toEqual([{ key: 'rekening', label: 'Rekening', type: 'text' }])
	})
})
