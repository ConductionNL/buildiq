/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for PermissionsModal's three group-picker v-model bindings and
 * the availableGroups prop default, which the behaviour spec does not exercise.
 *
 * Covers:
 *  - picking options in the owners/editors/viewers selects writes each model.
 *  - availableGroups defaults to an empty array when the prop is omitted.
 */

import { mount } from '@vue/test-utils'
import { afterAll, beforeAll, describe, expect, it } from 'vitest'
import PermissionsModal from '../../../src/modals/PermissionsModal.vue'

const realT = globalThis.t
beforeAll(() => {
	globalThis.t = (_app, key) => key
})
afterAll(() => {
	globalThis.t = realT
})

const STUBS = {
	NcDialog: {
		name: 'NcDialog',
		props: ['name', 'open', 'size'],
		emits: ['update:open'],
		template: '<div class="nc-dialog-stub"><slot /></div>',
	},
	NcButton: {
		name: 'NcButton',
		props: ['variant', 'disabled'],
		template: '<button class="nc-button-stub" @click="$emit(\'click\', $event)"><slot /></button>',
	},
	NcSelect: {
		name: 'NcSelect',
		props: ['modelValue', 'options', 'multiple', 'inputLabel'],
		emits: ['update:modelValue'],
		template: '<div class="nc-select-stub" />',
	},
}

/**
 * Mount the modal.
 *
 * @param {object} props Extra props to merge over the defaults.
 *
 * @return {object} The mounted wrapper.
 */
function mountModal(props = {}) {
	return mount(PermissionsModal, {
		props: { open: true, ...props },
		global: { stubs: STUBS },
	})
}

describe('PermissionsModal bindings', () => {
	it('writes each select back onto its model', async () => {
		const wrapper = mountModal()
		const selects = wrapper.findAllComponents({ name: 'NcSelect' })
		expect(selects).toHaveLength(3)

		selects[0].vm.$emit('update:modelValue', [{ value: 'admins', label: 'admins' }])
		selects[1].vm.$emit('update:modelValue', [{ value: 'editors', label: 'editors' }])
		selects[2].vm.$emit('update:modelValue', [{ value: 'everyone', label: 'everyone' }])
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.ownersModel).toEqual([{ value: 'admins', label: 'admins' }])
		expect(wrapper.vm.editorsModel).toEqual([{ value: 'editors', label: 'editors' }])
		expect(wrapper.vm.viewersModel).toEqual([{ value: 'everyone', label: 'everyone' }])
	})

	it('defaults availableGroups to an empty array when omitted', () => {
		const wrapper = mountModal()

		expect(wrapper.props('availableGroups')).toEqual([])
	})
})
