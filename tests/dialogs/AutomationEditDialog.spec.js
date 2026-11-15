import { mount } from '@vue/test-utils'
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for AutomationEditDialog.vue.
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

describe('AutomationEditDialog', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.post.mockReset()
		axios.put.mockReset()
		axios.get.mockResolvedValue({ data: { results: [] } })
		axios.post.mockResolvedValue({ data: { id: 'new-uuid' } })
		clearAppStatusCache()
	})

	it('fills the synchronization picker from the connector register this instance has', async () => {
		globalThis.OC = { appswebroots: { integriq: '/apps/integriq' } }
		axios.get.mockImplementation((url) => {
			if (url.includes('/objects/integriq/synchronization')) {
				return Promise.resolve({
					data: { results: [{ id: 'sync-1', name: 'BRP sync' }] },
				})
			}
			return Promise.resolve({ data: { results: [] } })
		})

		const wrapper = factory()
		await openDialog(wrapper)

		// The old code asked for the `openconnector` register with a `limit`
		// param: a 404 on this instance, and a filter that matches nothing on
		// any instance. Both leave the picker empty and say nothing.
		const syncCall = axios.get.mock.calls.find(([url]) =>
			url.includes('/synchronization'),
		)
		expect(syncCall[0]).toBe(
			'/apps/openregister/api/objects/integriq/synchronization',
		)
		expect(syncCall[1].params).toEqual({ _limit: 500 })
		expect(wrapper.vm.syncOptions).toEqual([{ id: 'sync-1', label: 'BRP sync' }])
		expect(wrapper.vm.syncPickerAvailable).toBe(true)
		delete globalThis.OC
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

	it('buildActions: persists every action type in the shape OpenRegister accepts (golden)', async () => {
		const wrapper = factory()
		await openDialog(wrapper)

		wrapper.vm.actions = [
			{ type: 'send-notification', channels: ['email'], recipients: ['admins'], subjectEn: 'Hi', subjectNl: 'Hoi', extra: 1 },
			{ type: 'run-synchronization', synchronizationId: 'sync-1', extra: 1 },
			{ type: 'object-op', operation: 'update', schema: 'case', fieldMappingText: '{"a":1}' },
			{ type: 'object-op', operation: 'create', schema: 'case', fieldMappingText: '{}' },
			{ type: 'object-op', operation: 'create', schema: 'case', fieldMappingText: 'not json' },
			{ type: 'object-op', operation: 'delete', schema: 'case', fieldMappingText: '' },
			{ type: 'object-op', operation: 'create', schema: 'case', fieldMappingText: '42' },
			{ type: 'object-op', operation: 'create', schema: 'case', fieldMappingText: '[1]' },
			{ type: 'approval', assigneeGroup: 'managers', onApprove: [{ type: 'webhook' }], onReject: 'nope' },
			{ type: 'approval', assigneeGroup: 'managers', onApprove: null, onReject: [] },
			{ type: 'generateDocument', templateId: 'tpl-1', output: ['attach'] },
			{ type: 'generateDocument', templateId: 'tpl-2', output: 'attach' },
			{ type: 'webhook', url: 'https://hooks.test/a', payloadTemplateText: '{"b":2}' },
			{ type: 'webhook', url: 'https://hooks.test/b', payloadTemplateText: '{}' },
			{ type: 'webhook', url: 'https://hooks.test/c', payloadTemplateText: '{oops' },
			{ type: 'something-else', url: 'https://hooks.test/d' },
		]

		expect(wrapper.vm.buildActions()).toEqual([
			{ type: 'send-notification', channels: ['email'], recipients: ['admins'], subject: { en: 'Hi', nl: 'Hoi' } },
			{ type: 'run-synchronization', synchronizationId: 'sync-1' },
			{ type: 'object-op', operation: 'update', schema: 'case', fieldMapping: { a: 1 } },
			{ type: 'object-op', operation: 'create', schema: 'case' },
			{ type: 'object-op', operation: 'create', schema: 'case' },
			{ type: 'object-op', operation: 'delete', schema: 'case' },
			{ type: 'object-op', operation: 'create', schema: 'case' },
			{ type: 'object-op', operation: 'create', schema: 'case', fieldMapping: [1] },
			{ type: 'approval', assigneeGroup: 'managers', onApprove: [{ type: 'webhook' }], onReject: [] },
			{ type: 'approval', assigneeGroup: 'managers', onApprove: [], onReject: [] },
			{ type: 'generateDocument', templateId: 'tpl-1', output: ['attach'] },
			{ type: 'generateDocument', templateId: 'tpl-2', output: [] },
			{ type: 'webhook', url: 'https://hooks.test/a', payloadTemplate: { b: 2 } },
			{ type: 'webhook', url: 'https://hooks.test/b' },
			{ type: 'webhook', url: 'https://hooks.test/c' },
			{ type: 'webhook', url: 'https://hooks.test/d' },
		])
		const keys = wrapper.vm.buildActions().map((a) => Object.keys(a))
		expect(keys[3]).toEqual(['type', 'operation', 'schema'])
		expect(keys[13]).toEqual(['type', 'url'])
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

	it('buildiq#923: a rule set is not offered as a gate that stops the actions', async () => {
		// A rule-set condition compiles to a call-rule-set action ahead of the
		// automation's own actions, and the actions run whatever the rule set
		// decides. The picker must not sell it as a condition.
		const wrapper = factory({
			...baseAutomation(),
			condition: { type: 'rule-set', ruleSetSlug: 'always-no' },
		})
		await openDialog(wrapper)

		const option = wrapper.vm.conditionKindOptions.find(
			(o) => o.value === 'rule-set',
		)
		expect(option.label).toBe('Also run a rule set')
		expect(wrapper.vm.conditionKind).toBe('rule-set')

		const note = wrapper.find('[data-testid="condition-rule-set-note"]')
		expect(note.exists()).toBe(true)
		expect(note.text()).toContain('The actions always run.')
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
			if (String(url).includes('/apps/filinq/api')) {
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

	describe('schedule cadence', () => {
		it('offers the six cadences with their labels', async () => {
			const wrapper = factory()
			await openDialog(wrapper)

			expect(wrapper.vm.cadenceOptions).toEqual([
				{ id: 'hourly', label: 'Hourly' },
				{ id: 'daily', label: 'Daily' },
				{ id: 'weekly', label: 'Weekly' },
				{ id: 'monthly', label: 'Monthly' },
				{ id: 'custom-cron', label: 'Custom (cron)' },
				{ id: 'custom-interval', label: 'Custom interval (seconds)' },
			])
		})

		it.each([
			[{ type: 'schedule', cron: '0 3 * * 1', interval: 3600 }, 'custom-cron'],
			[{ type: 'schedule', cron: '', interval: 604800 }, 'weekly'],
			[{ type: 'schedule', interval: 2592000 }, 'monthly'],
			[{ type: 'schedule', interval: 43200 }, 'custom-interval'],
			[{ type: 'manual' }, 'custom-interval'],
		])('maps the stored trigger %j to the %s cadence', async (trigger, id) => {
			const wrapper = factory({ ...baseAutomation(), slug: 'a', trigger })
			await openDialog(wrapper)

			expect(wrapper.vm.cadenceOption).toEqual(
				wrapper.vm.cadenceOptions.find((o) => o.id === id),
			)
		})

		it.each([
			['hourly', { type: 'schedule', interval: 3600 }],
			['monthly', { type: 'schedule', interval: 2592000 }],
			[null, { type: 'schedule', interval: 86400 }],
		])('writes the %s preset as its interval', async (id, expected) => {
			const wrapper = factory()
			await openDialog(wrapper)
			wrapper.vm.triggerType = 'schedule'
			wrapper.vm.cadenceOption = wrapper.vm.cadenceOptions.find((o) => o.id === id) || null

			expect(wrapper.vm.buildTrigger()).toEqual(expected)
		})

		it('derives the slug from the name', async () => {
			const wrapper = factory()
			await openDialog(wrapper)
			wrapper.vm.name = '  Nightly  Sync -- Run! '

			expect(wrapper.vm.derivedSlug).toBe('nightly-sync-run')
		})
	})
})
