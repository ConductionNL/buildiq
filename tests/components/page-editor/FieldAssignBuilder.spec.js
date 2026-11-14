/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for FieldAssignBuilder.vue, "Fill in from another answer"
 * (REQ-BQFK-004).
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import FieldAssignBuilder from '../../../src/components/page-editor/fields/FieldAssignBuilder.vue'

function mountBuilder(modelValue = null) {
	return mount(FieldAssignBuilder, {
		props: { modelValue, fieldKey: 'currency', fieldOptions: ['country', 'currency', 'amount'] },
	})
}

function lastEmit(wrapper) {
	const all = wrapper.emitted('update:modelValue')
	return all[all.length - 1][0]
}

describe('FieldAssignBuilder', () => {
	it('adds an empty rule', async () => {
		const wrapper = mountBuilder()
		await wrapper.find('[data-testid="assign-add"]').trigger('click')
		expect(lastEmit(wrapper)).toEqual([{ when: { op: 'eq' }, value: '' }])
	})

	it('writes "when country equals NL, set EUR" in the shape nextcloud-vue reads', async () => {
		const wrapper = mountBuilder([{ when: { op: 'eq' }, value: '' }])
		await wrapper.find('[data-testid="assign-when-field"]').setValue('country')
		await wrapper.setProps({ modelValue: lastEmit(wrapper) })
		await wrapper.find('[data-testid="assign-when-value"]').setValue('NL')
		await wrapper.setProps({ modelValue: lastEmit(wrapper) })
		await wrapper.find('[data-testid="assign-value-literal"]').setValue('EUR')
		expect(lastEmit(wrapper)).toEqual([{ when: { field: 'country', op: 'eq', value: 'NL' }, value: 'EUR' }])
	})

	it('offers only the shared visibleWhen operators', () => {
		const wrapper = mountBuilder([{ when: { field: 'country', op: 'eq', value: 'NL' }, value: 'EUR' }])
		const ops = wrapper.find('[data-testid="assign-when-op"]').findAll('option').map((o) => o.element.value)
		expect(ops).toEqual(['eq', 'neq', 'gt', 'gte', 'lt', 'lte'])
	})

	it('copies another answer as @answer.<field>', async () => {
		const wrapper = mountBuilder([{ when: { field: 'country', op: 'eq', value: 'NL' }, value: '' }])
		await wrapper.find('[data-testid="assign-value-kind"]').setValue('answer')
		await wrapper.setProps({ modelValue: lastEmit(wrapper) })
		await wrapper.find('[data-testid="assign-value-answer"]').setValue('amount')
		expect(lastEmit(wrapper)[0].value).toBe('@answer.amount')
	})

	it('sets a default token', async () => {
		const wrapper = mountBuilder([{ when: { field: 'country', op: 'eq', value: 'NL' }, value: '' }])
		await wrapper.find('[data-testid="assign-value-kind"]').setValue('token')
		await wrapper.setProps({ modelValue: lastEmit(wrapper) })
		await wrapper.find('[data-testid="assign-value-token"]').setValue('@today')
		expect(lastEmit(wrapper)[0].value).toBe('@today')
	})

	it('refuses a condition that reads the field itself', async () => {
		const rules = [{ when: { field: 'country', op: 'eq', value: 'NL' }, value: 'EUR' }]
		const wrapper = mountBuilder(rules)
		await wrapper.find('[data-testid="assign-when-field"]').setValue('currency')
		expect(wrapper.emitted('update:modelValue')).toBeUndefined()
		expect(wrapper.find('[data-testid="assign-refused"]').text()).toBe('A field cannot fill itself. Pick another field.')
	})

	it('refuses copying the field itself', async () => {
		const wrapper = mountBuilder([{ when: { field: 'country', op: 'eq', value: 'NL' }, value: '@answer.' }])
		await wrapper.find('[data-testid="assign-value-answer"]').setValue('currency')
		expect(wrapper.emitted('update:modelValue')).toBeUndefined()
		expect(wrapper.find('[data-testid="assign-refused"]').exists()).toBe(true)
	})

	it('emits null when the last rule is removed', async () => {
		const wrapper = mountBuilder([{ when: { field: 'country', op: 'eq', value: 'NL' }, value: 'EUR' }])
		await wrapper.find('.field-assign-builder__remove').trigger('click')
		expect(lastEmit(wrapper)).toBeNull()
	})
})
