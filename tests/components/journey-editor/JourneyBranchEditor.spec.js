/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * JourneyBranchEditor: the operators offered are the canonical schema's
 * visibleWhen enum (compared against the schema, not a list written here),
 * only the fields asked by then are offered, and rules are stored as plain
 * visibleWhen predicates.
 *
 * @spec openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-branch-rules-must-be-authored-only-from-the-shared-operator-set
 */
import manifestSchema from '@conduction/nextcloud-vue/src/schemas/app-manifest-v2.schema.json'
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import JourneyBranchEditor from '../../../src/components/journey-editor/JourneyBranchEditor.vue'

const targets = [{ id: 'b', title: 'Contact' }, { id: 'c', title: 'Check' }]

function mountWith(modelValue, fields = ['name', 'age']) {
	return mount(JourneyBranchEditor, { props: { modelValue, fields, targets } })
}

function lastEmit(wrapper) {
	const emits = wrapper.emitted('update:modelValue')
	return emits[emits.length - 1][0]
}

describe('JourneyBranchEditor', () => {
	it('offers exactly the operators of the canonical visibleWhen schema', () => {
		const wrapper = mountWith([{ when: { field: 'name', op: 'eq', value: 'x' }, goto: 'b' }])
		const offered = wrapper.find('[data-testid="journey-branch-op"]').findAll('option').map((o) => o.element.value)

		expect(offered).toEqual(manifestSchema.$defs.visibleWhen.properties.op.enum)
	})

	it('offers only the fields asked by this step and the steps before it', () => {
		const wrapper = mountWith([{ when: {}, goto: '' }], ['name'])
		const offered = wrapper.find('[data-testid="journey-branch-field"]').findAll('option').map((o) => o.element.value)

		expect(offered).toEqual(['', 'name'])
	})

	it('shows a stored rule and offers the other steps as targets', () => {
		const wrapper = mountWith([{ when: { field: 'age', op: 'gte', value: 18 }, goto: 'c' }])

		expect(wrapper.find('[data-testid="journey-branch-field"]').element.value).toBe('age')
		expect(wrapper.find('[data-testid="journey-branch-op"]').element.value).toBe('gte')
		expect(wrapper.find('[data-testid="journey-branch-value"]').element.value).toBe('18')
		expect(wrapper.find('[data-testid="journey-branch-goto"]').element.value).toBe('c')
		expect(wrapper.find('[data-testid="journey-branch-goto"]').findAll('option').map((o) => o.text())).toEqual(['— step —', 'Contact', 'Check'])
	})

	it('adds an empty eq rule', async () => {
		const wrapper = mountWith([])

		expect(wrapper.find('.journey-branch__empty').exists()).toBe(true)
		await wrapper.find('[data-testid="journey-branch-add"]').trigger('click')
		expect(lastEmit(wrapper)).toEqual([{ when: { field: '', op: 'eq', value: '' }, goto: '' }])
	})

	it('stores an edited rule as a visibleWhen predicate and nothing else', async () => {
		const wrapper = mountWith([{ when: { field: '', op: 'eq', value: '' }, goto: '' }])

		await wrapper.find('[data-testid="journey-branch-field"]').setValue('name')
		expect(lastEmit(wrapper)).toEqual([{ when: { field: 'name', op: 'eq', value: '' }, goto: '' }])

		await wrapper.setProps({ modelValue: lastEmit(wrapper) })
		await wrapper.find('[data-testid="journey-branch-value"]').setValue('Ann')
		await wrapper.setProps({ modelValue: lastEmit(wrapper) })
		await wrapper.find('[data-testid="journey-branch-goto"]').setValue('b')
		expect(lastEmit(wrapper)).toEqual([{ when: { field: 'name', op: 'eq', value: 'Ann' }, goto: 'b' }])
	})

	it('drops the value for an operator that takes none, and hides its input', async () => {
		const wrapper = mountWith([{ when: { field: 'name', op: 'eq', value: 'Ann' }, goto: 'b' }])

		await wrapper.find('[data-testid="journey-branch-op"]').setValue('notEmpty')
		expect(lastEmit(wrapper)).toEqual([{ when: { field: 'name', op: 'notEmpty' }, goto: 'b' }])

		await wrapper.setProps({ modelValue: lastEmit(wrapper) })
		expect(wrapper.find('[data-testid="journey-branch-value"]').exists()).toBe(false)
	})

	it('removes a rule', async () => {
		const wrapper = mountWith([{ when: { field: 'name', op: 'eq', value: 'a' }, goto: 'b' }, { when: { field: 'age', op: 'gt', value: 1 }, goto: 'c' }])

		await wrapper.findAll('[data-testid="journey-branch-remove"]')[0].trigger('click')
		expect(lastEmit(wrapper)).toEqual([{ when: { field: 'age', op: 'gt', value: 1 }, goto: 'c' }])
	})

	it('shows a list value comma separated', () => {
		const wrapper = mountWith([{ when: { field: 'name', op: 'in', value: ['a', 'b'] }, goto: 'b' }])

		expect(wrapper.find('[data-testid="journey-branch-value"]').element.value).toBe('a, b')
	})
})
