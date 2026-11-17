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
			{ type: 'webhook', source: 'planning', path: '/intake', payloadTemplateText: '{"b":2}' },
			{ type: 'webhook', source: ' planning ', path: '', payloadTemplateText: '{}' },
			{ type: 'webhook', url: 'https://hooks.test/c', payloadTemplateText: '{oops' },
			{ type: 'something-else', source: 'crm', path: '/d' },
			{ type: 'rule-decision', ruleSetSlug: 'leges-categorie', outputField: 'categorie', inputMappingText: '{"oppervlakte":"area"}' },
			{ type: 'rule-decision', ruleSetSlug: 'leges-categorie', outputField: 'categorie', inputMappingText: '' },
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
			{ type: 'webhook', source: 'planning', path: '/intake', payloadTemplate: { b: 2 } },
			{ type: 'webhook', source: 'planning', path: '/' },
			{ type: 'webhook', source: '', path: '/' },
			{ type: 'webhook', source: 'crm', path: '/d' },
			{ type: 'rule-decision', ruleSetSlug: 'leges-categorie', outputField: 'categorie', inputMapping: { oppervlakte: 'area' } },
			{ type: 'rule-decision', ruleSetSlug: 'leges-categorie', outputField: 'categorie' },
		])
		const keys = wrapper.vm.buildActions().map((a) => Object.keys(a))
		expect(keys[3]).toEqual(['type', 'operation', 'schema'])
		expect(keys[13]).toEqual(['type', 'source', 'path'])
		expect(keys[17]).toEqual(['type', 'ruleSetSlug', 'outputField'])
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
		wrapper.vm.actions = [wrapper.vm.actionToEditor({ type: 'run-synchronization' })]
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.valid).toBe(false)
		expect(wrapper.vm.actionBlockedReason('run-synchronization')).not.toBe('')

		await wrapper.vm.onSave()
		expect(
			axios.post.mock.calls.find(([url]) => url.includes('/automation')),
		).toBeUndefined()
	})

	it('REQ-BQAR-004: a rule-set condition is offered as the gate it now is', async () => {
		// buildiq#923 described a rule set that ran ahead of the actions and
		// stopped nothing. A condition is offered only on a manual trigger,
		// which now compiles to a flow where the rule set's outcome gates the
		// actions (logic-automation-actions-that-run D6), so the text says so.
		const wrapper = factory({
			...baseAutomation(),
			condition: { type: 'rule-set', ruleSetSlug: 'always-no' },
		})
		await openDialog(wrapper)

		const option = wrapper.vm.conditionKindOptions.find(
			(o) => o.value === 'rule-set',
		)
		expect(option.label).toBe('Rule set decides')
		expect(wrapper.vm.conditionKind).toBe('rule-set')

		const note = wrapper.find('[data-testid="condition-rule-set-note"]')
		expect(note.exists()).toBe(true)
		expect(note.text()).toContain('The actions run only when its outcome is true.')
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

	describe('logic-approval-task-form REQ-BQTF-001: the fields the approver fills in', () => {
		const schemaWithFields = {
			properties: {
				bedrag: { type: 'number', title: 'Bedrag' },
				motivering: { type: 'string' },
				status: { type: 'string', readOnly: true },
				intern: { type: 'string', visible: false },
				bijlagen: { type: 'array' },
				aanvrager: { type: 'object', $ref: 'person' },
				zaak: { type: 'string', objectConfiguration: { handling: 'related-object' } },
			},
		}

		async function approvalDialog(stored = { type: 'approval', assigneeGroup: 'reviewers' }) {
			axios.get.mockImplementation((url) => {
				if (url === '/apps/openregister/api/schemas/permit-application') {
					return Promise.resolve({ data: schemaWithFields })
				}
				return Promise.resolve({ data: { results: [] } })
			})
			const wrapper = factory({
				...baseAutomation(),
				slug: 'route-permit',
				name: 'Route permit',
				trigger: { type: 'object-created', schema: 'permit-application' },
				actions: [stored],
			})
			await openDialog(wrapper)
			await flush()
			return wrapper
		}

		function editor(wrapper) {
			return wrapper.findComponent({ name: 'ApprovalFormFieldsEditor' })
		}

		it('offers only the trigger schema fields an approver can fill in', async () => {
			const wrapper = await approvalDialog()

			expect(editor(wrapper).exists()).toBe(true)
			expect(editor(wrapper).props('options')).toEqual([
				{ value: 'bedrag', label: 'Bedrag' },
				{ value: 'motivering', label: 'motivering' },
			])
			const select = editor(wrapper).findComponent({ name: 'NcSelect' })
			expect(select.props('inputLabel')).toBe('Fields the approver fills in')
		})

		it('adds picked fields in order, optional by default, and stores them as form.fields', async () => {
			const wrapper = await approvalDialog()

			editor(wrapper).vm.addField({ value: 'bedrag' })
			await wrapper.vm.$nextTick()
			editor(wrapper).vm.addField({ value: 'motivering' })
			await wrapper.vm.$nextTick()
			editor(wrapper).vm.addField({ value: 'bedrag' })
			await wrapper.vm.$nextTick()
			editor(wrapper).vm.setRequired(0, true)
			await wrapper.vm.$nextTick()

			expect(wrapper.findAll('[data-testid="approval-form-field-row"]')).toHaveLength(2)
			expect(editor(wrapper).vm.availableOptions).toEqual([])
			await wrapper.vm.onSave()
			const [, payload] = axios.post.mock.calls.find(([url]) => url === '/apps/buildiq/api/automations')
			expect(payload.actions[0]).toEqual({
				type: 'approval',
				assigneeGroup: 'reviewers',
				onApprove: [],
				onReject: [],
				form: { fields: [{ field: 'bedrag', required: true }, { field: 'motivering', required: false }] },
			})
		})

		it('reorders and removes a stored form\'s fields', async () => {
			const wrapper = await approvalDialog({
				type: 'approval',
				assigneeGroup: 'reviewers',
				form: { fields: [{ field: 'bedrag', required: true }, { field: 'motivering' }] },
			})
			expect(wrapper.vm.actions[0].formFields).toEqual([
				{ field: 'bedrag', required: true },
				{ field: 'motivering', required: false },
			])

			await wrapper.findAll('[data-testid="approval-form-field-down"]')[0].trigger('click')
			expect(wrapper.vm.actions[0].formFields.map((f) => f.field)).toEqual(['motivering', 'bedrag'])
			editor(wrapper).vm.move(0, -1)
			await wrapper.vm.$nextTick()
			expect(wrapper.vm.actions[0].formFields.map((f) => f.field)).toEqual(['motivering', 'bedrag'])
			editor(wrapper).vm.move(0, 1)
			await wrapper.vm.$nextTick()
			expect(wrapper.vm.actions[0].formFields.map((f) => f.field)).toEqual(['bedrag', 'motivering'])

			await wrapper.findAll('[data-testid="approval-form-field-remove"]')[0].trigger('click')
			expect(wrapper.vm.actions[0].formFields).toEqual([{ field: 'motivering', required: false }])
			await wrapper.findAll('[data-testid="approval-form-field-remove"]')[0].trigger('click')

			await wrapper.vm.onSave()
			const [, payload] = axios.post.mock.calls.find(([url]) => url === '/apps/buildiq/api/automations')
			expect(payload.actions[0]).toEqual({ type: 'approval', assigneeGroup: 'reviewers', onApprove: [], onReject: [] })
			expect(payload.actions[0]).not.toHaveProperty('form')
		})

		it('offers no fields when the trigger schema cannot be read', async () => {
			axios.get.mockImplementation((url) => (url.includes('/api/schemas/')
				? Promise.reject(new Error('down'))
				: Promise.resolve({ data: { results: [] } })))
			const wrapper = factory({
				...baseAutomation(),
				slug: 'route-permit',
				name: 'Route permit',
				trigger: { type: 'object-created', schema: 'permit-application' },
				actions: [{ type: 'approval', assigneeGroup: 'reviewers' }],
			})
			await openDialog(wrapper)
			await flush()

			expect(editor(wrapper).props('options')).toEqual([])
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

	describe('logic-automation-actions-that-run: flow steps', () => {
		it('REQ-BQAR-003: lists the integriq sources and stores a source and a path, never a URL', async () => {
			globalThis.OC = { appswebroots: { integriq: '/apps/integriq' } }
			axios.get.mockImplementation((url) => {
				if (url.includes('/objects/integriq/source')) {
					return Promise.resolve({
						data: { results: [{ uuid: 'src-1', slug: 'planning', name: 'Planning', password: 'secret' }] },
					})
				}
				return Promise.resolve({ data: { results: [] } })
			})

			const wrapper = factory()
			await openDialog(wrapper)

			expect(wrapper.vm.sourceOptions).toEqual([{ id: 'planning', label: 'Planning' }])
			expect(wrapper.vm.sourcePickerAvailable).toBe(true)

			wrapper.vm.name = 'Post intake'
			wrapper.vm.triggerType = 'object-created'
			wrapper.vm.triggerSchema = 'aanvraag'
			wrapper.vm.actions = [wrapper.vm.actionToEditor({ type: 'webhook' })]
			wrapper.vm.updateAction(0, 'source', 'planning')
			wrapper.vm.updateAction(0, 'path', '/intake')
			await wrapper.vm.$nextTick()

			expect(wrapper.vm.valid).toBe(true)
			expect(wrapper.vm.buildActions()).toEqual([{ type: 'webhook', source: 'planning', path: '/intake' }])
			delete globalThis.OC
		})

		it('REQ-BQAR-003: a webhook without a source is refused on save, a stored bare URL included', async () => {
			const wrapper = factory()
			await openDialog(wrapper)

			wrapper.vm.name = 'Old webhook'
			wrapper.vm.triggerType = 'manual'
			wrapper.vm.actions = [wrapper.vm.actionToEditor({ type: 'webhook', url: 'https://hooks.test/a' })]
			await wrapper.vm.$nextTick()

			expect(wrapper.find('[data-testid="webhook-bare-url"]').exists()).toBe(true)
			expect(wrapper.vm.valid).toBe(false)
			expect(wrapper.vm.validationMessage).toBe('Pick a source for this webhook.')

			await wrapper.vm.onSave()
			expect(axios.post.mock.calls.find(([url]) => url.includes('/automations'))).toBeUndefined()
		})

		it('REQ-BQAR-003: the webhook kind is disabled without integriq', async () => {
			axios.get.mockImplementation((url) => {
				if (String(url).includes('/apps/integriq/api')) {
					const error = new Error('not found')
					error.response = { status: 404 }
					return Promise.reject(error)
				}
				return Promise.resolve({ data: { results: [] } })
			})

			const wrapper = factory()
			await openDialog(wrapper)

			expect(wrapper.vm.integriqAvailable).toBe(false)
			expect(wrapper.vm.actionBlockedReason('webhook')).toBe('Integriq is not installed, so a webhook step has no source to call.')
			expect(axios.get.mock.calls.some(([url]) => String(url).includes('/source'))).toBe(false)
		})

		it('REQ-BQAR-005: offers only decision tables and composes "Decide with a rule set"', async () => {
			axios.get.mockImplementation((url) => {
				if (url.includes('/objects/buildiq/rule-set')) {
					return Promise.resolve({
						data: {
							results: [
								{ slug: 'leges-categorie', name: 'Leges categorie', ruleType: 'decision-table' },
								{ slug: 'aut-notify', name: 'Notify', ruleType: 'condition-action' },
							],
						},
					})
				}
				return Promise.resolve({ data: { results: [] } })
			})

			const wrapper = factory()
			await openDialog(wrapper)

			expect(wrapper.vm.ruleSetOptions).toEqual([{ id: 'leges-categorie', label: 'Leges categorie' }])
			expect(wrapper.vm.actionTypeOptions.map((o) => o.value)).toContain('rule-decision')

			wrapper.vm.name = 'Decide the fee'
			wrapper.vm.triggerType = 'object-created'
			wrapper.vm.triggerSchema = 'aanvraag'
			wrapper.vm.actions = [wrapper.vm.actionToEditor({ type: 'rule-decision' })]
			wrapper.vm.updateAction(0, 'ruleSetSlug', 'leges-categorie')
			await wrapper.vm.$nextTick()

			expect(wrapper.vm.valid).toBe(false)
			expect(wrapper.vm.validationMessage).toBe('Pick a rule set and the field its decision is written to.')

			wrapper.vm.updateAction(0, 'outputField', 'categorie')
			wrapper.vm.updateAction(0, 'inputMappingText', '{"oppervlakte":"oppervlakte","type":"soort"}')
			await wrapper.vm.$nextTick()

			expect(wrapper.vm.valid).toBe(true)
			expect(wrapper.vm.buildActions()).toEqual([{
				type: 'rule-decision',
				ruleSetSlug: 'leges-categorie',
				outputField: 'categorie',
				inputMapping: { oppervlakte: 'oppervlakte', type: 'soort' },
			}])
		})

		it('REQ-BQAR-005: hydrates a stored rule decision into the editor', async () => {
			const wrapper = factory()
			await openDialog(wrapper)

			const row = wrapper.vm.actionToEditor({ type: 'rule-decision', ruleSetSlug: 'leges-categorie', outputField: 'categorie', inputMapping: { type: 'soort' } })
			expect(row).toMatchObject({ ruleSetSlug: 'leges-categorie', outputField: 'categorie' })
			expect(JSON.parse(row.inputMappingText)).toEqual({ type: 'soort' })
			expect(wrapper.vm.ruleSetOption('leges-categorie')).toEqual({ id: 'leges-categorie', label: 'leges-categorie' })
		})
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

describe('AutomationEditDialog Ask AI (ai-copilot-documents-and-code-help REQ-BQCH-001/004)', () => {
	const AssistStub = {
		name: 'CopilotAssistButton',
		props: ['target', 'appSlug', 'current', 'context'],
		emits: ['accept'],
		template: '<span class="assist-stub" :data-target="target" />',
	}

	beforeEach(() => {
		axios.get.mockReset()
		axios.post.mockReset()
		axios.get.mockResolvedValue({ data: { results: [] } })
		clearAppStatusCache()
	})

	async function openWith(actions) {
		const wrapper = mount(AutomationEditDialog, {
			propsData: { open: false, automation: baseAutomation(), register: 'openbuild-permit-tracker-main' },
			stubs: { ...stubs, CopilotAssistButton: AssistStub },
		})
		await openDialog(wrapper)
		wrapper.vm.triggerSchema = 'application'
		wrapper.vm.actions = actions.map((a) => wrapper.vm.actionToEditor(a))
		await wrapper.vm.$nextTick()
		return wrapper
	}

	it('puts Ask AI beside the field mapping, scoped to the app with names only', async () => {
		const wrapper = await openWith([{ type: 'object-op', operation: 'create', schema: 'permit', fieldMapping: { name: 'x' } }])

		const button = wrapper.findAllComponents(AssistStub).find((b) => b.props('target') === 'field-mapping')
		expect(button.props('appSlug')).toBe('permit-tracker')
		expect(button.props('context')).toEqual({ register: 'openbuild-permit-tracker-main', targetSchema: 'permit', triggerSchema: 'application' })
		expect(JSON.parse(button.props('current'))).toEqual({ name: 'x' })
	})

	it('stages an accepted mapping and payload template without saving', async () => {
		const wrapper = await openWith([
			{ type: 'object-op', operation: 'create', schema: 'permit' },
			{ type: 'webhook', url: 'https://hooks.test/a', payloadTemplateText: '' },
		])
		const buttons = wrapper.findAllComponents(AssistStub)
		const mapping = buttons.find((b) => b.props('target') === 'field-mapping')
		const payload = buttons.find((b) => b.props('target') === 'payload-template')
		expect(payload.props('context')).toEqual({ triggerSchema: 'application' })

		mapping.vm.$emit('accept', '{\n    "name": "{{ object.title }}"\n}')
		payload.vm.$emit('accept', '{\n    "id": "{{ object.uuid }}"\n}')
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.actions[0].fieldMappingText).toBe('{\n    "name": "{{ object.title }}"\n}')
		expect(wrapper.vm.actions[1].payloadTemplateText).toBe('{\n    "id": "{{ object.uuid }}"\n}')
		expect(axios.post).not.toHaveBeenCalled()
		expect(axios.put).not.toHaveBeenCalled()
	})
})
