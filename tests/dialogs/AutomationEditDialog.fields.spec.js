/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * AutomationEditDialog driven through its template (automation-designer
 * REQ-AUTD-002 / REQ-AUTD-003): with no pickers loaded every id falls back to
 * a text field, and each field the user types into writes the automation
 * being edited; the per-action fields write their own action; Remove drops
 * its action; closing asks the parent to close the dialog.
 */
import { mount } from '@vue/test-utils'
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

function valueStub (tag) {
  return {
	props: ['modelValue', 'label', 'placeholder', 'type'],
	emits: ['update:modelValue'],
	template: `<${tag} :data-label="label" :value="modelValue" @input="$emit('update:modelValue', $event.target.value)" />`,
}
}

const stubs = {
	NcModal: {
		emits: ['close'],
		template: '<div><button class="modal-close" @click="$emit(\'close\')" /><slot /></div>',
	},
	NcTextField: valueStub('input'),
	NcTextArea: valueStub('textarea'),
	NcSelect: {
		props: ['modelValue', 'options', 'inputLabel'],
		emits: ['update:modelValue'],
		template: '<select :data-label="inputLabel" @change="$emit(\'update:modelValue\', options.find((o) => (o.value ?? o.id) === $event.target.value))"><option v-for="o in options" :key="o.value ?? o.id" :value="o.value ?? o.id">{{ o.label }}</option></select>',
	},
	NcButton: { props: ['disabled'], emits: ['click'], template: '<button class="nc-button" @click="$emit(\'click\')"><slot /></button>' },
	NcNoteCard: { props: ['type'], template: '<div class="note"><slot /></div>' },
	NcCheckboxRadioSwitch: { props: ['modelValue'], template: '<label><slot /></label>' },
	AutomationActionListEditor: {
		props: ['modelValue', 'label'],
		emits: ['update:modelValue'],
		template: '<button class="list-editor" @click="$emit(\'update:modelValue\', [{ type: label === \'On approve\' ? \'object-op\' : \'send-notification\' }])" />',
	},
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

async function openWith(automation) {
	const wrapper = mount(AutomationEditDialog, {
		props: { open: false, automation: { applicationSlug: 'permit-tracker', versionUuid: 'v1', enabled: true, actions: [], ...automation }, register: '' },
		global: { stubs },
	})
	// The dialog hydrates from the automation when it opens.
	await wrapper.setProps({ open: true })
	await flush()
	await flush()
	return wrapper
}

/**
 * The text field or select with a label, the n-th when there are several.
 *
 * @param {object} wrapper The dialog.
 * @param {string} label The label.
 * @param {number} n Which one.
 * @return {object} The element wrapper.
 */
const field = (wrapper, label, n = 0) => wrapper.findAll(`[data-label="${label}"]`)[n]

describe('AutomationEditDialog — fields', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.get.mockResolvedValue({ data: { results: [] } })
		clearAppStatusCache()
	})

	it('writes the description, schema and transition of a lifecycle trigger', async () => {
		const wrapper = await openWith({ trigger: { type: 'lifecycle-transition' } })

		await field(wrapper, 'Description (optional)').setValue('Tell the applicant')
		await field(wrapper, 'Schema slug').setValue('permit')
		await field(wrapper, 'Transition action name').setValue('approve')

		expect(wrapper.vm.description).toBe('Tell the applicant')
		expect(wrapper.vm.triggerSchema).toBe('permit')
		expect(wrapper.vm.triggerTransition).toBe('approve')
	})

	it('writes every field of each action into that action', async () => {
		const wrapper = await openWith({
			trigger: { type: 'lifecycle-transition', schema: 'permit', transition: 'approve' },
			actions: [
				{ type: 'send-notification' },
				{ type: 'object-op', operation: 'create' },
				{ type: 'webhook' },
				{ type: 'approval' },
				{ type: 'generateDocument' },
			],
		})

		await field(wrapper, 'Subject (English)').setValue('Permit approved')
		await field(wrapper, 'Subject (Dutch)').setValue('Vergunning verleend')
		await field(wrapper, 'Operation').setValue('update')
		await field(wrapper, 'Target schema').setValue('decision')
		await field(wrapper, 'Field mapping (JSON)').setValue('{"permit":"{{ id }}"}')
		await field(wrapper, 'Webhook URL').setValue('https://hooks.example.org/permit')
		await field(wrapper, 'Payload template (JSON)').setValue('{"id":"{{ id }}"}')
		await field(wrapper, 'Assignee group id').setValue('vergunningen')
		await wrapper.find('[data-testid="on-approve-editor"]').trigger('click')
		await wrapper.find('[data-testid="on-reject-editor"]').trigger('click')
		await field(wrapper, 'Template id').setValue('tpl-42')

		const [notify, objectOp, webhook, approval, document] = wrapper.vm.actions
		expect(notify).toMatchObject({ subjectEn: 'Permit approved', subjectNl: 'Vergunning verleend' })
		expect(objectOp).toMatchObject({ operation: 'update', schema: 'decision', fieldMappingText: '{"permit":"{{ id }}"}' })
		expect(webhook).toMatchObject({ url: 'https://hooks.example.org/permit', payloadTemplateText: '{"id":"{{ id }}"}' })
		expect(approval).toMatchObject({
			assigneeGroup: 'vergunningen',
			onApprove: [{ type: 'object-op' }],
			onReject: [{ type: 'send-notification' }],
		})
		expect(document).toMatchObject({ templateId: 'tpl-42' })
	})

	it('changes an action type, and Remove drops the action it belongs to', async () => {
		const wrapper = await openWith({
			trigger: { type: 'manual' },
			actions: [{ type: 'send-notification' }, { type: 'webhook' }],
		})

		await field(wrapper, 'Action type', 0).setValue('object-op')
		expect(wrapper.vm.actions.map((a) => a.type)).toEqual(['object-op', 'webhook'])

		const remove = wrapper.findAll('.nc-button').filter((b) => b.text() === 'Remove')
		await remove[0].trigger('click')
		expect(wrapper.vm.actions.map((a) => a.type)).toEqual(['webhook'])
	})

	it('writes a custom cron, a custom interval as a number, and a run-synchronization id', async () => {
		const cron = await openWith({ trigger: { type: 'schedule', cron: '0 6 * * *' } })
		await field(cron, 'Cron expression (5 fields)').setValue('*/7 * * * *')
		expect(cron.vm.triggerCron).toBe('*/7 * * * *')

		const interval = await openWith({
			trigger: { type: 'schedule', interval: 1234 },
			actions: [{ type: 'run-synchronization' }],
		})
		await field(interval, 'Interval (seconds)').setValue('600')
		await field(interval, 'Synchronization id').setValue('sync-brp')
		expect(interval.vm.triggerInterval).toBe(600)
		expect(interval.vm.actions[0].synchronizationId).toBe('sync-brp')
	})

	it('writes a FEEL condition and a rule-set condition on a manual automation', async () => {
		const feel = await openWith({ trigger: { type: 'manual' }, condition: { type: 'feel', expression: 'true' } })
		await field(feel, 'FEEL expression').setValue('amount > 1000')
		expect(feel.vm.conditionExpression).toBe('amount > 1000')

		const ruleSet = await openWith({ trigger: { type: 'manual' }, condition: { type: 'rule-set', ruleSetSlug: 'a' } })
		await field(ruleSet, 'Rule set slug').setValue('loan-eligibility')
		expect(ruleSet.vm.conditionRuleSetSlug).toBe('loan-eligibility')
	})

	it('switches the trigger, its cadence and the condition type through their pickers', async () => {
		const wrapper = await openWith({ trigger: { type: 'manual' } })

		await field(wrapper, 'Condition type').setValue('feel')
		expect(wrapper.vm.conditionKind).toBe('feel')
		expect(field(wrapper, 'FEEL expression')).toBeDefined()

		await field(wrapper, 'When').setValue('schedule')
		expect(wrapper.vm.triggerType).toBe('schedule')

		await field(wrapper, 'Cadence').setValue('custom-cron')
		expect(wrapper.vm.isCustomCron).toBe(true)
		expect(field(wrapper, 'Cron expression (5 fields)')).toBeDefined()
	})

	it('asks the parent to close when the dialog closes', async () => {
		const wrapper = await openWith({ trigger: { type: 'manual' } })
		await wrapper.find('.modal-close').trigger('click')
		expect(wrapper.emitted('update:open')).toEqual([[false]])
	})
})
