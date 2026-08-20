/*
 * SPDX-FileCopyrightText: 2026 OpenBuild Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for VisibleWhenBuilder.vue (REQ-OBFEL-002).
 *
 * Covers: field options exclude the edited field (verified by the caller
 * passing a pre-filtered list), default-eq omission, boolean/number value
 * coercion, clear emits null, advanced endpoint/source passthrough never
 * rewrites.
 */
import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import VisibleWhenBuilder from '../../../src/components/page-editor/fields/VisibleWhenBuilder.vue'

function mountBuilder(modelValue = null, fieldOptions = ['wantsContact', 'phone']) {
	return mount(VisibleWhenBuilder, { propsData: { modelValue, fieldOptions } })
}

describe('VisibleWhenBuilder', () => {
	it('renders "no condition" as the default state', () => {
		const wrapper = mountBuilder(null)
		const select = wrapper.find('.visible-when-builder__select')
		expect(select.element.value).toBe('')
		expect(wrapper.find('.visible-when-builder__value').exists()).toBe(false)
	})

	it('the field picker only offers the field-options prop (caller excludes the edited field)', () => {
		const wrapper = mountBuilder(null, ['wantsContact', 'phone'])
		// VTU v2 returns a plain array from findAll() — the v1 `.wrappers`
		// property no longer exists.
		const options = wrapper.findAll('option').map((o) => o.element.value)
		expect(options).toEqual(['', 'wantsContact', 'phone'])
	})

	it('writes field + boolean value, omitting the default eq op', async () => {
		const wrapper = mountBuilder(null)
		await wrapper.find('.visible-when-builder__select').setValue('wantsContact')
		// A controlled component only re-renders the op/value inputs once the
		// caller passes the emitted value back down as the prop.
		await wrapper.setProps({
			modelValue: wrapper.emitted('update:modelValue')[0][0],
		})
		await wrapper.find('.visible-when-builder__value').setValue('true')
		const last = wrapper.emitted('update:modelValue').at(-1)[0]
		expect(last).toEqual({ field: 'wantsContact', value: true })
	})

	it('coerces a numeric value to a Number for an ordering op', async () => {
		const wrapper = mountBuilder({ field: 'phone', op: 'gt', value: 1 })
		await wrapper.find('.visible-when-builder__value').setValue('3')
		const last = wrapper.emitted('update:modelValue').at(-1)[0]
		expect(last).toEqual({ field: 'phone', op: 'gt', value: 3 })
	})

	it('keeps a non-boolean non-numeric value as a literal string', async () => {
		const wrapper = mountBuilder(null)
		await wrapper.find('.visible-when-builder__select').setValue('wantsContact')
		await wrapper.setProps({
			modelValue: wrapper.emitted('update:modelValue')[0][0],
		})
		await wrapper.find('.visible-when-builder__value').setValue('hello')
		const last = wrapper.emitted('update:modelValue').at(-1)[0]
		expect(last.value).toBe('hello')
	})

	it('selecting "no condition" clears the predicate (emits null)', async () => {
		const wrapper = mountBuilder({ field: 'wantsContact', value: true })
		await wrapper.find('.visible-when-builder__select').setValue('')
		expect(wrapper.emitted('update:modelValue')[0][0]).toBeNull()
	})

	it('the Clear button emits null', async () => {
		const wrapper = mountBuilder({ field: 'wantsContact', value: true })
		await wrapper.find('.visible-when-builder__clear').trigger('click')
		expect(wrapper.emitted('update:modelValue')[0][0]).toBeNull()
	})

	it('renders a read-only summary for an advanced endpoint condition and never rewrites it', async () => {
		const advanced = { endpoint: '/api/status', field: 'ready' }
		const wrapper = mountBuilder(advanced)
		expect(wrapper.text()).toContain('Advanced condition')
		expect(wrapper.find('.visible-when-builder__select').exists()).toBe(false)
		expect(wrapper.find('.visible-when-builder__value').exists()).toBe(false)
		expect(wrapper.emitted('update:modelValue')).toBeUndefined()
	})

	it('renders a read-only summary for an advanced source condition', () => {
		const advanced = { source: { register: 'r', schema: 's' } }
		const wrapper = mountBuilder(advanced)
		expect(wrapper.text()).toContain('Advanced condition')
	})
})
