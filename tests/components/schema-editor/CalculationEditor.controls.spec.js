/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for CalculationEditor.vue: the rendered controls of one
 * calculated field — the result-type select, Remove, the expression builder,
 * the sample-value inputs and the new-name input — and what each writes.
 *
 * Spec: data-calculated-field-authoring (REQ-BQCF-001, REQ-BQCF-003).
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

const operators = [
	{ op: 'prop', category: 'reference', arity: '1', operands: ['string'], result: 'any', description: 'Reads.' },
	{ op: '*', category: 'arithmetic', arity: '1+', operands: ['number'], result: 'number', description: 'Multiplies.' },
]

const stubs = {
	NcButton: {
		name: 'NcButton',
		props: ['disabled', 'ariaLabel'],
		emits: ['click'],
		template: '<button :disabled="disabled" :aria-label="ariaLabel" @click="$emit(\'click\', $event)"><slot /></button>',
	},
	NcTextField: {
		name: 'NcTextField',
		props: ['modelValue', 'label', 'error', 'helperText'],
		emits: ['update:modelValue'],
		template: '<input class="tf" :data-label="label" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
	},
	NcSelect: {
		name: 'NcSelect',
		props: ['inputLabel', 'modelValue', 'options', 'clearable'],
		emits: ['update:modelValue'],
		template: '<div class="nc-select-stub" />',
	},
	NcNoteCard: {
		name: 'NcNoteCard',
		props: ['type'],
		template: '<div class="note"><slot /></div>',
	},
	ExpressionNodeEditor: {
		name: 'ExpressionNodeEditor',
		props: ['node', 'fieldNames', 'operators'],
		emits: ['update:node'],
		template: '<div class="expr-stub" />',
	},
}

const total = {
	type: 'number',
	expression: { '*': [{ prop: 'quantity' }, { prop: 'unitPrice' }] },
}

/**
 * Mount the editor and let the catalogue load.
 *
 * @param {object} props - props to override.
 * @return {Promise<object>} The wrapper.
 */
async function mountEditor(props = {}) {
	const wrapper = mount(CalculationEditor, {
		props: {
			calculations: null,
			propertyCalculations: { total },
			fieldNames: ['quantity', 'unitPrice'],
			refusals: {},
			...props,
		},
		global: { stubs },
	})
	await new Promise((resolve) => setTimeout(resolve, 0))
	return wrapper
}

/**
 * The last emitted calculations.
 *
 * @param {object} wrapper - the wrapper.
 * @return {object} The calculations.
 */
function last(wrapper) {
	const emitted = wrapper.emitted('update:propertyCalculations')
	return emitted[emitted.length - 1][0]
}

beforeEach(() => {
	serviceMocks.loadOperatorCatalogue.mockReset()
	serviceMocks.tryCalculation.mockReset()
	serviceMocks.loadOperatorCatalogue.mockResolvedValue({ operators, categories: [] })
})

describe('CalculationEditor controls', () => {
	it('the type select writes the result type and keeps the expression', async () => {
		const wrapper = await mountEditor()

		wrapper.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', 'string')

		expect(last(wrapper)).toEqual({ total: { ...total, type: 'string' } })
	})

	it('the expression builder writes the expression and keeps the type', async () => {
		const wrapper = await mountEditor()
		const expression = { prop: 'quantity' }

		wrapper.findComponent({ name: 'ExpressionNodeEditor' }).vm.$emit('update:node', expression)

		expect(last(wrapper)).toEqual({ total: { type: 'number', expression } })
	})

	it('the Remove button drops the calculation', async () => {
		const wrapper = await mountEditor({ propertyCalculations: { total, other: { type: 'date', expression: null } } })

		const remove = wrapper.find('[data-calculation="total"]').findAll('button').find((b) => b.text() === 'Remove')
		await remove.trigger('click')

		expect(last(wrapper)).toEqual({ other: { type: 'date', expression: null } })
	})

	it('a sample value typed per referenced field is what the trial sends', async () => {
		serviceMocks.tryCalculation.mockResolvedValue({ ok: true, value: 12 })
		const wrapper = await mountEditor()
		const samples = wrapper.findAll('.buildiq-calculation-editor__trial input.tf')

		expect(samples).toHaveLength(2)
		await samples.at(0).setValue('3')
		await samples.at(1).setValue('4')
		expect(samples.at(0).element.value).toBe('3')

		const tryButton = wrapper.findAll('.buildiq-calculation-editor__trial button').at(0)
		await tryButton.trigger('click')
		await new Promise((resolve) => setTimeout(resolve, 0))

		expect(serviceMocks.tryCalculation).toHaveBeenCalledWith(total, { quantity: 3, unitPrice: 4 })
	})

	it('the new-name input explains a name that is not an identifier and keeps Add disabled', async () => {
		const wrapper = await mountEditor()
		const nameInput = wrapper.find('.buildiq-calculation-editor__add input.tf')
		const add = wrapper.find('.buildiq-calculation-editor__add button')

		await nameInput.setValue('2 totals')
		expect(wrapper.vm.newNameError).toBe('Use letters, digits and underscores, starting with a letter.')
		expect(add.attributes('disabled')).toBeDefined()

		await nameInput.setValue('vat')
		expect(add.attributes('disabled')).toBeUndefined()
		await add.trigger('click')
		expect(last(wrapper)).toEqual({ total, vat: { type: 'number', expression: null } })
	})

	it('shows a register-file calculation formatted, and a value that cannot be formatted as empty', async () => {
		const wrapper = await mountEditor({ calculations: { net: { type: 'number', expression: 'x' } } })
		const circular = {}
		circular.self = circular

		expect(wrapper.find('[data-calculation="net"] pre').text()).toBe(
			JSON.stringify({ type: 'number', expression: 'x' }, null, 2),
		)
		expect(wrapper.vm.format('as is')).toBe('as is')
		expect(wrapper.vm.format(circular)).toBe('')
	})
})
