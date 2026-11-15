/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for SaveCancelActions.vue: Cancel emits `cancel`, Save emits
 * `save`, and while `saving` the Save button is disabled and reads "Saving…".
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import SaveCancelActions from '../../src/components/SaveCancelActions.vue'

const NcButtonStub = {
	name: 'NcButton',
	props: ['variant', 'disabled'],
	emits: ['click'],
	template: '<button :disabled="disabled || false" :data-variant="variant" @click="$emit(\'click\')"><slot /></button>',
}

function mountWith(props = {}) {
	return mount(SaveCancelActions, { props, global: { stubs: { NcButton: NcButtonStub } } })
}

describe('SaveCancelActions', () => {
	it('renders Cancel and an enabled primary Save by default', () => {
		const buttons = mountWith().findAll('button')
		expect(buttons.map((b) => b.text())).toEqual(['Cancel', 'Save'])
		expect(buttons[1].attributes('data-variant')).toBe('primary')
		expect(buttons[1].attributes('disabled')).toBeUndefined()
	})

	it('emits cancel only from the Cancel button', async () => {
		const wrapper = mountWith()
		await wrapper.findAll('button')[0].trigger('click')
		expect(wrapper.emitted('cancel')).toHaveLength(1)
		expect(wrapper.emitted('save')).toBeUndefined()
	})

	it('emits save only from the Save button', async () => {
		const wrapper = mountWith()
		await wrapper.findAll('button')[1].trigger('click')
		expect(wrapper.emitted('save')).toHaveLength(1)
		expect(wrapper.emitted('cancel')).toBeUndefined()
	})

	it('disables Save and shows Saving… while saving, keeping Cancel usable', async () => {
		const wrapper = mountWith({ saving: true })
		const [cancel, save] = wrapper.findAll('button')
		expect(save.text()).toBe('Saving…')
		expect(save.attributes('disabled')).toBeDefined()
		expect(cancel.attributes('disabled')).toBeUndefined()
		await cancel.trigger('click')
		expect(wrapper.emitted('cancel')).toHaveLength(1)
	})

	it('returns to Save once saving ends', async () => {
		const wrapper = mountWith({ saving: true })
		await wrapper.setProps({ saving: false })
		const save = wrapper.findAll('button')[1]
		expect(save.text()).toBe('Save')
		expect(save.attributes('disabled')).toBeUndefined()
	})
})
