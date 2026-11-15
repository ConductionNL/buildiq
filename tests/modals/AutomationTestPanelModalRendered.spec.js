/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest rendering spec for AutomationTestPanelModal — the dry-run result,
 * running state, error note, close affordance and payload binding that the
 * behaviour spec (AutomationTestPanelModal.spec.js) does not render.
 *
 * Covers:
 *  - the Run button reads "Running…" while a run is in flight.
 *  - a matched result lists each would-be action.
 *  - an error message is shown in the note card.
 *  - closing the modal emits `close`.
 *  - editing the payload field updates payloadText.
 */

import { mount } from '@vue/test-utils'
import { beforeAll, describe, expect, it } from 'vitest'
import AutomationTestPanelModal from '../../src/modals/AutomationTestPanelModal.vue'

beforeAll(() => {
	globalThis.t = (_app, key) => key
})

const STUBS = {
	NcModal: {
		name: 'NcModal',
		props: ['size'],
		emits: ['close'],
		template: '<div class="nc-modal-stub"><slot /></div>',
	},
	NcButton: {
		name: 'NcButton',
		props: ['variant', 'disabled'],
		template: '<button class="nc-button-stub" :disabled="disabled" @click="$emit(\'click\', $event)"><slot /></button>',
	},
	NcTextArea: {
		name: 'NcTextArea',
		props: ['modelValue', 'label'],
		emits: ['update:modelValue'],
		template: '<textarea class="nc-textarea-stub" :value="modelValue" />',
	},
	NcNoteCard: {
		name: 'NcNoteCard',
		props: ['type'],
		template: '<div class="nc-note-stub"><slot /></div>',
	},
}

/**
 * Mount the modal with a given automation.
 *
 * @param {object} automation The automation prop.
 *
 * @return {object} The mounted wrapper.
 */
function mountModal(automation = { id: 'auto-1', name: 'Route for approval' }) {
	return mount(AutomationTestPanelModal, {
		props: { automation },
		global: { stubs: STUBS },
	})
}

describe('AutomationTestPanelModal rendering', () => {
	it('shows "Running…" on the run button while a run is in flight', async () => {
		const wrapper = mountModal()
		expect(wrapper.find('[data-testid="dry-run-button"]').text()).toBe('Run')

		wrapper.vm.running = true
		await wrapper.vm.$nextTick()

		expect(wrapper.find('[data-testid="dry-run-button"]').text()).toBe('Running…')
	})

	it('lists each would-be action when the condition matched', async () => {
		const wrapper = mountModal()
		wrapper.vm.result = {
			conditionMatched: true,
			actions: ['notify owner', 'open approval'],
			errors: ['minor'],
			durationMs: 7,
			approvalState: 'pending',
		}
		await wrapper.vm.$nextTick()

		const items = wrapper.findAll('[data-testid="dry-run-action"]')
		expect(items).toHaveLength(2)
		expect(items.map((li) => li.text())).toEqual(['notify owner', 'open approval'])
		// The approval-state line renders for a non-"none" state.
		expect(wrapper.find('[data-testid="dry-run-approval-state"]').exists()).toBe(true)
	})

	it('renders the error note when a run fails', async () => {
		const wrapper = mountModal()
		wrapper.vm.errorMessage = 'Dry-run failed.'
		await wrapper.vm.$nextTick()

		expect(wrapper.text()).toContain('Dry-run failed.')
	})

	it('emits close when the modal is dismissed', async () => {
		const wrapper = mountModal()
		wrapper.findComponent({ name: 'NcModal' }).vm.$emit('close')

		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('binds the payload field to payloadText', async () => {
		const wrapper = mountModal()
		wrapper.findComponent({ name: 'NcTextArea' }).vm.$emit('update:modelValue', '{"a":1}')
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.payloadText).toBe('{"a":1}')
	})
})
