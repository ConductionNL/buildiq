/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for the ExpressionNodeEditor component itself (its helper
 * functions are covered in CalculationEditor.spec.js): which control a node
 * kind shows, what a kind, field, value or operator pick emits, how nested
 * operands are edited, and when operands can be added or removed.
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import ExpressionNodeEditor from '../../../src/components/schema-editor/ExpressionNodeEditor.vue'

const operators = [
	{ op: 'prop', category: 'data', arity: '1', description: 'Reads a property.' },
	{ op: 'lit', category: 'data', arity: '1', description: 'A literal.' },
	{ op: '*', category: 'arithmetic', arity: '1+', description: 'Multiplies its operands.' },
	{ op: '/', category: 'arithmetic', arity: '2', description: 'Divides.' },
]

const stubs = {
	NcButton: {
		name: 'NcButton',
		props: ['ariaLabel'],
		emits: ['click'],
		template: '<button class="btn" :aria-label="ariaLabel" @click="$emit(\'click\', $event)"><slot /></button>',
	},
	NcTextField: {
		name: 'NcTextField',
		props: ['modelValue', 'label'],
		template: '<input class="tf" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
	},
	NcSelect: {
		name: 'NcSelect',
		props: ['inputLabel', 'modelValue', 'options'],
		template: '<div class="sel" :data-label="inputLabel" />',
	},
}

function mountNode(node, extra = {}) {
	return mount(ExpressionNodeEditor, {
		props: { node, fieldNames: ['quantity', 'unitPrice'], operators, ...extra },
		global: { stubs },
	})
}

// The NcSelect stubs of this node only, not of its nested operands.
function ownSelects(wrapper) {
	return wrapper.findAllComponents({ name: 'NcSelect' })
		.filter((s) => s.element.parentElement.parentElement === wrapper.element)
}

function select(wrapper, label) {
	return ownSelects(wrapper).find((s) => s.props('inputLabel') === label)
}

describe('ExpressionNodeEditor', () => {
	it('shows a field select for a field node, with the field picked', () => {
		const wrapper = mountNode({ prop: 'unitPrice' })

		expect(select(wrapper, 'Part').props('modelValue').value).toBe('field')
		expect(select(wrapper, 'Field').props('modelValue')).toBe('unitPrice')
		expect(select(wrapper, 'Field').props('options')).toEqual(['quantity', 'unitPrice'])
		expect(wrapper.find('.tf').exists()).toBe(false)
	})

	it('reads a field given in the array form', () => {
		expect(select(mountNode({ prop: ['quantity'] }), 'Field').props('modelValue')).toBe('quantity')
	})

	it('shows a value field with the literal as text, and emits the parsed literal', async () => {
		const wrapper = mountNode(12)

		expect(wrapper.find('.tf').element.value).toBe('12')
		await wrapper.find('.tf').setValue('true')
		await wrapper.find('.tf').setValue('open')

		expect(wrapper.emitted('update:node')).toEqual([[true], ['open']])
	})

	it('shows an empty value field for a null node', () => {
		expect(mountNode(null).find('.tf').element.value).toBe('')
	})

	it('emits the new node for a picked kind', async () => {
		const wrapper = mountNode(3)

		select(wrapper, 'Part').vm.$emit('update:modelValue', { value: 'field' })
		select(wrapper, 'Part').vm.$emit('update:modelValue', { value: 'value' })

		expect(wrapper.emitted('update:node')).toEqual([[{ prop: 'quantity' }], [0]])
	})

	it('picking the operator kind shows the operator select before one is chosen', async () => {
		const wrapper = mountNode(null)

		select(wrapper, 'Part').vm.$emit('update:modelValue', { value: 'operator' })
		expect(wrapper.emitted('update:node')).toEqual([[null]])
		await wrapper.setProps({ node: null })

		expect(select(wrapper, 'Operator').exists()).toBe(true)
		expect(select(wrapper, 'Operator').props('options').map((o) => o.label)).toEqual(['* (arithmetic)', '/ (arithmetic)'])
	})

	it('emits the operator sized to its arity, keeping operands it already had', () => {
		const wrapper = mountNode({ '*': [{ prop: 'quantity' }, 2, 3] })

		select(wrapper, 'Operator').vm.$emit('update:modelValue', operators[3])
		select(wrapper, 'Operator').vm.$emit('update:modelValue', null)

		expect(wrapper.emitted('update:node')).toEqual([[{ '/': [{ prop: 'quantity' }, 2] }]])
	})

	it('shows the operator description and one nested editor per operand', () => {
		const wrapper = mountNode({ '/': [{ prop: 'quantity' }, 4] })

		expect(wrapper.find('.buildiq-expr-node__hint').text()).toBe('Divides.')
		const nested = wrapper.findAllComponents(ExpressionNodeEditor).filter((c) => c.vm !== wrapper.vm)
		expect(nested.map((c) => c.props('node'))).toEqual([{ prop: 'quantity' }, 4])
		expect(nested.map((c) => c.props('depth'))).toEqual([1, 1])
		expect(nested[0].classes()).toContain('buildiq-expr-node--nested')
	})

	it('writes a nested operand back into its place', () => {
		const wrapper = mountNode({ '/': [{ prop: 'quantity' }, 4] })
		const nested = wrapper.findAllComponents(ExpressionNodeEditor).filter((c) => c.vm !== wrapper.vm)

		nested[1].vm.$emit('update:node', 8)

		expect(wrapper.emitted('update:node')).toEqual([[{ '/': [{ prop: 'quantity' }, 8] }]])
	})

	it('offers add and remove only for an open arity above its minimum', async () => {
		const fixed = mountNode({ '/': [1, 2] })
		expect(fixed.findAll('.btn')).toHaveLength(0)

		const open = mountNode({ '*': [1, 2] })
		const buttons = open.findAll('.btn')
		expect(buttons.map((b) => b.text())).toEqual(['Remove', 'Remove', 'Add operand'])

		await buttons[0].trigger('click')
		await buttons[2].trigger('click')
		expect(open.emitted('update:node')).toEqual([[{ '*': [2] }], [{ '*': [1, 2, 0] }]])

		const atMinimum = mountNode({ '*': [1] })
		expect(atMinimum.findAll('.btn').map((b) => b.text())).toEqual(['Add operand'])
	})

	it('treats an operator the catalogue does not list as having no hint and no buttons', () => {
		const wrapper = mountNode({ max: [1, 2] })

		expect(wrapper.find('.buildiq-expr-node__hint').exists()).toBe(false)
		expect(select(wrapper, 'Operator').props('modelValue')).toBeNull()
		expect(wrapper.findAll('.btn')).toHaveLength(0)
	})
})
