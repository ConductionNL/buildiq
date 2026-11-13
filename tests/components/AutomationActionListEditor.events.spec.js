/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for AutomationActionListEditor.vue's field events: type and
 * operation changes (including a cleared select), the notification and
 * webhook fields, removing a row, and the resync from an external reset.
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import AutomationActionListEditor from '../../src/components/AutomationActionListEditor.vue'

const NcSelectStub = {
	name: 'NcSelect',
	props: ['modelValue', 'options', 'inputLabel', 'label', 'clearable'],
	emits: ['update:modelValue'],
	template: '<div class="ncselect-stub" :data-label="inputLabel" />',
}
const NcTextFieldStub = {
	name: 'NcTextField',
	props: ['modelValue', 'label'],
	emits: ['update:modelValue'],
	template: '<input class="nctextfield-stub" :data-label="label" :value="modelValue">',
}
const NcTextAreaStub = {
	name: 'NcTextArea',
	props: ['modelValue', 'label'],
	emits: ['update:modelValue'],
	template: '<textarea class="nctextarea-stub" :data-label="label" :value="modelValue" />',
}
const NcButtonStub = {
	name: 'NcButton',
	props: ['variant'],
	emits: ['click'],
	template: '<button @click="$emit(\'click\')"><slot /></button>',
}

const stubs = {
	NcSelect: NcSelectStub,
	NcTextField: NcTextFieldStub,
	NcTextArea: NcTextAreaStub,
	NcButton: NcButtonStub,
}

/**
 * Mount the editor over stored actions.
 *
 * @param {Array<object>} modelValue Stored actions.
 * @return {import('@vue/test-utils').Wrapper}
 */
function factory(modelValue) {
	return mount(AutomationActionListEditor, {
		propsData: { modelValue, label: 'On approve' },
		stubs,
	})
}

/**
 * The component stub with the given label.
 *
 * @param {import('@vue/test-utils').Wrapper} wrapper The editor.
 * @param {object} stub The stub component.
 * @param {string} label The label prop to find.
 * @return {import('@vue/test-utils').Wrapper}
 */
function byLabel(wrapper, stub, label) {
	return wrapper
		.findAllComponents(stub)
		.find((c) => c.props('inputLabel') === label || c.props('label') === label)
}

/**
 * The last emitted value.
 *
 * @param {import('@vue/test-utils').Wrapper} wrapper The editor.
 * @return {Array<object>}
 */
function lastEmitted(wrapper) {
	const events = wrapper.emitted('update:modelValue')
	return events[events.length - 1][0]
}

describe('AutomationActionListEditor field events', () => {
	it('switches a row to a webhook and emits its url and payload', async () => {
		const wrapper = factory([{ type: 'send-notification', subject: { en: 'Hi', nl: 'Hoi' } }])
		await byLabel(wrapper, NcSelectStub, 'Action type').vm.$emit('update:modelValue', { value: 'webhook' })
		expect(lastEmitted(wrapper)).toEqual([{ type: 'webhook', url: '' }])

		await byLabel(wrapper, NcTextFieldStub, 'Webhook URL').vm.$emit('update:modelValue', 'https://hook.test/x')
		await byLabel(wrapper, NcTextAreaStub, 'Payload template (JSON)').vm.$emit('update:modelValue', '{"id":"{{id}}"}')
		expect(lastEmitted(wrapper)).toEqual([
			{ type: 'webhook', url: 'https://hook.test/x', payloadTemplate: { id: '{{id}}' } },
		])
	})

	it('drops an unparsable payload template instead of emitting it', async () => {
		const wrapper = factory([{ type: 'webhook', url: 'u' }])
		await byLabel(wrapper, NcTextAreaStub, 'Payload template (JSON)').vm.$emit('update:modelValue', '{not json')
		expect(lastEmitted(wrapper)).toEqual([{ type: 'webhook', url: 'u' }])
	})

	it('falls back to send-notification when the type select is cleared', async () => {
		const wrapper = factory([{ type: 'webhook', url: 'u' }])
		await byLabel(wrapper, NcSelectStub, 'Action type').vm.$emit('update:modelValue', null)
		expect(lastEmitted(wrapper)).toEqual([{ type: 'send-notification', subject: { en: '', nl: '' } }])
	})

	it('emits both notification subjects', async () => {
		const wrapper = factory([{ type: 'send-notification' }])
		await byLabel(wrapper, NcTextFieldStub, 'Subject (English)').vm.$emit('update:modelValue', 'Approved')
		await byLabel(wrapper, NcTextFieldStub, 'Subject (Dutch)').vm.$emit('update:modelValue', 'Goedgekeurd')
		expect(lastEmitted(wrapper)).toEqual([
			{ type: 'send-notification', subject: { en: 'Approved', nl: 'Goedgekeurd' } },
		])
	})

	it('sets the object operation and falls back to update when it is cleared', async () => {
		const wrapper = factory([{ type: 'object-op', operation: 'update', schema: 'case' }])
		const select = byLabel(wrapper, NcSelectStub, 'Operation')
		expect(select.props('modelValue').value).toBe('update')
		await select.vm.$emit('update:modelValue', { value: 'create' })
		expect(lastEmitted(wrapper)).toEqual([{ type: 'object-op', operation: 'create', schema: 'case' }])
		await byLabel(wrapper, NcSelectStub, 'Operation').vm.$emit('update:modelValue', null)
		expect(lastEmitted(wrapper)).toEqual([{ type: 'object-op', operation: 'update', schema: 'case' }])
	})

	it('shows an unknown stored type and operation as the defaults', () => {
		const wrapper = factory([{ type: 'object-op', operation: 'archive' }])
		expect(byLabel(wrapper, NcSelectStub, 'Operation').props('modelValue').value).toBe('update')
		expect(wrapper.vm.typeOption('nope').value).toBe('send-notification')
	})

	it('removes the clicked row and emits the rest', async () => {
		const wrapper = factory([
			{ type: 'webhook', url: 'first' },
			{ type: 'webhook', url: 'second' },
		])
		const removeButtons = wrapper.findAll('button').filter((b) => b.text() === 'Remove')
		await removeButtons[0].trigger('click')
		expect(lastEmitted(wrapper)).toEqual([{ type: 'webhook', url: 'second' }])
		expect(wrapper.findAll('[data-testid="follow-up-action-row"]')).toHaveLength(1)
	})

	it('rebuilds its rows from an external reset but not from its own echo', async () => {
		const wrapper = factory([{ type: 'webhook', url: 'a' }])
		const keyBefore = wrapper.vm.items[0]._key
		await wrapper.setProps({ modelValue: [{ type: 'webhook', url: 'a' }] })
		expect(wrapper.vm.items[0]._key).toBe(keyBefore)

		await wrapper.setProps({ modelValue: [{ type: 'webhook', url: 'b' }, { type: 'send-notification' }] })
		expect(wrapper.vm.items).toHaveLength(2)
		expect(wrapper.vm.items[0].url).toBe('b')
		expect(wrapper.vm.items[0]._key).not.toBe(keyBefore)

		await wrapper.setProps({ modelValue: null })
		expect(wrapper.vm.items).toEqual([])
	})
})
