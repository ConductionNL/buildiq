/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Ask AI in the two rule-set editors (ai-copilot-documents-and-code-help
 * REQ-BQCH-001/004): the condition field and each decision row carry the
 * button, scoped to the rule set's owner app with names only; an accepted
 * value is staged and nothing is saved until Save.
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: { post: vi.fn(), put: vi.fn(), get: vi.fn() } }))

import axios from '@nextcloud/axios'
import ConditionActionRuleEditor from '../../src/dialogs/ConditionActionRuleEditor.vue'
import DecisionTableEditor from '../../src/dialogs/DecisionTableEditor.vue'

const AssistStub = {
	name: 'CopilotAssistButton',
	props: ['target', 'appSlug', 'current', 'context'],
	emits: ['accept'],
	template: '<span class="assist-stub" />',
}

const stubs = {
	NcDialog: { template: '<div><slot /><slot name="actions" /></div>' },
	NcButton: { emits: ['click'], template: '<button @click="$emit(\'click\')"><slot /></button>' },
	NcTextField: { template: '<input class="tf" />' },
	NcTextArea: { template: '<textarea />' },
	NcSelect: { template: '<div />' },
	NcCheckboxRadioSwitch: { template: '<label><slot /></label>' },
	NcNoteCard: { template: '<div><slot /></div>' },
	SaveCancelActions: true,
	CopilotAssistButton: AssistStub,
}

const mountWith = (Editor, ruleSet) => mount(Editor, { props: { ruleSet }, global: { stubs } })

describe('ConditionActionRuleEditor — Ask AI', () => {
	it('sits beside the condition, scoped to the owner app, and stages an accepted condition', async () => {
		const wrapper = mountWith(ConditionActionRuleEditor, { ownerApp: 'permits', condition: 'applicant.age > 1' })
		const button = wrapper.findComponent(AssistStub)

		expect(button.props()).toMatchObject({ target: 'feel-condition', appSlug: 'permits', current: 'applicant.age > 1' })

		button.vm.$emit('accept', 'applicant.age < 18')
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.staged.condition).toBe('applicant.age < 18')
		expect(axios.post).not.toHaveBeenCalled()
		expect(axios.put).not.toHaveBeenCalled()
	})

	it('has no app scope for a rule set without an owner app', () => {
		const wrapper = mountWith(ConditionActionRuleEditor, {})
		expect(wrapper.findComponent(AssistStub).props('appSlug')).toBe('')
	})
})

describe('DecisionTableEditor — Ask AI', () => {
	const ruleSet = () => ({
		ownerApp: 'permits',
		inputColumns: [
			{ name: 'age', type: 'number', expressionPath: 'applicant.age' },
			{ name: ' ', type: 'string', expressionPath: '' },
			{ name: 'country', type: 'string', expressionPath: 'applicant.country' },
		],
		rules: [{ conditions: { age: '>= 18', country: "in ('NL', 'BE')" }, values: { decision: 'approve' }, label: 'adults' }],
	})

	it('gives each row and the add-rule action a button with the column names and paths', () => {
		const wrapper = mountWith(DecisionTableEditor, ruleSet())
		const buttons = wrapper.findAllComponents(AssistStub)

		expect(buttons).toHaveLength(2)
		for (const button of buttons) {
			expect(button.props('target')).toBe('decision-rule')
			expect(button.props('appSlug')).toBe('permits')
			expect(button.props('context')).toEqual({ columns: ['age', 'country'], paths: ['applicant.age', 'applicant.country'] })
		}
		expect(buttons[0].props('current')).toEqual({ conditions: { age: '>= 18', country: "in ('NL', 'BE')" }, decision: 'approve' })
	})

	it('replaces a row with an accepted proposal, keeping its label', async () => {
		const wrapper = mountWith(DecisionTableEditor, ruleSet())

		wrapper.findAllComponents(AssistStub)[0].vm.$emit('accept', { conditions: { age: '>= 21' }, decision: 'review' })
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.staged.rules).toEqual([{ conditions: { age: '>= 21' }, values: { decision: 'review' }, label: 'adults' }])
	})

	it('appends a proposed row and saves nothing', async () => {
		const wrapper = mountWith(DecisionTableEditor, ruleSet())

		wrapper.findAllComponents(AssistStub)[1].vm.$emit('accept', { conditions: { age: '< 18' }, decision: 'reject' })
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.staged.rules).toHaveLength(2)
		expect(wrapper.vm.staged.rules[1]).toEqual({ conditions: { age: '< 18' }, values: { decision: 'reject' }, label: '' })
		expect(axios.post).not.toHaveBeenCalled()
		expect(axios.put).not.toHaveBeenCalled()
	})
})
