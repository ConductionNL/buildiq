/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The two rule-set editors driven through their templates: every field the
 * user types into writes the staged rule (numbers as numbers), the remove
 * buttons drop the row they sit on, the decision-table cells write their
 * condition and decision and mark an invalid condition, and closing or
 * cancelling the dialog emits `close`.
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: { post: vi.fn(), put: vi.fn() } }))

import ConditionActionRuleEditor from '../../src/dialogs/ConditionActionRuleEditor.vue'
import DecisionTableEditor from '../../src/dialogs/DecisionTableEditor.vue'

// Stubs that behave like the Nextcloud inputs: they show their value and
// emit `update:modelValue` when the user changes them.
const stubs = {
	NcDialog: {
		emits: ['closing'],
		template: '<div><button class="dialog-close" @click="$emit(\'closing\')" /><slot /><slot name="actions" /></div>',
	},
	NcButton: { emits: ['click'], template: '<button class="nc-button" @click="$emit(\'click\')"><slot /></button>' },
	NcTextField: {
		props: ['modelValue', 'label', 'type'],
		emits: ['update:modelValue'],
		template: '<input :data-label="label" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
	},
	NcTextArea: {
		props: ['modelValue', 'label'],
		emits: ['update:modelValue'],
		template: '<textarea :data-label="label" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
	},
	NcSelect: {
		props: ['modelValue', 'inputLabel', 'options'],
		emits: ['update:modelValue'],
		template: '<select :data-label="inputLabel" @change="$emit(\'update:modelValue\', $event.target.value)"><option v-for="o in options" :key="o" :value="o">{{ o }}</option></select>',
	},
	NcCheckboxRadioSwitch: {
		props: ['modelValue'],
		emits: ['update:modelValue'],
		template: '<input type="checkbox" class="switch" :checked="modelValue" @change="$emit(\'update:modelValue\', $event.target.checked)" />',
	},
	NcNoteCard: { props: ['type'], template: '<div class="note" :data-type="type"><slot /></div>' },
	SaveCancelActions: {
		props: ['saving'],
		emits: ['cancel', 'save'],
		template: '<div><button class="cancel" @click="$emit(\'cancel\')" /><button class="save" @click="$emit(\'save\')" /></div>',
	},
}

const mountWith = (Editor, ruleSet) => mount(Editor, { props: { ruleSet }, global: { stubs } })
const field = (wrapper, label) => wrapper.find(`[data-label="${label}"]`)

describe('ConditionActionRuleEditor — template', () => {
	it('writes every field the user changes into the staged rule', async () => {
		const wrapper = mountWith(ConditionActionRuleEditor, { slug: 'r1', actions: [{ type: 'set-field', parameters: {} }] })

		await field(wrapper, 'Rule name').setValue('Escalate overdue')
		await field(wrapper, 'Description').setValue('After 30 days')
		await field(wrapper, 'Priority').setValue('7')
		await field(wrapper, 'Salience').setValue('3')
		await field(wrapper, 'Condition (FEEL)').setValue('daysOpen > 30')
		await field(wrapper, 'Action type').setValue('start-workflow')
		await wrapper.find('.switch').setValue(false)

		expect(wrapper.vm.staged).toMatchObject({
			name: 'Escalate overdue',
			description: 'After 30 days',
			priority: 7,
			salience: 3,
			condition: 'daysOpen > 30',
			actions: [{ type: 'start-workflow', parameters: {} }],
			active: false,
		})
	})

	it('adds an action, and Remove drops the row it sits on', async () => {
		const wrapper = mountWith(ConditionActionRuleEditor, {
			actions: [{ type: 'set-field' }, { type: 'send-notification' }],
		})
		const buttons = () => wrapper.findAll('.nc-button')

		await buttons().find((b) => b.text() === 'Add action').trigger('click')
		expect(wrapper.vm.staged.actions.map((a) => a.type)).toEqual(['set-field', 'send-notification', 'set-field'])

		await buttons().filter((b) => b.text() === 'Remove')[1].trigger('click')
		expect(wrapper.vm.staged.actions.map((a) => a.type)).toEqual(['set-field', 'set-field'])
	})

	it('emits close when the dialog closes or the user cancels', async () => {
		const wrapper = mountWith(ConditionActionRuleEditor, {})
		await wrapper.find('.dialog-close').trigger('click')
		await wrapper.find('.cancel').trigger('click')
		expect(wrapper.emitted('close')).toHaveLength(2)
	})
})

describe('DecisionTableEditor — template', () => {
	const table = () => ({
		slug: 't',
		inputColumns: [{ name: 'age', type: 'number', expressionPath: 'age' }],
		rules: [{ conditions: { age: '> 18' }, values: { decision: 'adult' } }],
	})

	it('writes the name, hit policy and column fields', async () => {
		const wrapper = mountWith(DecisionTableEditor, table())

		await field(wrapper, 'Rule set name').setValue('Age check')
		await field(wrapper, 'Hit policy').setValue('collect')
		await field(wrapper, 'Name').setValue('leeftijd')
		await field(wrapper, 'Payload path').setValue('person.age')

		expect(wrapper.vm.staged.name).toBe('Age check')
		expect(wrapper.vm.staged.hitPolicy).toBe('collect')
		expect(wrapper.vm.staged.inputColumns[0]).toEqual({ name: 'leeftijd', type: 'number', expressionPath: 'person.age' })
	})

	it('writes a cell condition and the decision, and marks an invalid condition', async () => {
		const wrapper = mountWith(DecisionTableEditor, table())
		const cell = wrapper.find('input[aria-label="age"]')
		const decision = wrapper.find('input[aria-label="Decision"]')

		await cell.setValue('>= 65')
		await decision.setValue('senior')
		expect(wrapper.vm.staged.rules[0]).toEqual({ conditions: { age: '>= 65' }, values: { decision: 'senior' } })
		expect(cell.classes()).not.toContain('decision-table-editor__cell--invalid')

		await cell.setValue('=')
		expect(cell.classes()).toContain('decision-table-editor__cell--invalid')
	})

	it('Remove drops its column, Add rule adds a row, and closing emits close', async () => {
		const wrapper = mountWith(DecisionTableEditor, {
			inputColumns: [{ name: 'a' }, { name: 'b' }],
		})
		const buttons = () => wrapper.findAll('.nc-button')

		await buttons().filter((b) => b.text() === 'Remove')[0].trigger('click')
		expect(wrapper.vm.staged.inputColumns.map((c) => c.name)).toEqual(['b'])

		await buttons().find((b) => b.text() === 'Add input column').trigger('click')
		await buttons().find((b) => b.text() === 'Add rule').trigger('click')
		expect(wrapper.vm.staged.inputColumns).toHaveLength(2)
		expect(wrapper.findAll('tbody tr')).toHaveLength(1)

		await wrapper.find('.dialog-close').trigger('click')
		await wrapper.find('.cancel').trigger('click')
		expect(wrapper.emitted('close')).toHaveLength(2)
	})
})
