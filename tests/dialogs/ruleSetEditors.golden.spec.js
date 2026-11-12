/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pins the save of the two rule-set editors (condition-action rule, decision
 * table): the requests and payloads, the order they go out, the error
 * message and saving flag of a failed save, and the dialog footer (Cancel
 * closes, Save saves, disabled with a "Saving…" label while saving).
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p, params) => p.replace('{slug}', params ? params.slug : '{slug}'),
}))
vi.mock('@nextcloud/axios', () => ({
	default: { post: vi.fn(), put: vi.fn() },
}))

import axios from '@nextcloud/axios'
import ConditionActionRuleEditor from '../../src/dialogs/ConditionActionRuleEditor.vue'
import DecisionTableEditor from '../../src/dialogs/DecisionTableEditor.vue'

const stubs = {
	NcDialog: { template: '<div class="dialog"><slot /><footer><slot name="actions" /></footer></div>' },
	NcButton: {
		props: ['variant', 'disabled'],
		emits: ['click'],
		template: '<button :data-variant="variant" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcTextField: { template: '<input />' },
	NcTextArea: { template: '<textarea />' },
	NcSelect: { template: '<select />' },
	NcCheckboxRadioSwitch: { template: '<label><slot /></label>' },
	NcNoteCard: { props: ['type'], template: '<div class="note" :data-type="type"><slot /></div>' },
}

const EDITORS = [
	['ConditionActionRuleEditor', ConditionActionRuleEditor, { slug: 'r1', name: 'Rule', status: 'active', condition: 'x > 1' }, 'Could not save the rule.'],
	['DecisionTableEditor', DecisionTableEditor, { slug: 'r1', name: 'Table' }, 'Could not save the decision table.'],
]

describe.each(EDITORS)('%s save', (_name, Editor, ruleSet, failure) => {
	beforeEach(() => {
		axios.post.mockReset().mockResolvedValue({ data: {} })
		axios.put.mockReset().mockResolvedValue({ data: {} })
	})

	/**
	 * @return {import('@vue/test-utils').VueWrapper}
	 */
	function mountEditor() {
		return mount(Editor, { props: { ruleSet }, global: { stubs } })
	}

	it('sends the rule set, then its rule or table, and emits saved', async () => {
		const wrapper = mountEditor()
		await wrapper.vm.save()

		expect({ post: axios.post.mock.calls, put: axios.put.mock.calls }).toMatchSnapshot()
		expect(wrapper.emitted('saved')).toHaveLength(1)
		expect(wrapper.vm.saving).toBe(false)
	})

	it('reports a failed save', async () => {
		const wrapper = mountEditor()
		axios.post.mockRejectedValueOnce(new Error('boom'))
		await wrapper.vm.save()
		await flushPromises()

		expect(wrapper.vm.errorMessage).toBe(failure)
		expect(wrapper.vm.saving).toBe(false)
		expect(wrapper.emitted('saved')).toBeUndefined()
		expect(wrapper.find('.note[data-type="error"]').text()).toBe(failure)
	})

	it('renders the footer and wires Cancel and Save', async () => {
		const wrapper = mountEditor()
		const footer = () => wrapper.find('footer').html().replace(/ data-v-[0-9a-f]+=""/g, '')
		const idle = footer()
		await wrapper.setData({ saving: true })
		const busy = footer()
		await wrapper.setData({ saving: false })

		const buttons = wrapper.findAll('footer button')
		await buttons[0].trigger('click')
		await buttons[1].trigger('click')
		await flushPromises()

		expect({ idle, busy }).toMatchSnapshot()
		expect(wrapper.emitted('close')).toHaveLength(1)
		expect(axios.post).toHaveBeenCalled()
	})
})
