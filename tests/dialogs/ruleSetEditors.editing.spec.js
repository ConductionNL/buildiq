/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Editing in the two rule-set editors before a save: the staged copy and its
 * defaults, adding and removing columns, rules and actions, and the decision
 * table's unreachable-rule warning and invalid-cell marking.
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: { post: vi.fn(), put: vi.fn() } }))

import ConditionActionRuleEditor from '../../src/dialogs/ConditionActionRuleEditor.vue'
import DecisionTableEditor from '../../src/dialogs/DecisionTableEditor.vue'

const stubs = {
	NcDialog: { template: '<div><slot /><slot name="actions" /></div>' },
	NcButton: { emits: ['click'], template: '<button @click="$emit(\'click\')"><slot /></button>' },
	NcTextField: { template: '<input class="tf" />' },
	NcTextArea: { template: '<textarea />' },
	NcSelect: { template: '<div />' },
	NcCheckboxRadioSwitch: { template: '<label><slot /></label>' },
	NcNoteCard: { props: ['type'], template: '<div class="note" :data-type="type"><slot /></div>' },
	SaveCancelActions: true,
}

const mountWith = (Editor, ruleSet) => mount(Editor, { props: { ruleSet }, global: { stubs } })

describe('DecisionTableEditor — editing', () => {
	it('stages defaults for a new table', () => {
		const wrapper = mountWith(DecisionTableEditor, {})
		expect(wrapper.vm.staged).toEqual({
			slug: '',
			name: '',
			ruleType: 'decision-table',
			hitPolicy: 'first',
			inputColumns: [],
			rules: [],
		})
	})

	it('stages a deep copy, so edits do not touch the rule set prop', () => {
		const ruleSet = {
			slug: 't',
			name: 'T',
			hitPolicy: 'collect',
			inputColumns: [{ name: 'age', type: 'number', expressionPath: 'age' }],
			rules: [{ conditions: { age: '> 18' }, values: { decision: 'adult' } }],
		}
		const wrapper = mountWith(DecisionTableEditor, ruleSet)

		wrapper.vm.staged.inputColumns[0].name = 'changed'
		wrapper.vm.staged.rules[0].conditions.age = '< 1'

		expect(wrapper.vm.staged.hitPolicy).toBe('collect')
		expect(ruleSet.inputColumns[0].name).toBe('age')
		expect(ruleSet.rules[0].conditions.age).toBe('> 18')
	})

	it('adds and removes input columns', () => {
		const wrapper = mountWith(DecisionTableEditor, {
			inputColumns: [{ name: 'a' }, { name: 'b' }],
		})
		wrapper.vm.addInput()
		expect(wrapper.vm.staged.inputColumns[2]).toEqual({ name: '', type: 'string', expressionPath: '' })

		wrapper.vm.removeInput(0)
		expect(wrapper.vm.staged.inputColumns.map((c) => c.name)).toEqual(['b', ''])
	})

	it('adds an empty catch-all rule', () => {
		const wrapper = mountWith(DecisionTableEditor, {})
		wrapper.vm.addRule()
		expect(wrapper.vm.staged.rules).toEqual([{ conditions: {}, values: { decision: '' }, label: '' }])
	})

	it('warns when a catch-all rule shadows later rules', async () => {
		const wrapper = mountWith(DecisionTableEditor, {
			rules: [{ values: {} }, { conditions: { age: '> 1' }, values: {} }],
		})
		expect(wrapper.vm.warnings).toEqual([
			'A catch-all rule appears before other rules — later rules are unreachable.',
		])
		expect(wrapper.find('.note[data-type="warning"]').exists()).toBe(true)
	})

	it('does not warn about a catch-all rule in last place', () => {
		const wrapper = mountWith(DecisionTableEditor, {
			rules: [{ conditions: { age: '> 1' }, values: {} }, { conditions: {}, values: {} }],
		})
		expect(wrapper.vm.warnings).toEqual([])
		expect(wrapper.find('.note').exists()).toBe(false)
	})

	it('marks an invalid cell condition in the grid', () => {
		const wrapper = mountWith(DecisionTableEditor, {
			inputColumns: [{ name: 'age' }],
			rules: [
				{ conditions: { age: '> 18' }, values: { decision: 'adult' } },
				{ conditions: { age: '=' }, values: { decision: 'x' } },
			],
		})
		const cells = wrapper.findAll('input[aria-label="age"]')
		expect(cells).toHaveLength(2)
		expect(cells[0].classes()).not.toContain('decision-table-editor__cell--invalid')
		expect(cells[1].classes()).toContain('decision-table-editor__cell--invalid')
		expect(wrapper.vm.isCellValid('=')).toBe(false)
		expect(wrapper.vm.isCellValid('> 18')).toBe(true)
	})
})

describe('ConditionActionRuleEditor — editing', () => {
	it('stages defaults for a new rule, active unless switched off', () => {
		expect(mountWith(ConditionActionRuleEditor, {}).vm.staged).toEqual({
			slug: '',
			name: '',
			description: '',
			priority: 0,
			salience: 0,
			condition: '',
			actions: [],
			active: true,
		})
		expect(mountWith(ConditionActionRuleEditor, { active: false }).vm.staged.active).toBe(false)
	})

	it('stages a deep copy of the actions', () => {
		const ruleSet = { actions: [{ type: 'set-field', parameters: { field: 'a' } }] }
		const wrapper = mountWith(ConditionActionRuleEditor, ruleSet)
		wrapper.vm.staged.actions[0].parameters.field = 'b'
		expect(ruleSet.actions[0].parameters.field).toBe('a')
	})

	it('adds a set-field action and removes by index', () => {
		const wrapper = mountWith(ConditionActionRuleEditor, {
			actions: [{ type: 'start-workflow', parameters: {} }],
		})
		wrapper.vm.addAction()
		expect(wrapper.vm.staged.actions[1]).toEqual({ type: 'set-field', parameters: {} })

		wrapper.vm.removeAction(0)
		expect(wrapper.vm.staged.actions).toEqual([{ type: 'set-field', parameters: {} }])
	})
})
