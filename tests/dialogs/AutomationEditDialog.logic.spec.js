import { mount } from '@vue/test-utils'
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for AutomationEditDialog.vue: the editor's validity rules,
 * hydration of a stored condition, and the action-row helpers.
 *
 * Spec: automation-designer (REQ-AUTD-002, REQ-AUTD-003).
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))
vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), put: vi.fn() },
}))

import axios from '@nextcloud/axios'
import AutomationEditDialog from '../../src/dialogs/AutomationEditDialog.vue'
import { clearAppStatusCache } from '../../src/composables/useAppStatus.js'

const NcSelectStub = {
	name: 'NcSelect',
	props: [
		'value',
		'options',
		'loading',
		'inputLabel',
		'label',
		'clearable',
		'disabled',
		'multiple',
	],
	template: '<div class="ncselect-stub" :data-label="inputLabel" />',
}
const NcTextFieldStub = {
	name: 'NcTextField',
	props: ['value', 'label', 'placeholder', 'type'],
	template:
		'<input class="nctextfield-stub" :data-label="label" :value="value" @input="$emit(\'update:value\', $event.target.value)">',
}
const NcTextAreaStub = {
	name: 'NcTextArea',
	props: ['value', 'label'],
	template:
		'<textarea class="nctextarea-stub" :data-label="label" :value="value" @input="$emit(\'update:value\', $event.target.value)" />',
}
const NcButtonStub = {
	name: 'NcButton',
	props: ['type', 'disabled'],
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

function baseAutomation() {
	return {
		slug: '',
		name: '',
		description: '',
		applicationSlug: 'permit-tracker',
		versionUuid: 'version-1',
		enabled: true,
		trigger: { type: 'manual' },
		condition: null,
		actions: [],
	}
}

function factory(automation = baseAutomation()) {
	return mount(AutomationEditDialog, {
		propsData: { open: false, automation, register: '' },
		stubs,
	})
}

async function openDialog(wrapper) {
	await wrapper.setProps({ open: true })
	await flush()
	await flush()
}

describe('AutomationEditDialog editor logic', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.post.mockReset()
		axios.put.mockReset()
		axios.get.mockResolvedValue({ data: { results: [] } })
		clearAppStatusCache()
	})

	it('clearing the trigger picker falls back to a manual trigger', async () => {
		const wrapper = factory({ ...baseAutomation(), trigger: { type: 'schedule' } })
		await openDialog(wrapper)

		wrapper.vm.triggerOption = null

		expect(wrapper.vm.triggerType).toBe('manual')
	})

	it('is invalid with a condition on a trigger that takes none', async () => {
		const wrapper = factory()
		await openDialog(wrapper)
		wrapper.vm.name = 'Notify'
		wrapper.vm.triggerType = 'object-created'
		wrapper.vm.actions = [wrapper.vm.actionToEditor({ type: 'send-notification' })]
		wrapper.vm.conditionKindOption = wrapper.vm.conditionKindOptions[1]

		expect(wrapper.vm.valid).toBe(false)

		wrapper.vm.conditionKindOption = wrapper.vm.conditionKindOptions[0]
		expect(wrapper.vm.valid).toBe(true)
	})

	it('is invalid with an action the trigger does not allow', async () => {
		const wrapper = factory()
		await openDialog(wrapper)
		wrapper.vm.name = 'Sync'
		wrapper.vm.triggerType = 'schedule'
		wrapper.vm.actions = [wrapper.vm.actionToEditor({ type: 'send-notification' })]

		expect(wrapper.vm.valid).toBe(false)
	})

	it('asks for a name first, then for an action', async () => {
		const wrapper = factory()
		await openDialog(wrapper)

		expect(wrapper.vm.validationMessage).toBe('Please enter a name.')

		wrapper.vm.name = 'Notify'
		wrapper.vm.actions = []
		expect(wrapper.vm.validationMessage).toBe('Add at least one action.')
	})

	it('hydrates a stored FEEL condition and a stored rule-set condition', async () => {
		const feel = factory({ ...baseAutomation(), condition: { type: 'feel', expression: 'amount > 10' } })
		await openDialog(feel)
		expect(feel.vm.conditionKind).toBe('feel')
		expect(feel.vm.conditionExpression).toBe('amount > 10')
		expect(feel.vm.buildCondition()).toEqual({ type: 'feel', expression: 'amount > 10' })

		const ruleSet = factory({ ...baseAutomation(), condition: { type: 'rule-set', ruleSetSlug: 'checks' } })
		await openDialog(ruleSet)
		expect(ruleSet.vm.conditionKind).toBe('rule-set')
		expect(ruleSet.vm.buildCondition()).toEqual({ type: 'rule-set', ruleSetSlug: 'checks' })
	})

	it('resolves sync and template options by id, null when unknown', async () => {
		const wrapper = factory()
		await openDialog(wrapper)
		wrapper.vm.syncOptions = [{ id: 'sync-1', label: 'BRP sync' }]
		wrapper.vm.docudeskTemplateOptions = [{ uuid: 'tpl-1', label: 'Besluit' }]

		expect(wrapper.vm.syncOption('sync-1')).toEqual({ id: 'sync-1', label: 'BRP sync' })
		expect(wrapper.vm.syncOption('other')).toBeNull()
		expect(wrapper.vm.templateOption('tpl-1')).toEqual({ uuid: 'tpl-1', label: 'Besluit' })
		expect(wrapper.vm.templateOption('other')).toBeNull()
	})

	it('writes the picked template and output modes onto the action row', async () => {
		const wrapper = factory()
		await openDialog(wrapper)
		wrapper.vm.actions = [wrapper.vm.actionToEditor({ type: 'generateDocument' })]

		wrapper.vm.onTemplateSelect(0, { uuid: 'tpl-1' })
		wrapper.vm.onOutputModesSelect(0, [{ value: 'attach' }, { value: 'notify' }])
		expect(wrapper.vm.actions[0].templateId).toBe('tpl-1')
		expect(wrapper.vm.actions[0].output).toEqual(['attach', 'notify'])

		wrapper.vm.onTemplateSelect(0, null)
		wrapper.vm.onOutputModesSelect(0, null)
		expect(wrapper.vm.actions[0].templateId).toBe('')
		expect(wrapper.vm.actions[0].output).toEqual([])
	})

	it('a generateDocument action needs a template, an output, and never notify alone', async () => {
		const wrapper = factory()
		await openDialog(wrapper)
		const check = (action) => wrapper.vm.generateDocumentActionValid(action)

		expect(check({ templateId: '', output: ['attach'] })).toBe(false)
		expect(check({ templateId: 'tpl-1', output: 'attach' })).toBe(false)
		expect(check({ templateId: 'tpl-1', output: [] })).toBe(false)
		expect(check({ templateId: 'tpl-1', output: ['notify'] })).toBe(false)
		expect(check({ templateId: 'tpl-1', output: ['notify', 'download-link'] })).toBe(true)
		expect(check({ templateId: 'tpl-1', output: ['attach'] })).toBe(true)
	})

	it('an incomplete generateDocument action makes the automation invalid', async () => {
		const wrapper = factory()
		await openDialog(wrapper)
		wrapper.vm.name = 'Make a letter'
		wrapper.vm.triggerType = 'object-created'
		wrapper.vm.actions = [{ ...wrapper.vm.actionToEditor({ type: 'generateDocument' }), templateId: '', output: [] }]
		vi.spyOn(wrapper.vm, 'actionBlockedReason').mockReturnValue('')

		expect(wrapper.vm.valid).toBe(false)
	})

	it('loads transitions only for a lifecycle trigger with a schema', async () => {
		const wrapper = factory()
		await openDialog(wrapper)
		axios.get.mockReset()
		axios.get.mockResolvedValue({ data: { 'x-openregister-lifecycle': { transitions: { approve: {} } } } })

		wrapper.vm.triggerType = 'object-created'
		wrapper.vm.triggerSchema = 'permit'
		wrapper.vm.onTriggerChange()
		expect(axios.get).not.toHaveBeenCalled()

		wrapper.vm.triggerType = 'lifecycle-transition'
		wrapper.vm.onTriggerChange()
		await flush()
		expect(axios.get).toHaveBeenCalledWith('/apps/openregister/api/schemas/permit')
		expect(wrapper.vm.transitionOptions.map((o) => o.value)).toContain('approve')
	})

	it('removes an action row with its operation picker, and a cleared type falls back to a notification', async () => {
		const wrapper = factory()
		await openDialog(wrapper)
		wrapper.vm.actions = []
		wrapper.vm.objectOpOperationOption = []
		wrapper.vm.addAction()
		wrapper.vm.addAction()
		wrapper.vm.onActionTypeChange(1, { value: 'webhook' })
		expect(wrapper.vm.actions.map((a) => a.type)).toEqual(['send-notification', 'webhook'])

		wrapper.vm.onActionTypeChange(1, null)
		expect(wrapper.vm.actions[1].type).toBe('send-notification')

		wrapper.vm.removeAction(0)
		expect(wrapper.vm.actions).toHaveLength(1)
		expect(wrapper.vm.objectOpOperationOption).toHaveLength(1)
	})
})
