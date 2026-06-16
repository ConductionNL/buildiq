/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for `src/modals/CloneTemplateDialog.vue`.
 *
 * Covers:
 *   - submit emits the expected payload when input is valid
 *   - slug-shape validation rejects non-kebab-case or > 32 char slugs
 *     and does NOT emit `submit`
 *   - error rendering surfaces an external error set via `setError`
 *     (so the parent gallery can hand the server-side error to the dialog
 *     after a clone POST fails)
 */

import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

import CloneTemplateDialog from '../../src/modals/CloneTemplateDialog.vue'

const baseStubs = {
	NcModal: {
		name: 'NcModal',
		props: ['size'],
		// Render the slot so the inner controls are reachable. Default-slot
		// is the only one used by the dialog.
		template: '<div class="nc-modal-stub"><slot /></div>',
	},
	NcButton: {
		name: 'NcButton',
		props: ['type', 'disabled'],
		template: '<button :disabled="disabled" :data-type="type" @click="$emit(\'click\', $event)"><slot /></button>',
	},
	NcTextField: {
		name: 'NcTextField',
		props: ['value', 'label', 'placeholder'],
		template: '<input class="nc-textfield-stub" :data-label="label" :value="value" @input="$emit(\'update:value\', $event.target.value)" />',
	},
}

/**
 * Mount helper.
 *
 * @param {object} props initial props
 * @return {import('@vue/test-utils').Wrapper}
 */
function mountDialog(props = {}) {
	return mount(CloneTemplateDialog, {
		propsData: {
			open: true,
			template: { slug: 'permit-tracker', title: 'Permit Tracker' },
			...props,
		},
		stubs: baseStubs,
	})
}

describe('CloneTemplateDialog.vue', () => {
	it('emits submit with the trimmed name/slug payload when input is valid', async () => {
		const wrapper = mountDialog()

		wrapper.vm.localName = 'My permits'
		wrapper.vm.localSlug = 'my-permits'
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.canSubmit).toBe(true)

		await wrapper.vm.submit()

		const submitEvents = wrapper.emitted('submit')
		expect(submitEvents).toBeTruthy()
		expect(submitEvents.length).toBe(1)
		expect(submitEvents[0][0]).toEqual({ name: 'My permits', slug: 'my-permits' })
	})

	it('does not emit submit and surfaces an error when the slug shape is invalid', async () => {
		const wrapper = mountDialog()

		wrapper.vm.localName = 'My permits'
		// Uppercase + spaces — invalid kebab-case.
		wrapper.vm.localSlug = 'My Permits'
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.canSubmit).toBe(false)
		await wrapper.vm.submit()

		expect(wrapper.emitted('submit')).toBeUndefined()
		expect(wrapper.vm.error.length).toBeGreaterThan(0)
	})

	it('rejects slugs longer than 32 characters', async () => {
		const wrapper = mountDialog()

		wrapper.vm.localName = 'X'
		// 33 chars of kebab-case.
		wrapper.vm.localSlug = 'a'.repeat(33)
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.canSubmit).toBe(false)
	})

	it('renders an external error message set via setError', async () => {
		const wrapper = mountDialog()

		wrapper.vm.setError('Slug already in use')
		await wrapper.vm.$nextTick()

		const error = wrapper.find('.clone-dialog__error')
		expect(error.exists()).toBe(true)
		expect(error.text()).toBe('Slug already in use')
		// submitting is reset so the button re-enables.
		expect(wrapper.vm.submitting).toBe(false)
	})
})
