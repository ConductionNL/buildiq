import { mount } from '@vue/test-utils'
/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for FieldValidationBuilder.vue (REQ-OBFEL-003).
 *
 * Covers: structured write, legacy flat prefill (display only — actual
 * per-field normalisation/removal of flat keys is FormFieldBuilder's
 * responsibility, covered in FormFieldBuilder.logic.spec.js), non-compiling
 * pattern marked invalid and never emitted.
 */
import { describe, expect, it } from 'vitest'
import FieldValidationBuilder from '../../../src/components/page-editor/fields/FieldValidationBuilder.vue'

function mountBuilder(propsData = {}) {
	return mount(FieldValidationBuilder, { propsData })
}

describe('FieldValidationBuilder', () => {
	it('writes the structured validation object', async () => {
		const wrapper = mountBuilder()
		wrapper.find('input[type="checkbox"]').setChecked(true)
		await wrapper.vm.$nextTick()
		let last = wrapper.emitted('update:modelValue').at(-1)[0]
		expect(last).toEqual({ required: true })

		await wrapper.setProps({ modelValue: last })
		await wrapper.find('input[placeholder="Min"]').setValue('5')
		await wrapper.setProps({
			modelValue: wrapper.emitted('update:modelValue').at(-1)[0],
		})
		await wrapper.find('input[placeholder="Max"]').setValue('254')
		await wrapper.setProps({
			modelValue: wrapper.emitted('update:modelValue').at(-1)[0],
		})
		await wrapper
			.find('input[placeholder="Pattern (regex)"]')
			.setValue('^[^@]+@[^@]+$')
		await wrapper.setProps({
			modelValue: wrapper.emitted('update:modelValue').at(-1)[0],
		})
		await wrapper
			.find('input[placeholder="Custom message (i18n key)"]')
			.setValue('i18n.email-invalid')

		last = wrapper.emitted('update:modelValue').at(-1)[0]
		expect(last).toEqual({
			required: true,
			min: 5,
			max: 254,
			pattern: '^[^@]+@[^@]+$',
			message: 'i18n.email-invalid',
		})
	})

	it('prefills from an existing validation object', () => {
		const wrapper = mountBuilder({
			modelValue: { required: true, pattern: '^\\d+$' },
		})
		expect(wrapper.find('input[type="checkbox"]').element.checked).toBe(true)
		expect(
			wrapper.find('input[placeholder="Pattern (regex)"]').element.value,
		).toBe('^\\d+$')
	})

	it('prefills from legacy flat keys when no validation object exists yet', () => {
		const wrapper = mountBuilder({
			legacyRequired: true,
			legacyPattern: '^\\d+$',
		})
		expect(wrapper.find('input[type="checkbox"]').element.checked).toBe(true)
		expect(
			wrapper.find('input[placeholder="Pattern (regex)"]').element.value,
		).toBe('^\\d+$')
		// A component that is never interacted with must never emit anything —
		// the field it prefills from stays untouched (Decision 4).
		expect(wrapper.emitted('update:modelValue')).toBeUndefined()
	})

	it('a non-compiling pattern is marked invalid and never emitted', async () => {
		const wrapper = mountBuilder()
		await wrapper.find('input[placeholder="Pattern (regex)"]').setValue('[a-')
		expect(
			wrapper.find('.field-validation-builder__pattern-error').exists(),
		).toBe(true)
		expect(wrapper.emitted('update:modelValue')).toBeUndefined()
	})

	it('clearing every rule emits null', async () => {
		const wrapper = mountBuilder({ modelValue: { required: true } })
		await wrapper.find('input[type="checkbox"]').setChecked(false)
		expect(wrapper.emitted('update:modelValue')[0][0]).toBeNull()
	})
})
