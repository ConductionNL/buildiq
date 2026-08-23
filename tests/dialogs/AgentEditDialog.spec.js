/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for AgentEditDialog.vue.
 *
 * Spec: agent-workspace ("Agent entity declares a named, tool-scoped configuration").
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))
vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), put: vi.fn() },
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

const factory = (agent = null) =>
	mount(AgentEditDialog, {
		propsData: { open: false, agent, applicationSlug: 'tool-library' },
		stubs,
	})

const openDialog = async (wrapper) => {
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
		expect(url).toBe('/apps/openregister/api/objects/openbuild/agent')
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
			'/apps/openregister/api/objects/openbuild/agent/agent-1',
		)
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
})
