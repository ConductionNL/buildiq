/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for AgentsPage.vue.
 *
 * Spec: agent-workspace ("Agents page provides CRUD and a per-agent chat panel").
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn(), delete: vi.fn() } }))

import axios from '@nextcloud/axios'
import AgentsPage from '../../src/views/AgentsPage.vue'

const NcButtonStub = {
	name: 'NcButton',
	props: ['type', 'disabled'],
	template: '<button :disabled="disabled || false" @click="$emit(\'click\')"><slot /></button>',
}
const NcSelectStub = {
	name: 'NcSelect',
	props: ['value', 'options', 'loading', 'inputLabel'],
	template: '<div class="ncselect-stub" :data-label="inputLabel" />',
}
const NcLoadingIconStub = { name: 'NcLoadingIcon', template: '<div class="ncloading-stub" />' }
const NcEmptyContentStub = {
	name: 'NcEmptyContent',
	props: ['name', 'description'],
	template: '<div class="ncempty-stub">{{ name }}</div>',
}
const NcNoteCardStub = { name: 'NcNoteCard', props: ['type'], template: '<div class="ncnotecard-stub"><slot /></div>' }

const stubs = {
	NcButton: NcButtonStub,
	NcSelect: NcSelectStub,
	NcLoadingIcon: NcLoadingIconStub,
	NcEmptyContent: NcEmptyContentStub,
	NcNoteCard: NcNoteCardStub,
	CopilotPanel: { name: 'CopilotPanel', props: ['appSlug', 'agentId', 'name', 'instructions', 'enabledTools'], template: '<div class="copilot-panel-stub" />' },
	AgentRunHistory: { name: 'AgentRunHistory', props: ['agentId'], template: '<div class="agent-run-history-stub" />' },
	AgentEditDialog: { name: 'AgentEditDialog', props: ['open', 'agent', 'applicationSlug'], template: '<div class="edit-dialog-stub" />' },
}

const flush = () => new Promise((r) => setTimeout(r, 0))

const application = { slug: 'tool-library', name: 'Tool Library' }

const agent = (overrides = {}) => ({
	id: 'agent-1',
	name: 'Page builder assistant',
	applicationSlug: 'tool-library',
	enabledTools: ['openbuild.upsertPage', 'openbuild.addWidget'],
	maxActionsPerRun: 5,
	...overrides,
})

describe('AgentsPage', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.post.mockReset()
		axios.delete.mockReset()
		axios.get.mockImplementation((url) => {
			if (url.includes('/api/applications')) {
				return Promise.resolve({ data: { results: [application] } })
			}
			if (url.includes('/agent')) {
				return Promise.resolve({ data: { results: [agent()] } })
			}
			return Promise.resolve({ data: { results: [] } })
		})
	})

	it('renders each agent for the selected application', async () => {
		const wrapper = mount(AgentsPage, { stubs })
		await flush()
		wrapper.vm.selectedApp = application
		wrapper.vm.onAppChange()
		await flush()
		await flush()

		const rows = wrapper.findAll('[data-testid="agent-row"]')
		expect(rows).toHaveLength(1)
		expect(rows.at(0).text()).toContain('Page builder assistant')
	})

	it('empty state renders without error for an application with no agents', async () => {
		axios.get.mockImplementation((url) => {
			if (url.includes('/api/applications')) {
				return Promise.resolve({ data: { results: [application] } })
			}
			return Promise.resolve({ data: { results: [] } })
		})

		const wrapper = mount(AgentsPage, { stubs })
		await flush()
		wrapper.vm.selectedApp = application
		wrapper.vm.onAppChange()
		await flush()
		await flush()

		expect(wrapper.find('.ncempty-stub').exists()).toBe(true)
	})

	it('selecting an agent shows the chat panel scoped to that agent', async () => {
		const wrapper = mount(AgentsPage, { stubs })
		await flush()
		wrapper.vm.selectedApp = application
		wrapper.vm.onAppChange()
		await flush()
		await flush()

		wrapper.vm.selectAgent(agent())
		await flush()

		const panel = wrapper.findComponent({ name: 'CopilotPanel' })
		expect(panel.exists()).toBe(true)
		expect(panel.props('agentId')).toBe('agent-1')
		expect(panel.props('appSlug')).toBe('tool-library')
	})

	it('switching to the history tab shows AgentRunHistory scoped to that agent', async () => {
		const wrapper = mount(AgentsPage, { stubs })
		await flush()
		wrapper.vm.selectedApp = application
		wrapper.vm.onAppChange()
		await flush()
		await flush()

		wrapper.vm.selectAgent(agent())
		wrapper.vm.activeTab = 'history'
		await flush()

		const history = wrapper.findComponent({ name: 'AgentRunHistory' })
		expect(history.exists()).toBe(true)
		expect(history.props('agentId')).toBe('agent-1')
	})

	it('deleting an agent calls the generic OpenRegister delete endpoint and refreshes the list', async () => {
		axios.delete.mockResolvedValueOnce({ data: {} })
		const wrapper = mount(AgentsPage, { stubs })
		await flush()
		wrapper.vm.selectedApp = application
		wrapper.vm.onAppChange()
		await flush()
		await flush()

		await wrapper.vm.remove(agent())
		await flush()

		expect(axios.delete).toHaveBeenCalledWith('/apps/openregister/api/objects/openbuild/agent/agent-1')
	})

	it('the "New agent" button is disabled until an application is selected', async () => {
		const wrapper = mount(AgentsPage, { stubs })
		await flush()

		const newButton = wrapper.findAll('button').filter((b) => b.text().includes('New agent')).at(0)
		expect(newButton.attributes('disabled')).toBeTruthy()
	})
})
