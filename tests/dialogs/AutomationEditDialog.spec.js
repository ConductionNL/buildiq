/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for AutomationEditDialog.vue.
 *
 * Spec: automation-designer (REQ-AUTD-002, REQ-AUTD-003).
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))
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

const baseAutomation = () => ({
	slug: '',
	name: '',
	description: '',
	applicationSlug: 'permit-tracker',
	versionUuid: 'version-1',
	enabled: true,
	trigger: { type: 'manual' },
	condition: null,
	actions: [],
})

const factory = (automation = baseAutomation()) =>
	mount(AutomationEditDialog, {
		propsData: { open: false, automation, register: '' },
		stubs,
	})

const openDialog = async (wrapper) => {
	await wrapper.setProps({ open: true })
	await flush()
	await flush()
}

describe('AutomationEditDialog', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.post.mockReset()
		axios.put.mockReset()
		axios.get.mockResolvedValue({ data: { results: [] } })
		axios.post.mockResolvedValue({ data: { id: 'new-uuid' } })
		clearAppStatusCache()
	})

	it('REQ-AUTD-002 scenario 1: composes an event-triggered notification', async () => {
		const wrapper = factory()
		await openDialog(wrapper)

		wrapper.vm.name = 'Notify case workers'
		wrapper.vm.triggerType = 'object-created'
		wrapper.vm.triggerSchema = 'permit'
		wrapper.vm.actions = [
			wrapper.vm.actionToEditor({ type: 'send-notification' }),
		]
		wrapper.vm.actions[0].subjectEn = 'New permit'
		wrapper.vm.actions[0].subjectNl = 'Nieuwe vergunning'
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.valid).toBe(true)
		await wrapper.vm.onSave()

		const [, payload] = axios.post.mock.calls.find(([url]) =>
			url.includes('/automation'),
		)
		expect(payload.trigger).toEqual({ type: 'object-created', schema: 'permit' })
		expect(payload.actions[0].type).toBe('send-notification')
		expect(payload.actions[0].subject.en).toBe('New permit')
	})

	it('REQ-AUTD-002 scenario 2: composes a scheduled synchronization run', async () => {
		const wrapper = factory()
		await openDialog(wrapper)

		wrapper.vm.name = 'Nightly sync'
		wrapper.vm.triggerType = 'schedule'
		wrapper.vm.cadenceOption = wrapper.vm.cadenceOptions.find(
			(o) => o.id === 'daily',
		)
		wrapper.vm.actions = [
			wrapper.vm.actionToEditor({ type: 'run-synchronization' }),
		]
		wrapper.vm.actions[0].synchronizationId = 'sync-1'
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.valid).toBe(true)
		await wrapper.vm.onSave()

		const [, payload] = axios.post.mock.calls.find(([url]) =>
			url.includes('/automation'),
		)
		expect(payload.trigger).toEqual({ type: 'schedule', interval: 86400 })
		expect(payload.actions[0]).toEqual({
			type: 'run-synchronization',
			synchronizationId: 'sync-1',
		})
	})

	it('REQ-AUTD-002 scenario 3: composes a manual automation with a condition + object-op', async () => {
		const wrapper = factory()
		await openDialog(wrapper)

		wrapper.vm.name = 'Flag large claims'
		wrapper.vm.triggerType = 'manual'
		wrapper.vm.conditionKindOption = wrapper.vm.conditionKindOptions.find(
			(o) => o.value === 'feel',
		)
		wrapper.vm.conditionExpression = 'payload.amount > 1000'
		wrapper.vm.actions = [wrapper.vm.actionToEditor({ type: 'object-op' })]
		wrapper.vm.actions[0].schema = 'flag'
		wrapper.vm.actions[0].operation = 'create'
		wrapper.vm.actions[0].fieldMappingText = '{"reason":"large-claim"}'
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.valid).toBe(true)
		await wrapper.vm.onSave()

		const [, payload] = axios.post.mock.calls.find(([url]) =>
			url.includes('/automation'),
		)
		expect(payload.condition).toEqual({
			type: 'feel',
			expression: 'payload.amount > 1000',
		})
		expect(payload.actions[0]).toEqual({
			type: 'object-op',
			operation: 'create',
			schema: 'flag',
			fieldMapping: { reason: 'large-claim' },
		})
	})

	it('REQ-AUTD-003: blocks an unsupported action for an event trigger and prevents save', async () => {
		const wrapper = factory()
		await openDialog(wrapper)

		wrapper.vm.name = 'Bad automation'
		wrapper.vm.triggerType = 'object-created'
		wrapper.vm.triggerSchema = 'permit'
		wrapper.vm.actions = [wrapper.vm.actionToEditor({ type: 'webhook' })]
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.valid).toBe(false)
		expect(wrapper.vm.actionBlockedReason('webhook')).not.toBe('')

		await wrapper.vm.onSave()
		expect(
			axios.post.mock.calls.find(([url]) => url.includes('/automation')),
		).toBeUndefined()
	})

	it('REQ-AUTD-003: blocks a condition on a schedule trigger', async () => {
		const wrapper = factory()
		await openDialog(wrapper)

		wrapper.vm.triggerType = 'schedule'
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.conditionBlockedReason).not.toBe('')
	})

	it('automation-approval-steps: composes an approval action with on-approve and on-reject follow-ups', async () => {
		const wrapper = factory()
		await openDialog(wrapper)

		wrapper.vm.name = 'Route permit application for approval'
		wrapper.vm.triggerType = 'object-created'
		wrapper.vm.triggerSchema = 'permit-application'
		wrapper.vm.actions = [wrapper.vm.actionToEditor({ type: 'approval' })]
		wrapper.vm.actions[0].assigneeGroup = 'permit-reviewers'
		wrapper.vm.actions[0].onApprove = [
			{
				type: 'object-op',
				operation: 'update',
				schema: 'permit-application',
				fieldMapping: { status: 'approved' },
			},
		]
		wrapper.vm.actions[0].onReject = [
			{
				type: 'send-notification',
				subject: { en: 'Rejected', nl: 'Afgewezen' },
			},
		]
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.valid).toBe(true)
		await wrapper.vm.onSave()

		const [, payload] = axios.post.mock.calls.find(([url]) =>
			url.includes('/automation'),
		)
		expect(payload.actions[0]).toEqual({
			type: 'approval',
			assigneeGroup: 'permit-reviewers',
			onApprove: [
				{
					type: 'object-op',
					operation: 'update',
					schema: 'permit-application',
					fieldMapping: { status: 'approved' },
				},
			],
			onReject: [
				{
					type: 'send-notification',
					subject: { en: 'Rejected', nl: 'Afgewezen' },
				},
			],
		})
	})

	it('automation-approval-steps REQ-AUTD-003: approval action on a schedule trigger is blocked', async () => {
		const wrapper = factory()
		await openDialog(wrapper)

		wrapper.vm.name = 'Bad approval automation'
		wrapper.vm.triggerType = 'schedule'
		wrapper.vm.actions = [wrapper.vm.actionToEditor({ type: 'approval' })]
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.valid).toBe(false)
		expect(wrapper.vm.actionBlockedReason('approval')).not.toBe('')
	})

	it('automation-document-action REQ-AUTD-002 scenario 4: composes a document-generation action', async () => {
		const wrapper = factory()
		await openDialog(wrapper)

		wrapper.vm.name = 'Generate decision letter on approval'
		wrapper.vm.triggerType = 'lifecycle-transition'
		wrapper.vm.triggerSchema = 'permit-application'
		wrapper.vm.triggerTransition = 'approve'
		wrapper.vm.actions = [
			wrapper.vm.actionToEditor({ type: 'generateDocument' }),
		]
		wrapper.vm.actions[0].templateId = 'tpl-uuid-1'
		wrapper.vm.actions[0].output = ['attach']
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.valid).toBe(true)
		await wrapper.vm.onSave()

		const [, payload] = axios.post.mock.calls.find(([url]) =>
			url.includes('/automation'),
		)
		expect(payload.actions[0]).toEqual({
			type: 'generateDocument',
			templateId: 'tpl-uuid-1',
			output: ['attach'],
		})
	})

	it('automation-document-action REQ-AUTD-002/003: generateDocument on a schedule trigger is blocked', async () => {
		const wrapper = factory()
		await openDialog(wrapper)

		wrapper.vm.name = 'Bad generateDocument automation'
		wrapper.vm.triggerType = 'schedule'
		wrapper.vm.actions = [
			wrapper.vm.actionToEditor({ type: 'generateDocument' }),
		]
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.valid).toBe(false)
		expect(wrapper.vm.actionBlockedReason('generateDocument')).not.toBe('')
	})

	it('automation-document-action design.md D3: notify-only output (no attach/download-link) is rejected', async () => {
		const wrapper = factory()
		await openDialog(wrapper)

		wrapper.vm.name = 'Notify-only automation'
		wrapper.vm.triggerType = 'object-created'
		wrapper.vm.triggerSchema = 'permit-application'
		wrapper.vm.actions = [
			wrapper.vm.actionToEditor({ type: 'generateDocument' }),
		]
		wrapper.vm.actions[0].templateId = 'tpl-uuid-1'
		wrapper.vm.actions[0].output = ['notify']
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.valid).toBe(false)
	})

	it('automation-document-action REQ-AUTD-002: generateDocument action is disabled without Docudesk', async () => {
		axios.get.mockImplementation((url) => {
			if (String(url).includes('/apps/docudesk/api')) {
				const error = new Error('not found')
				error.response = { status: 404 }
				return Promise.reject(error)
			}
			return Promise.resolve({ data: { results: [] } })
		})

		const wrapper = factory()
		await openDialog(wrapper)

		wrapper.vm.name = 'No docudesk automation'
		wrapper.vm.triggerType = 'object-created'
		wrapper.vm.triggerSchema = 'permit-application'
		wrapper.vm.actions = [
			wrapper.vm.actionToEditor({ type: 'generateDocument' }),
		]
		wrapper.vm.actions[0].templateId = 'tpl-uuid-1'
		wrapper.vm.actions[0].output = ['attach']
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.docudeskAvailable).toBe(false)
		expect(wrapper.vm.actionBlockedReason('generateDocument')).not.toBe('')
		expect(wrapper.vm.valid).toBe(false)
	})
})
