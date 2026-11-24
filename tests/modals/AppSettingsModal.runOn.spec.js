/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The "Run on" editor of the app settings dialog (REQ-BQSF-001): one block
 * per bound flow, entries on the app version's schemas only, and buildiq's
 * own wrapper flows never shown or dropped.
 *
 * @spec openspec/changes/archive/2026-11-24-logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-a-bound-flow-declares-where-it-runs-req-bqsf-001
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import AppSettingsModal from '../../src/modals/AppSettingsModal.vue'
import {
	isWrapperBinding,
	runOnEntry,
	runOnWhen,
	withPickedFlows,
	withRunOn,
} from '../../src/utils/flowBindings.js'

const stubs = {
	NcModal: { name: 'NcModal', template: '<div><slot /></div>' },
	NcButton: {
		name: 'NcButton',
		props: ['variant', 'disabled'],
		emits: ['click'],
		template: '<button :disabled="disabled || undefined" @click="$emit(\'click\')"><slot /></button>',
	},
	NcCheckboxRadioSwitch: { name: 'NcCheckboxRadioSwitch', props: ['modelValue'], template: '<label><slot /></label>' },
	NcTextField: { name: 'NcTextField', props: ['modelValue', 'label'], template: '<input>' },
	NcSelect: {
		name: 'NcSelect',
		props: ['modelValue', 'options', 'multiple', 'disabled', 'loading', 'inputLabel', 'clearable'],
		emits: ['update:modelValue'],
		template: '<div class="select" />',
	},
}

const ARCHIVE = 'flow-archive'
const WRAPPER = { flow: 'w-1', label: 'aut-attach-permits-abc', sourceUuid: ARCHIVE }
const SCHEMAS = [{ label: 'Vergunning', value: 'vergunning' }, { label: 'Zaak', value: 'zaak' }]
const AVAILABLE = [
	{ label: 'Archiveer dossier', value: ARCHIVE },
	{ label: 'Stuur bevestiging', value: 'flow-send' },
	{ label: 'aut-attach-permits-abc', value: 'w-1' },
]

/**
 * Mount the modal with "Archiveer dossier" bound and its wrapper.
 *
 * @param {Array<object>} runOn The bound flow's entries.
 * @param {object} props Props to override.
 * @return {import('@vue/test-utils').VueWrapper}
 */
function mountModal(runOn = [], props = {}) {
	return mount(AppSettingsModal, {
		props: {
			open: true,
			appName: 'Permits',
			flows: [{ flow: ARCHIVE, label: 'Archiveer dossier', runOn }, WRAPPER],
			availableFlows: AVAILABLE,
			schemas: SCHEMAS,
			...props,
		},
		global: { stubs },
	})
}

/**
 * The selects with an input label.
 *
 * @param {import('@vue/test-utils').VueWrapper} wrapper The modal.
 * @param {string} label The input label.
 * @return {Array<import('@vue/test-utils').VueWrapper>}
 */
function selects(wrapper, label) {
	return wrapper.findAllComponents({ name: 'NcSelect' }).filter((select) => select.props('inputLabel') === label)
}

/**
 * The last `update:flows` payload.
 *
 * @param {import('@vue/test-utils').VueWrapper} wrapper The modal.
 * @return {Array<object>}
 */
function lastFlows(wrapper) {
	const events = wrapper.emitted('update:flows')
	return events[events.length - 1][0]
}

describe('AppSettingsModal "Run on" editor', () => {
	it('shows one block per bound flow, never one for a wrapper, and keeps wrappers out of the picker', () => {
		const wrapper = mountModal()

		expect(wrapper.find(`[data-test="app-settings-run-on-${ARCHIVE}"]`).exists()).toBe(true)
		expect(wrapper.find('[data-test="app-settings-run-on-w-1"]').exists()).toBe(false)
		const picker = selects(wrapper, 'Flows this app is made of')[0]
		expect(picker.props('options').map((option) => option.value)).toEqual([ARCHIVE, 'flow-send'])
		expect(picker.props('modelValue')).toEqual([{ label: 'Archiveer dossier', value: ARCHIVE }])
		expect(wrapper.text()).toContain('Not wired yet.')
	})

	it('adds "Run on" vergunning on updates and keeps the wrapper in the bindings', async () => {
		const wrapper = mountModal()

		await wrapper.find('[data-test="app-settings-run-on-add"]').trigger('click')

		expect(lastFlows(wrapper)).toEqual([
			{ flow: ARCHIVE, label: 'Archiveer dossier', runOn: [{ schema: 'vergunning', event: 'object.updated' }] },
			WRAPPER,
		])
	})

	it('lists only the app version\'s schemas and changes an entry\'s schema and when', async () => {
		const wrapper = mountModal([{ schema: 'vergunning', event: 'object.updated' }])
		const [schema] = selects(wrapper, 'Schema')
		const [when] = selects(wrapper, 'When')

		expect(schema.props('options')).toEqual(SCHEMAS)
		expect(schema.props('modelValue')).toEqual({ label: 'Vergunning', value: 'vergunning' })
		expect(when.props('modelValue')).toEqual({ label: 'Updated', value: 'object.updated' })

		await schema.vm.$emit('update:modelValue', { label: 'Zaak', value: 'zaak' })
		expect(lastFlows(wrapper)[0].runOn).toEqual([{ schema: 'zaak', event: 'object.updated' }])

		await when.vm.$emit('update:modelValue', { label: 'From a record', value: 'from-record' })
		expect(lastFlows(wrapper)[0].runOn).toEqual([{ schema: 'vergunning', mode: 'from-record' }])
	})

	it('removes an entry', async () => {
		const wrapper = mountModal([{ schema: 'vergunning', event: 'object.updated' }, { schema: 'zaak', mode: 'from-record' }])

		await wrapper.findAll('[data-test="app-settings-run-on-remove"]')[0].trigger('click')

		expect(lastFlows(wrapper)[0].runOn).toEqual([{ schema: 'zaak', mode: 'from-record' }])
	})

	it('cannot add an entry when the app has no schemas', async () => {
		const wrapper = mountModal([], { schemas: [] })
		const add = wrapper.find('[data-test="app-settings-run-on-add"]')

		expect(add.attributes('disabled')).toBeDefined()
		expect(wrapper.text()).toContain('This app has no schemas yet')
		wrapper.vm.addRunOn({ flow: ARCHIVE })
		expect(wrapper.emitted('update:flows')).toBeUndefined()
	})

	it('keeps a flow\'s entries when another flow is picked, and drops nothing of the wrapper', async () => {
		const runOn = [{ schema: 'vergunning', event: 'object.created' }]
		const wrapper = mountModal(runOn)

		await selects(wrapper, 'Flows this app is made of')[0].vm.$emit('update:modelValue', [AVAILABLE[0], AVAILABLE[1]])

		expect(lastFlows(wrapper)).toEqual([
			{ flow: ARCHIVE, label: 'Archiveer dossier', runOn },
			{ flow: 'flow-send', label: 'Stuur bevestiging' },
			WRAPPER,
		])
	})
})

describe('flowBindings', () => {
	it('tells a wrapper from a bound flow', () => {
		expect(isWrapperBinding(WRAPPER)).toBe(true)
		expect(isWrapperBinding({ flow: 'x', label: 'aut-attach-permits-abc' })).toBe(false)
		expect(isWrapperBinding({ flow: 'x', label: 'Archiveer', sourceUuid: 'published' })).toBe(false)
		expect(isWrapperBinding(null)).toBe(false)
	})

	it('builds entries in the stored shape, one of event and mode', () => {
		expect(runOnEntry('zaak', 'from-record')).toEqual({ schema: 'zaak', mode: 'from-record' })
		expect(runOnEntry('zaak', 'object.deleted')).toEqual({ schema: 'zaak', event: 'object.deleted' })
		expect(runOnEntry('zaak', 'object.archived')).toEqual({ schema: 'zaak', event: 'object.updated' })
		expect(runOnWhen({ schema: 'zaak', mode: 'from-record' })).toBe('from-record')
		expect(runOnWhen({ schema: 'zaak' })).toBe('')
	})

	it('replaces one flow\'s entries and nobody else\'s', () => {
		const bindings = [{ flow: 'a', runOn: [] }, { flow: 'b' }, { ...WRAPPER, flow: 'a' }]
		expect(withRunOn(bindings, 'a', [{ schema: 'zaak', event: 'object.created' }])).toEqual([
			{ flow: 'a', runOn: [{ schema: 'zaak', event: 'object.created' }] },
			{ flow: 'b' },
			{ ...WRAPPER, flow: 'a' },
		])
		expect(withPickedFlows(undefined, null)).toEqual([])
	})
})
