/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for LinkRepoDialog's close guard and input bindings, which the
 * submit-focused specs do not exercise.
 *
 * Covers:
 *  - onClose emits `close` when idle.
 *  - onClose does NOT emit while a request is in flight.
 *  - the owner and name fields write their value back onto the component.
 */

import { mount } from '@vue/test-utils'
import { afterAll, beforeAll, describe, expect, it } from 'vitest'
import LinkRepoDialog from '../../src/modals/LinkRepoDialog.vue'

const realT = globalThis.t
beforeAll(() => {
	globalThis.t = (_app, key) => key
})
afterAll(() => {
	globalThis.t = realT
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
	NcTextField: {
		name: 'NcTextField',
		props: ['modelValue', 'label', 'placeholder'],
		emits: ['update:modelValue'],
		template: '<input class="nc-text-stub" :value="modelValue" />',
	},
}

/**
 * Mount the dialog already open.
 *
 * @return {Promise<object>} The mounted wrapper.
 */
async function mountDialog() {
	const wrapper = mount(LinkRepoDialog, {
		props: { open: false, slug: 'shop' },
		global: { stubs: STUBS },
	})
	await wrapper.setProps({ open: true })
	await wrapper.vm.$nextTick()
	return wrapper
}

describe('LinkRepoDialog close guard and bindings', () => {
	it('emits close when idle', async () => {
		const wrapper = await mountDialog()
		wrapper.vm.onClose()

		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('does not emit close while submitting', async () => {
		const wrapper = await mountDialog()
		wrapper.vm.submitting = true
		wrapper.vm.onClose()

		expect(wrapper.emitted('close')).toBeUndefined()
	})

	it('writes the owner and name fields back onto the component', async () => {
		const wrapper = await mountDialog()
		const fields = wrapper.findAllComponents({ name: 'NcTextField' })
		fields[0].vm.$emit('update:modelValue', 'ConductionNL')
		fields[1].vm.$emit('update:modelValue', 'buildiq-shop')
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.owner).toBe('ConductionNL')
		expect(wrapper.vm.name).toBe('buildiq-shop')
	})
})
