/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for CalculationEditor.vue and ExpressionNodeEditor.vue
 * (change data-calculated-field-authoring, REQ-BQCF-001 to REQ-BQCF-004).
 */

import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const serviceMocks = vi.hoisted(() => ({
	loadOperatorCatalogue: vi.fn(),
	tryCalculation: vi.fn(),
}))

vi.mock('../../../src/services/calculations.js', async (importOriginal) => {
	const actual = await importOriginal()
	return { ...actual, ...serviceMocks }
})

const { default: CalculationEditor } =
	await import('../../../src/components/schema-editor/CalculationEditor.vue')
const {
	addOperand,
	nodeKind,
	nodeForKind,
	parseLiteral,
	removeOperand,
	withOperator,
} = await import('../../../src/components/schema-editor/ExpressionNodeEditor.vue')

const operators = [
	{
		op: 'prop',
		category: 'reference',
		arity: '1',
		operands: ['string'],
		result: 'any',
		description: 'Reads a property.',
	},
	{
		op: '*',
		category: 'arithmetic',
		arity: '1+',
		operands: ['number'],
		result: 'number',
		description: 'Multiplies its operands.',
	},
	{
		op: '/',
		category: 'arithmetic',
		arity: '2',
		operands: ['number', 'number'],
		result: 'number',
		description: 'Divides.',
	},
	{
		op: 'now',
		category: 'date',
		arity: '0',
		operands: [],
		result: 'date',
		description: 'Now.',
	},
]

const stubs = {
	NcButton: {
		name: 'NcButton',
		props: ['disabled', 'ariaLabel'],
		template:
			'<button :disabled="disabled" :aria-label="ariaLabel" @click="$emit(\'click\', $event)"><slot /></button>',
	},
	NcTextField: {
		name: 'NcTextField',
		props: ['modelValue', 'label'],
		template:
			'<input class="tf" :data-label="label" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
	},
	NcSelect: {
		name: 'NcSelect',
		props: ['inputLabel', 'modelValue', 'options'],
		template: '<div class="nc-select-stub" :data-label="inputLabel" />',
	},
	NcNoteCard: {
		name: 'NcNoteCard',
		props: ['type'],
		template: '<div class="note"><slot /></div>',
	},
	ExpressionNodeEditor: {
		name: 'ExpressionNodeEditor',
		props: ['node', 'fieldNames', 'operators'],
		template: '<div class="expr-stub" />',
	},
}

const total = {
	type: 'number',
	expression: { '*': [{ prop: 'quantity' }, { prop: 'unitPrice' }] },
}

async function mountEditor(props = {}) {
	const wrapper = mount(CalculationEditor, {
		props: {
			calculations: null,
			propertyCalculations: {},
			fieldNames: ['quantity', 'unitPrice'],
			refusals: {},
			...props,
		},
		global: { stubs },
	})
	await new Promise((resolve) => setTimeout(resolve, 0))
	return wrapper
}

beforeEach(() => {
	serviceMocks.loadOperatorCatalogue.mockReset()
	serviceMocks.tryCalculation.mockReset()
	serviceMocks.loadOperatorCatalogue.mockResolvedValue({
		operators,
		categories: [],
	})
})

describe('CalculationEditor', () => {
	it('REQ-BQCF-001: adds a calculated field and emits it', async () => {
		const wrapper = await mountEditor()
		wrapper.vm.newName = 'total'
		wrapper.vm.addCalculation()
		const emitted = wrapper.emitted('update:propertyCalculations')
		expect(emitted).toHaveLength(1)
		expect(emitted[0][0]).toEqual({
			total: { type: 'number', expression: null },
		})
		expect(wrapper.vm.newName).toBe('')
	})

	it('REQ-BQCF-001: does not add a name the register file already declares, or one already present', async () => {
		const wrapper = await mountEditor({
			calculations: { age: { type: 'integer', expression: 1 } },
			propertyCalculations: { total },
		})
		wrapper.vm.newName = 'age'
		expect(wrapper.vm.newNameError).not.toBe('')
		wrapper.vm.addCalculation()
		wrapper.vm.newName = 'total'
		wrapper.vm.addCalculation()
		expect(wrapper.emitted('update:propertyCalculations')).toBeUndefined()
	})

	it('REQ-BQCF-001: changes type and expression, and removes', async () => {
		const wrapper = await mountEditor({ propertyCalculations: { total } })
		wrapper.vm.setType('total', 'integer')
		expect(wrapper.emitted('update:propertyCalculations')[0][0].total.type).toBe(
			'integer',
		)
		wrapper.vm.setExpression('total', { prop: 'quantity' })
		expect(
			wrapper.emitted('update:propertyCalculations')[1][0].total.expression,
		).toEqual({ prop: 'quantity' })
		wrapper.vm.removeCalculation('total')
		expect(wrapper.emitted('update:propertyCalculations')[2][0]).toEqual({})
	})

	it('REQ-BQCF-001: lists a register file calculation read-only, without edit controls', async () => {
		const wrapper = await mountEditor({
			calculations: { age: { type: 'integer', expression: 1 } },
		})
		const row = wrapper.find('[data-calculation="age"]')
		expect(row.exists()).toBe(true)
		expect(row.find('button').exists()).toBe(false)
		expect(row.find('.expr-stub').exists()).toBe(false)
	})

	it('REQ-BQCF-002: passes the published operators to the expression builder', async () => {
		const wrapper = await mountEditor({ propertyCalculations: { total } })
		const expr = wrapper.findComponent({ name: 'ExpressionNodeEditor' })
		expect(expr.props('operators').map((o) => o.op)).toEqual([
			'prop',
			'*',
			'/',
			'now',
		])
		expect(expr.props('fieldNames')).toEqual(['quantity', 'unitPrice'])
	})

	it('REQ-BQCF-002: without a catalogue the editor says it cannot edit and offers no add', async () => {
		serviceMocks.loadOperatorCatalogue.mockResolvedValue(null)
		const wrapper = await mountEditor({ propertyCalculations: { total } })
		expect(wrapper.vm.canEdit).toBe(false)
		expect(wrapper.find('.expr-stub').exists()).toBe(false)
	})

	it('REQ-BQCF-003: tries a calculation with the sample values and shows the result', async () => {
		serviceMocks.tryCalculation.mockResolvedValue({
			ok: true,
			value: 8,
			error: null,
		})
		const wrapper = await mountEditor({ propertyCalculations: { total } })
		expect(wrapper.vm.sampleFields('total')).toEqual(['quantity', 'unitPrice'])
		wrapper.vm.setSample('total', 'quantity', '2')
		wrapper.vm.setSample('total', 'unitPrice', '4')
		await wrapper.vm.runTrial('total')
		expect(serviceMocks.tryCalculation).toHaveBeenCalledWith(total, {
			quantity: 2,
			unitPrice: 4,
		})
		await wrapper.vm.$nextTick()
		// The global t() stub does not substitute, so read the shown result off the state.
		expect(wrapper.find('[data-trial="total"]').exists()).toBe(true)
		expect(wrapper.vm.trials.total).toEqual({ ok: true, value: 8, error: null })
		expect(wrapper.emitted('update:propertyCalculations')).toBeUndefined()
	})

	it('REQ-BQCF-003: shows a refused trial', async () => {
		serviceMocks.tryCalculation.mockResolvedValue({
			ok: false,
			value: null,
			error: { code: 'x', message: 'Division by zero.' },
		})
		const wrapper = await mountEditor({ propertyCalculations: { total } })
		await wrapper.vm.runTrial('total')
		await wrapper.vm.$nextTick()
		expect(wrapper.find('[data-trial="total"]').text()).toContain(
			'Not calculated',
		)
		expect(wrapper.vm.trials.total.error.message).toBe('Division by zero.')
	})

	it('REQ-BQCF-004: shows a save refusal next to its calculation', async () => {
		const wrapper = await mountEditor({
			propertyCalculations: { total },
			refusals: {
				total: ['Calculation "total" reads unknown property "qty".'],
			},
		})
		expect(
			wrapper.find('[data-calculation="total"] [role="alert"]').text(),
		).toContain('unknown property')
	})
})

describe('ExpressionNodeEditor helpers', () => {
	it('tells the three node kinds apart', () => {
		expect(nodeKind({ prop: 'a' })).toBe('field')
		expect(nodeKind({ '*': [1, 2] })).toBe('operator')
		expect(nodeKind(3)).toBe('value')
		expect(nodeKind(null)).toBe('value')
	})

	it('builds a node for a kind', () => {
		expect(nodeForKind('field', ['a', 'b'])).toEqual({ prop: 'a' })
		expect(nodeForKind('value')).toBe(0)
	})

	it('sizes the operands of an operator from its arity', () => {
		expect(withOperator({ '*': [1] }, operators[1])).toEqual({ '*': [1, 0] })
		expect(withOperator(null, operators[2])).toEqual({ '/': [0, 0] })
		expect(withOperator(null, operators[3])).toEqual({ now: [] })
	})

	it('adds and removes operands of an n-ary operator', () => {
		expect(addOperand({ '*': [1, 2] })).toEqual({ '*': [1, 2, 0] })
		expect(removeOperand({ '*': [1, 2, 3] }, 1)).toEqual({ '*': [1, 3] })
	})

	it('parses a typed literal', () => {
		expect(parseLiteral('12')).toBe(12)
		expect(parseLiteral('1.5')).toBe(1.5)
		expect(parseLiteral('true')).toBe(true)
		expect(parseLiteral('open')).toBe('open')
		expect(parseLiteral('')).toBe('')
	})
})
