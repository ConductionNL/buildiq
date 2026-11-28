/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for AgentsPage.vue row actions and failure paths: selecting an
 * agent and switching its tabs, the new/edit dialog, deleting (including the
 * selected agent), and load/delete failures.
 *
 * Spec: agent-workspace.
 */
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))
vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), delete: vi.fn() },
}))

import axios from '@nextcloud/axios'
import AgentsPage from '../../src/views/AgentsPage.vue'

const stubs = {
	NcButton: {
		name: 'NcButton',
		props: ['variant', 'disabled'],
		emits: ['click'],
		template: '<button :data-variant="variant" :disabled="disabled || false" @click="$emit(\'click\')"><slot /></button>',
	},
	NcSelect: { name: 'NcSelect', props: ['modelValue', 'options', 'loading', 'inputLabel'], emits: ['update:modelValue'], template: '<div class="ncselect-stub" />' },
	NcLoadingIcon: { name: 'NcLoadingIcon', template: '<div class="ncloading-stub" />' },
	NcEmptyContent: { name: 'NcEmptyContent', props: ['name', 'description'], template: '<div class="ncempty-stub">{{ name }}</div>' },
	NcNoteCard: { name: 'NcNoteCard', props: ['type'], template: '<div class="ncnotecard-stub"><slot /></div>' },
	CopilotPanel: { name: 'CopilotPanel', props: ['appSlug', 'agentId', 'name', 'instructions', 'enabledTools'], template: '<div class="copilot-panel-stub" />' },
	AgentRunHistory: { name: 'AgentRunHistory', props: ['agentId'], template: '<div class="agent-run-history-stub" />' },
	AgentEditDialog: { name: 'AgentEditDialog', props: ['open', 'agent', 'applicationSlug'], emits: ['saved', 'update:open'], template: '<div class="edit-dialog-stub" />' },
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const application = { slug: 'tool-library', name: 'Tool Library' }
const reviewer = { id: 'ag-1', name: 'Reviewer', applicationSlug: 'tool-library', instructions: 'Review pages.', enabledTools: ['buildiq.addPage', 'buildiq.listPages'] }
const helper = { id: 'ag-2', name: 'Helper', applicationSlug: 'tool-library' }
const foreign = { id: 'ag-3', name: 'Other', applicationSlug: 'other-app' }

/**
 * Serve applications and agents; either may be an Error.
 *
 * @param {Array|Error} agents The agents listing.
 * @return {void}
 */
function serve(agents = [reviewer, helper, foreign]) {
	axios.get.mockImplementation((url) => {
		if (url.includes('/buildAgent')) {
			return agents instanceof Error ? Promise.reject(agents) : Promise.resolve({ data: { results: agents } })
		}
		return Promise.resolve({ data: { results: [application] } })
	})
}

/**
 * Mount the page with the tool-library app picked.
 *
 * @return {Promise<object>}
 */
async function mountOnApp() {
	const wrapper = mount(AgentsPage, { global: { stubs } })
	await flush()
	wrapper.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', application)
	await flush()
	await flush()
	return wrapper
}

/**
 * A button inside the given element by its text.
 *
 * @param {object} scope The wrapper to search.
 * @param {string} label The button text.
 * @return {object}
 */
function button(scope, label) {
	return scope.findAll('button').find((b) => b.text() === label)
}

describe('AgentsPage — actions and failures', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.delete.mockReset()
		serve()
	})

	it('lists only the picked app’s agents with their tool counts', async () => {
		const wrapper = await mountOnApp()
		const rows = wrapper.findAll('[data-testid="agent-row"]')

		expect(rows.map((r) => r.find('strong').text())).toEqual(['Reviewer', 'Helper'])
		expect(rows.map((r) => r.find('.agents-page__item-meta').text())).toEqual(['%n tools enabled', '%n tools enabled'])
	})

	it('opens an agent on its chat tab and switches to run history and back', async () => {
		const wrapper = await mountOnApp()

		await wrapper.findAll('.agents-page__item-main')[0].trigger('click')
		const chat = wrapper.findComponent({ name: 'CopilotPanel' })
		expect(chat.props()).toEqual({ appSlug: 'tool-library', agentId: 'ag-1', name: 'Reviewer', instructions: 'Review pages.', enabledTools: ['buildiq.addPage', 'buildiq.listPages'] })
		expect(wrapper.findAll('[data-testid="agent-row"]')[0].classes()).toContain('agents-page__item--active')

		const tabs = wrapper.find('.agents-page__detail-tabs')
		await button(tabs, 'Run history').trigger('click')
		expect(wrapper.find('.copilot-panel-stub').exists()).toBe(false)
		expect(wrapper.findComponent({ name: 'AgentRunHistory' }).props('agentId')).toBe('ag-1')
		expect(button(tabs, 'Run history').attributes('data-variant')).toBe('primary')

		await button(tabs, 'Chat').trigger('click')
		expect(wrapper.find('.copilot-panel-stub').exists()).toBe(true)
	})

	it('hands an agent without tools an empty tool list, and selecting resets the tab to chat', async () => {
		const wrapper = await mountOnApp()
		await wrapper.findAll('.agents-page__item-main')[0].trigger('click')
		await button(wrapper.find('.agents-page__detail-tabs'), 'Run history').trigger('click')

		await wrapper.findAll('.agents-page__item-main')[1].trigger('click')

		expect(wrapper.vm.activeTab).toBe('chat')
		expect(wrapper.findComponent({ name: 'CopilotPanel' }).props('enabledTools')).toEqual([])
	})

	it('opens the dialog empty for a new agent, filled for an edit, and reloads after save', async () => {
		const wrapper = await mountOnApp()
		const dialog = () => wrapper.findComponent({ name: 'AgentEditDialog' })

		await button(wrapper, 'New agent').trigger('click')
		expect(dialog().props()).toEqual({ open: true, agent: null, applicationSlug: 'tool-library' })

		await button(wrapper.findAll('[data-testid="agent-row"]')[1], 'Edit').trigger('click')
		expect(dialog().props('agent')).toEqual(helper)

		const loads = axios.get.mock.calls.length
		dialog().vm.$emit('saved')
		await flush()
		expect(wrapper.vm.editDialogOpen).toBe(false)
		expect(axios.get.mock.calls.length).toBe(loads + 1)
	})

	it('deletes the selected agent, clears the selection and reloads', async () => {
		axios.delete.mockResolvedValue({})
		const wrapper = await mountOnApp()
		await wrapper.findAll('.agents-page__item-main')[0].trigger('click')
		serve([helper])

		await button(wrapper.findAll('[data-testid="agent-row"]')[0], 'Delete').trigger('click')
		await flush()
		await flush()

		expect(axios.delete).toHaveBeenCalledWith('/apps/openregister/api/objects/buildiq/buildAgent/ag-1')
		expect(wrapper.vm.selectedAgent).toBeNull()
		expect(wrapper.find('.agents-page__detail').exists()).toBe(false)
		expect(wrapper.findAll('[data-testid="agent-row"]')).toHaveLength(1)
	})

	it('keeps the selection when another agent is deleted', async () => {
		axios.delete.mockResolvedValue({})
		const wrapper = await mountOnApp()
		await wrapper.findAll('.agents-page__item-main')[0].trigger('click')

		await button(wrapper.findAll('[data-testid="agent-row"]')[1], 'Delete').trigger('click')
		await flush()

		expect(axios.delete).toHaveBeenCalledWith('/apps/openregister/api/objects/buildiq/buildAgent/ag-2')
		expect(wrapper.vm.selectedAgent).toEqual(reviewer)
	})

	it('reports a failed delete and keeps the list', async () => {
		axios.delete.mockRejectedValue(new Error('403'))
		const wrapper = await mountOnApp()

		await button(wrapper.findAll('[data-testid="agent-row"]')[0], 'Delete').trigger('click')
		await flush()

		expect(wrapper.find('.ncnotecard-stub').text()).toBe('Could not delete the agent.')
		expect(wrapper.findAll('[data-testid="agent-row"]')).toHaveLength(2)
	})

	it('reports agents that cannot be loaded', async () => {
		serve(new Error('500'))
		const wrapper = await mountOnApp()

		expect(wrapper.vm.loading).toBe(false)
		expect(wrapper.find('.ncnotecard-stub').text()).toBe('Could not load agents.')
	})

	it('clearing the app empties the list without a request', async () => {
		const wrapper = await mountOnApp()
		await wrapper.findAll('.agents-page__item-main')[0].trigger('click')
		const loads = axios.get.mock.calls.length

		wrapper.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', null)
		await flush()

		expect(axios.get.mock.calls.length).toBe(loads)
		expect(wrapper.vm.agents).toEqual([])
		expect(wrapper.vm.selectedAgent).toBeNull()
		expect(wrapper.find('.agents-page__hint').exists()).toBe(true)
	})
})
