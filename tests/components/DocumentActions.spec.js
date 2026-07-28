/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for DocumentActions.vue (runtime surface).
 *
 * Spec: docudesk-document-templates (REQ-DDT-004, REQ-DDT-005).
 */
import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import DocumentActions from '../../src/components/runtime/DocumentActions.vue'

// `emits: ['click']` is load-bearing under Vue 3: an undeclared emit leaves the
// parent's `@click` in `$attrs`, which falls through onto the root <button>, so
// one click runs the handler twice. The real NcButton declares
// `emits: ['click', 'update:pressed']` and therefore fires exactly once.
const NcButtonStub = {
	name: 'NcButton',
	props: ['type', 'disabled'],
	emits: ['click'],
	template: '<button :disabled="disabled || false" @click="$emit(\'click\')"><slot /></button>',
}

const object = { '@self': { id: 'abc-123', register: 'kap', schema: 'kapaanvraag' } }

const attachments = [
	{ id: 'a', schema: 'kapaanvraag', templateId: 'u1', templateName: 'A', label: 'Generate A' },
	{ id: 'b', schema: 'kapaanvraag', templateId: 'u2', templateName: 'B', label: 'Generate B' },
	{ id: 'c', schema: 'andere', templateId: 'u3', templateName: 'C', label: 'Generate C' },
]

const factory = (props = {}) => mount(DocumentActions, {
	propsData: { object, attachments, ...props },
	stubs: { NcButton: NcButtonStub },
})

describe('DocumentActions', () => {
	it('renders one button per attachment for the object schema, in declared order', () => {
		const wrapper = factory()
		const buttons = wrapper.findAll('.ob-document-actions__row button')
		expect(buttons).toHaveLength(2)
		expect(buttons.at(0).text()).toContain('Generate A')
		expect(buttons.at(1).text()).toContain('Generate B')
	})

	it('renders nothing when the schema has no attachments', () => {
		const wrapper = factory({ object: { '@self': { id: 'x', schema: 'unknown' } } })
		expect(wrapper.find('.ob-document-actions').exists()).toBe(false)
	})

	it('shows the unavailable state and issues no request when Docudesk is absent', async () => {
		const wrapper = factory({ docudeskAvailable: false })
		const spy = vi.fn()
		wrapper.vm.docs.generate = spy
		expect(wrapper.find('.ob-document-actions__unavailable').exists()).toBe(true)
		expect(wrapper.find('.ob-document-actions__row').exists()).toBe(false)
	})

	it('delegates generate to the composable on click', async () => {
		const wrapper = factory()
		const spy = vi.fn().mockResolvedValue(null)
		wrapper.vm.docs.generate = spy
		await wrapper.findAll('.ob-document-actions__row button').at(0).trigger('click')
		expect(spy).toHaveBeenCalledTimes(1)
		expect(spy.mock.calls[0][0].id).toBe('a')
	})
})
