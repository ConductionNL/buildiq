/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest coverage of DocumentActions.vue's generate flow: the busy label,
 * the per-attachment error messages, and the Docudesk capability probe that
 * must settle before any generate request is made (REQ-DDT-003/005).
 */
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'

const { fakes } = vi.hoisted(() => ({ fakes: { docs: null, status: null } }))

vi.mock('../../src/composables/useDocudeskDocument.js', () => ({
	useDocudeskDocument: () => fakes.docs,
}))

vi.mock('../../src/composables/useAppStatus.js', () => ({
	useAppStatus: () => fakes.status,
}))

import DocumentActions from '../../src/components/runtime/DocumentActions.vue'

const NcButtonStub = {
	name: 'NcButton',
	props: ['disabled', 'variant'],
	emits: ['click'],
	template:
		'<button :disabled="disabled || false" @click="$emit(\'click\')"><slot /></button>',
}

const object = { '@self': { id: 'abc-123', register: 'kap', schema: 'kapaanvraag' } }

const attachments = [
	{ id: 'a', schema: 'kapaanvraag', label: 'Generate A' },
	{ id: 'b', schema: 'kapaanvraag', label: 'Generate B' },
]

/**
 * A fake `useAppStatus` whose `check()` resolves to `answer`.
 *
 * @param {boolean} answer The availability the probe settles on.
 * @param {boolean} checked Whether the probe has already settled.
 * @return {object}
 */
function fakeStatus(answer, checked = false) {
	const status = {
		available: ref(checked ? answer : false),
		checked: ref(checked),
	}
	status.check = vi.fn(async () => {
		status.available.value = answer
		status.checked.value = true
		return answer
	})
	return status
}

/**
 * Mount the widget.
 *
 * @param {object} props Extra props.
 * @return {import('@vue/test-utils').Wrapper}
 */
function factory(props = {}) {
	return mount(DocumentActions, {
		propsData: { object, attachments, ...props },
		stubs: { NcButton: NcButtonStub },
	})
}

describe('DocumentActions generate flow', () => {
	beforeEach(() => {
		fakes.docs = {
			busyFor: vi.fn(() => false),
			errorFor: vi.fn(() => null),
			generate: vi.fn().mockResolvedValue(null),
		}
		fakes.status = fakeStatus(true, true)
	})

	it('probes Docudesk once on mount when no explicit flag is given', () => {
		factory()
		expect(fakes.status.check).toHaveBeenCalledTimes(1)
	})

	it('does not probe when the host passes an explicit flag', () => {
		factory({ docudeskAvailable: true })
		expect(fakes.status.check).not.toHaveBeenCalled()
	})

	it('hides the unavailable notice until the probe has settled', () => {
		fakes.status = fakeStatus(false, false)
		fakes.status.check = vi.fn(() => new Promise(() => {}))
		const wrapper = factory()
		expect(wrapper.find('.ob-document-actions__unavailable').exists()).toBe(false)
		expect(wrapper.findAll('.ob-document-actions__row')).toHaveLength(2)
	})

	it('shows the unavailable notice once the probe settles absent', async () => {
		fakes.status = fakeStatus(false, false)
		const wrapper = factory()
		await vi.waitFor(() => expect(fakes.status.checked.value).toBe(true))
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.ob-document-actions__unavailable').exists()).toBe(true)
		expect(wrapper.findAll('button')).toHaveLength(0)
	})

	it('awaits an unsettled probe before generating and generates when present', async () => {
		fakes.status = fakeStatus(true, false)
		// The mount-time probe has not settled yet when the user clicks.
		fakes.status.check.mockImplementationOnce(async () => false)
		const wrapper = factory()
		expect(fakes.status.checked.value).toBe(false)
		await wrapper.vm.onGenerate(attachments[0])
		expect(fakes.status.check).toHaveBeenCalledTimes(2)
		expect(fakes.docs.generate).toHaveBeenCalledWith(attachments[0], object)
	})

	it('issues no generate request when the probe settles absent at click time', async () => {
		fakes.status = fakeStatus(false, false)
		fakes.status.check = vi.fn(async () => false)
		const wrapper = factory()
		await wrapper.vm.onGenerate(attachments[0])
		expect(fakes.status.check).toHaveBeenCalledTimes(2)
		expect(fakes.docs.generate).not.toHaveBeenCalled()
	})

	it('generates without probing when the host says Docudesk is available', async () => {
		const wrapper = factory({ docudeskAvailable: true })
		await wrapper.findAll('button').at(1).trigger('click')
		expect(fakes.status.check).not.toHaveBeenCalled()
		expect(fakes.docs.generate).toHaveBeenCalledTimes(1)
		expect(fakes.docs.generate).toHaveBeenCalledWith(attachments[1], object)
	})

	it('respects an explicit false flag even when the probe would say present', async () => {
		const wrapper = factory({ docudeskAvailable: false })
		expect(wrapper.find('.ob-document-actions__unavailable').exists()).toBe(true)
		await wrapper.vm.onGenerate(attachments[0])
		expect(fakes.docs.generate).not.toHaveBeenCalled()
	})

	it('shows a busy, disabled button for the attachment being generated', () => {
		fakes.docs.busyFor = vi.fn((att) => att.id === 'a')
		const wrapper = factory({ docudeskAvailable: true })
		const buttons = wrapper.findAll('button')
		expect(buttons.at(0).text()).toBe('Generating…')
		expect(buttons.at(0).attributes('disabled')).toBeDefined()
		expect(buttons.at(1).text()).toBe('Generate B')
		expect(buttons.at(1).attributes('disabled')).toBeUndefined()
		expect(fakes.docs.busyFor).toHaveBeenCalledWith(attachments[0], object)
	})

	it.each([
		['no-access', 'You do not have access to generate this document.'],
		[
			'generate-failed',
			'Generating the document failed. The object is unchanged — you can try again.',
		],
	])('renders the %s error as an alert on its own row', (code, message) => {
		fakes.docs.errorFor = vi.fn((att) => (att.id === 'b' ? code : null))
		const wrapper = factory({ docudeskAvailable: true })
		const rows = wrapper.findAll('.ob-document-actions__row')
		expect(rows.at(0).find('[role="alert"]').exists()).toBe(false)
		const alert = rows.at(1).find('[role="alert"]')
		expect(alert.text()).toBe(message)
		expect(fakes.docs.errorFor).toHaveBeenCalledWith(attachments[1], object)
	})
})
