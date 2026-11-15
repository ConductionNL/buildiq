import { mount } from '@vue/test-utils'
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for AgentEditDialog.vue.
 *
 * Spec: agent-workspace ("Agent entity declares a named, tool-scoped configuration").
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))
vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))
vi.mock('@nextcloud/auth', () => ({
	getCurrentUser: () => ({ uid: 'alice' }),
}))

import axios from '@nextcloud/axios'
import AgentEditDialog from '../../src/dialogs/AgentEditDialog.vue'

// Vue 3 model API throughout: `modelValue` in, `update:modelValue` out. The
// Vue 2 `value` / `update:value` pair the dialog used to bind is gone.
const NcSelectStub = {
	name: 'NcSelect',
	props: ['modelValue', 'options', 'inputLabel', 'clearable', 'multiple', 'label'],
	emits: ['update:modelValue'],
	template:
		'<div class="ncselect-stub" :data-label="inputLabel" :data-count="(modelValue || []).length || (modelValue ? 1 : 0)" />',
}
const NcTextFieldStub = {
	name: 'NcTextField',
	props: ['modelValue', 'label', 'type'],
	emits: ['update:modelValue'],
	template:
		'<input class="nctextfield-stub" :data-label="label" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
}
const NcTextAreaStub = {
	name: 'NcTextArea',
	props: ['modelValue', 'label', 'placeholder'],
	emits: ['update:modelValue'],
	template:
		'<textarea class="nctextarea-stub" :data-label="label" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
}
// `emits: ['click']` is load-bearing: without it Vue 3 leaves the parent's
// `@click` in `$attrs`, it falls through onto the root <button>, and one user
// click fires the handler twice (here: two axios saves).
const NcButtonStub = {
	name: 'NcButton',
	props: ['type', 'disabled'],
	emits: ['click'],
	template:
		'<button :disabled="disabled || false" @click="$emit(\'click\')"><slot /></button>',
}
const NcModalStub = {
	name: 'NcModal',
	props: ['name', 'size'],
	template: '<div class="ncmodal-stub"><slot /></div>',
}
const NcNoteCardStub = {
	name: 'NcNoteCard',
	props: ['type'],
	template: '<div class="ncnotecard-stub"><slot /></div>',
}

const stubs = {
	NcModal: NcModalStub,
	NcSelect: NcSelectStub,
	NcTextField: NcTextFieldStub,
	NcTextArea: NcTextAreaStub,
	NcButton: NcButtonStub,
	NcNoteCard: NcNoteCardStub,
}

const flush = () => new Promise((r) => setTimeout(r, 0))

function factory(agent = null) {
	return mount(AgentEditDialog, {
		propsData: { open: false, agent, applicationSlug: 'tool-library' },
		stubs,
	})
}

async function openDialog(wrapper) {
	await wrapper.setProps({ open: true })
	await flush()
	await flush()
}

describe('AgentEditDialog', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.post.mockReset()
		axios.put.mockReset()
		axios.post.mockResolvedValue({ data: { id: 'new-agent-uuid' } })
		axios.put.mockResolvedValue({ data: {} })
	})

	it('a new agent starts with an empty name and no enabled tools', async () => {
		const wrapper = factory(null)
		await openDialog(wrapper)

		expect(wrapper.vm.name).toBe('')
		expect(wrapper.vm.enabledTools).toEqual([])
		expect(wrapper.vm.maxActionsPerRun).toBe(10)
	})

	it('editing an existing agent hydrates every field', async () => {
		const agent = {
			id: 'agent-1',
			name: 'Page builder assistant',
			instructions: 'Be helpful.',
			modelTaskType: 'TextToText',
			enabledTools: ['buildiq.upsertPage', 'buildiq.addWidget'],
			maxActionsPerRun: 5,
		}
		const wrapper = factory(agent)
		await openDialog(wrapper)

		expect(wrapper.vm.name).toBe('Page builder assistant')
		expect(wrapper.vm.enabledTools).toEqual([
			'buildiq.upsertPage',
			'buildiq.addWidget',
		])
		expect(wrapper.vm.maxActionsPerRun).toBe(5)
		expect(wrapper.vm.editing).toBe(true)
	})

	it('validation blocks save when no tool is enabled', async () => {
		const wrapper = factory(null)
		await openDialog(wrapper)
		wrapper.vm.name = 'My agent'
		wrapper.vm.enabledTools = []

		await wrapper.find('[data-testid="agent-save-button"]').trigger('click')
		await flush()

		expect(axios.post).not.toHaveBeenCalled()
		expect(wrapper.find('.agent-edit__error').exists()).toBe(true)
	})

	it('saving a new agent posts to the generic OpenRegister agent endpoint', async () => {
		const wrapper = factory(null)
		await openDialog(wrapper)
		wrapper.vm.name = 'Page builder assistant'
		wrapper.vm.enabledTools = ['buildiq.upsertPage']
		wrapper.vm.maxActionsPerRun = 5

		await wrapper.find('[data-testid="agent-save-button"]').trigger('click')
		await flush()

		expect(axios.post).toHaveBeenCalledTimes(1)
		const [url, payload] = axios.post.mock.calls[0]
		expect(url).toBe('/apps/openregister/api/objects/buildiq/buildAgent')
		expect(payload.enabledTools).toEqual(['buildiq.upsertPage'])
		expect(payload.applicationSlug).toBe('tool-library')
		expect(wrapper.emitted('saved')).toBeTruthy()
	})

	it('saving an existing agent PUTs to its uuid', async () => {
		const agent = {
			id: 'agent-1',
			name: 'Existing agent',
			enabledTools: ['buildiq.listApps'],
			maxActionsPerRun: 10,
		}
		const wrapper = factory(agent)
		await openDialog(wrapper)

		await wrapper.find('[data-testid="agent-save-button"]').trigger('click')
		await flush()

		expect(axios.put).toHaveBeenCalledTimes(1)
		expect(axios.put.mock.calls[0][0]).toBe(
			'/apps/openregister/api/objects/buildiq/buildAgent/agent-1',
		)
		expect(axios.put.mock.calls[0][1]).toMatchObject({
			name: 'Existing agent',
			enabledTools: ['buildiq.listApps'],
			maxActionsPerRun: 10,
			applicationSlug: 'tool-library',
		})
	})

	it('an enabled-tools multi-select change updates the local list', async () => {
		const wrapper = factory(null)
		await openDialog(wrapper)

		wrapper.vm.onEnabledToolsSelect([
			{ value: 'buildiq.upsertPage' },
			{ value: 'buildiq.addWidget' },
		])
		expect(wrapper.vm.enabledTools).toEqual([
			'buildiq.upsertPage',
			'buildiq.addWidget',
		])

		wrapper.vm.onEnabledToolsSelect(null)
		expect(wrapper.vm.enabledTools).toEqual([])
	})

	it('the max-actions-per-run field clamps to a minimum of 1', async () => {
		const wrapper = factory(null)
		await openDialog(wrapper)

		wrapper.vm.onMaxActionsInput('0')
		expect(wrapper.vm.maxActionsPerRun).toBe(1)

		wrapper.vm.onMaxActionsInput('7')
		expect(wrapper.vm.maxActionsPerRun).toBe(7)
	})
	describe('hydrate and save branches', () => {
		it('takes the uuid when there is no id and copies the tool list', async () => {
			const tools = ['buildiq.listApps']
			const wrapper = factory({ uuid: 'agent-uuid', enabledTools: tools })
			await openDialog(wrapper)
			expect(wrapper.vm.id).toBe('agent-uuid')
			expect(wrapper.vm.enabledTools).toEqual(tools)
			expect(wrapper.vm.enabledTools).not.toBe(tools)
			expect(wrapper.vm.instructions).toBe('')
		})

		it('falls back to the first task type and the default limit', async () => {
			const wrapper = factory({
				id: 'a',
				modelTaskType: 'Unknown',
				enabledTools: 'buildiq.listApps',
				maxActionsPerRun: '5',
			})
			await openDialog(wrapper)
			expect(wrapper.vm.modelTaskTypeOption.value).toBe('TextToText')
			expect(wrapper.vm.enabledTools).toEqual([])
			expect(wrapper.vm.maxActionsPerRun).toBe(10)
		})

		it('keeps a zero limit as a finite number', async () => {
			const wrapper = factory({ id: 'a', maxActionsPerRun: 0 })
			await openDialog(wrapper)
			expect(wrapper.vm.maxActionsPerRun).toBe(0)
		})

		it('resets the validation state and a previous agent on reopen', async () => {
			const wrapper = factory({ id: 'a', name: 'First' })
			await openDialog(wrapper)
			wrapper.vm.showValidation = true
			wrapper.vm.errorMessage = 'old'
			await wrapper.setProps({ open: false, agent: null })
			await openDialog(wrapper)
			expect(wrapper.vm.showValidation).toBe(false)
			expect(wrapper.vm.errorMessage).toBe('')
			expect(wrapper.vm.id).toBeNull()
			expect(wrapper.vm.name).toBe('')
			expect(wrapper.vm.editing).toBe(false)
		})

		it('reports a failed save and emits nothing', async () => {
			axios.post.mockRejectedValueOnce(new Error('down'))
			const wrapper = factory(null)
			await openDialog(wrapper)
			wrapper.vm.name = 'Agent'
			wrapper.vm.enabledTools = ['buildiq.listApps']
			await wrapper.vm.onSave()
			expect(wrapper.vm.errorMessage).toBe('Could not save the agent.')
			expect(wrapper.vm.saving).toBe(false)
			expect(wrapper.emitted('saved')).toBeUndefined()
		})

		it('posts a new agent and emits saved', async () => {
			const wrapper = factory(null)
			await openDialog(wrapper)
			wrapper.vm.name = 'Agent'
			wrapper.vm.enabledTools = ['buildiq.listApps']
			await wrapper.vm.onSave()
			expect(axios.post).toHaveBeenCalledTimes(1)
			expect(axios.put).not.toHaveBeenCalled()
			expect(wrapper.emitted('saved')).toHaveLength(1)
			expect(wrapper.vm.saving).toBe(false)
		})
	})
})

describe('AgentEditDialog knowledge (REQ-BQAG-001)', () => {
	const keyOnly = globalThis.t
	afterEach(() => {
		globalThis.t = keyOnly
	})

	beforeEach(() => {
		// The refusals are built in script code: fill in their placeholders.
		globalThis.t = (_app, key, vars = {}) => key.replace(/\{(\w+)\}/g, (match, name) => (name in vars ? String(vars[name]) : match))
		axios.get.mockReset()
		axios.post.mockReset()
		axios.put.mockReset()
		axios.delete.mockReset()
		axios.post.mockResolvedValue({ data: { id: 'new-agent-uuid' } })
		axios.put.mockResolvedValue({ data: {} })
		axios.delete.mockResolvedValue({ data: {} })
	})

	/**
	 * A picked file as the file input's change event carries it.
	 *
	 * @param {string} name - the file name.
	 * @param {string} text - the file text.
	 * @return {object}
	 */
	function pick(name, text) {
		const target = { files: [{ name, text: () => Promise.resolve(text) }], value: name }
		return { target }
	}

	const existing = {
		id: 'agent-1',
		name: 'Formulierbouwer',
		enabledTools: ['buildiq.upsertPage'],
		maxActionsPerRun: 10,
		knowledge: [{ fileName: 'old.md', addedBy: 'bob', addedAt: '2026-11-01T10:00:00Z', characters: 38000 }],
	}

	it('adds a Markdown file and uploads it to the agent object on save', async () => {
		const wrapper = factory({ ...existing, knowledge: [] })
		await openDialog(wrapper)

		const event = pick('huisstijl-formulieren.md', 'Labels in sentence case.')
		await wrapper.vm.onKnowledgePick(event)
		await flush()

		expect(event.target.value).toBe('')
		expect(wrapper.findAll('[data-testid="agent-knowledge-item"]')).toHaveLength(1)
		expect(wrapper.text()).toContain('huisstijl-formulieren.md')
		expect(wrapper.vm.knowledge[0]).toMatchObject({
			fileName: 'huisstijl-formulieren.md',
			addedBy: 'alice',
			characters: 24,
		})

		await wrapper.find('[data-testid="agent-save-button"]').trigger('click')
		await flush()

		expect(axios.put.mock.calls[0][1].knowledge.map((k) => k.fileName)).toEqual(['huisstijl-formulieren.md'])
		expect(axios.post).toHaveBeenCalledWith(
			'/apps/openregister/api/objects/buildiq/buildAgent/agent-1/files',
			{ name: 'huisstijl-formulieren.md', content: 'Labels in sentence case.' },
		)
		expect(axios.get).not.toHaveBeenCalled()
		expect(wrapper.emitted('saved')).toBeTruthy()
	})

	it('uploads to the id a new agent comes back with', async () => {
		const wrapper = factory(null)
		await openDialog(wrapper)
		wrapper.vm.name = 'New agent'
		wrapper.vm.enabledTools = ['buildiq.upsertPage']
		await wrapper.vm.onKnowledgePick(pick('notes.txt', 'Hello'))

		await wrapper.find('[data-testid="agent-save-button"]').trigger('click')
		await flush()

		expect(axios.post).toHaveBeenCalledTimes(2)
		expect(axios.post.mock.calls[0][0]).toBe('/apps/openregister/api/objects/buildiq/buildAgent')
		expect(axios.post.mock.calls[1]).toEqual([
			'/apps/openregister/api/objects/buildiq/buildAgent/new-agent-uuid/files',
			{ name: 'notes.txt', content: 'Hello' },
		])
	})

	it('refuses a file over the budget and names the room left', async () => {
		const wrapper = factory(existing)
		await openDialog(wrapper)

		await wrapper.vm.onKnowledgePick(pick('extra.md', 'x'.repeat(5000)))
		await flush()

		expect(wrapper.vm.knowledge.map((k) => k.fileName)).toEqual(['old.md'])
		const error = wrapper.find('[data-testid="agent-knowledge-error"]')
		expect(error.exists()).toBe(true)
		expect(error.text()).toContain('extra.md has 5,000 characters, but only 2,000 characters of room are left.')
		expect(wrapper.vm.pendingKnowledge).toEqual({})
	})

	it('refuses a type other than text or Markdown, and a name already listed', async () => {
		const wrapper = factory(existing)
		await openDialog(wrapper)

		await wrapper.vm.onKnowledgePick(pick('handbook.pdf', '%PDF'))
		expect(wrapper.vm.knowledgeError).toBe('Only text (.txt) and Markdown (.md) files can be added as knowledge.')

		await wrapper.vm.onKnowledgePick(pick('old.md', 'again'))
		expect(wrapper.vm.knowledgeError).toBe('old.md is already in this agent\'s knowledge.')
		expect(wrapper.vm.knowledge).toHaveLength(1)
	})

	it('removes a stored file and detaches it from the agent object on save', async () => {
		axios.get.mockResolvedValue({ data: { results: [{ id: 7, title: 'old.md' }, { id: 8, title: 'other.md' }] } })
		const wrapper = factory(existing)
		await openDialog(wrapper)

		await wrapper.find('[data-testid="agent-knowledge-remove"]').trigger('click')
		expect(wrapper.vm.knowledge).toEqual([])
		expect(wrapper.vm.knowledgeRoom).toBe(40000)

		await wrapper.find('[data-testid="agent-save-button"]').trigger('click')
		await flush()

		expect(axios.put.mock.calls[0][1].knowledge).toEqual([])
		expect(axios.get).toHaveBeenCalledWith('/apps/openregister/api/objects/buildiq/buildAgent/agent-1/files')
		expect(axios.delete).toHaveBeenCalledTimes(1)
		expect(axios.delete).toHaveBeenCalledWith('/apps/openregister/api/objects/buildiq/buildAgent/agent-1/files/7')
	})

	it('removing a file added in this dialog uploads nothing', async () => {
		const wrapper = factory({ ...existing, knowledge: [] })
		await openDialog(wrapper)
		await wrapper.vm.onKnowledgePick(pick('draft.md', 'draft'))
		wrapper.vm.removeKnowledge('draft.md')

		await wrapper.find('[data-testid="agent-save-button"]').trigger('click')
		await flush()

		expect(axios.post).not.toHaveBeenCalled()
		expect(axios.get).not.toHaveBeenCalled()
		expect(axios.delete).not.toHaveBeenCalled()
	})
})
