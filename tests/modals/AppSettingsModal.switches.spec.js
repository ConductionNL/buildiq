/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for `src/modals/AppSettingsModal.vue`'s switches, flows picker
 * and close: what each control emits, how stored flow bindings show in the
 * picker (including one whose flow no longer exists), and the empty-state hint.
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import AppSettingsModal from '../../src/modals/AppSettingsModal.vue'

const stubs = {
	NcModal: {
		name: 'NcModal',
		props: ['name'],
		emits: ['close'],
		template: '<div class="nc-modal-stub" :data-name="name"><slot /></div>',
	},
	NcButton: {
		name: 'NcButton',
		props: ['variant', 'disabled', 'ariaLabel'],
		emits: ['click'],
		template: '<button :disabled="disabled || undefined" @click="$emit(\'click\')"><slot /></button>',
	},
	NcCheckboxRadioSwitch: {
		name: 'NcCheckboxRadioSwitch',
		props: ['modelValue', 'type', 'disabled'],
		emits: ['update:modelValue'],
		template: '<label class="switch" :data-checked="String(modelValue)" :data-disabled="String(!!disabled)"><slot /></label>',
	},
	NcTextField: {
		name: 'NcTextField',
		props: ['modelValue', 'label', 'disabled'],
		emits: ['update:modelValue'],
		template: '<input :value="modelValue" :data-label="label">',
	},
	NcSelect: {
		name: 'NcSelect',
		props: ['modelValue', 'options', 'multiple', 'disabled', 'loading', 'inputLabel'],
		emits: ['update:modelValue'],
		template: '<div class="select" :data-disabled="String(!!disabled)" />',
	},
}

/**
 * Mount the modal.
 *
 * @param {object} props Props to override.
 * @return {import('@vue/test-utils').VueWrapper}
 */
function mountModal(props = {}) {
	return mount(AppSettingsModal, { props: { open: true, appName: 'Permits', ...props }, global: { stubs } })
}

/**
 * The switch whose label contains the text.
 *
 * @param {import('@vue/test-utils').VueWrapper} wrapper The modal.
 * @param {string} text The label text.
 * @return {import('@vue/test-utils').VueWrapper}
 */
function switchLabelled(wrapper, text) {
	return wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' }).find((s) => s.text().includes(text))
}

describe('AppSettingsModal switches and flows', () => {
	it('renders nothing while closed', () => {
		expect(mountModal({ open: false }).find('.nc-modal-stub').exists()).toBe(false)
	})

	it('names the app and closes through the modal', async () => {
		const wrapper = mountModal()
		expect(wrapper.find('.app-settings__title').text()).toBe('Permits')
		await wrapper.findComponent({ name: 'NcModal' }).vm.$emit('close')
		expect(wrapper.emitted('update:open')).toEqual([[false]])
	})

	it('shows and emits the published and per-user switches', async () => {
		const wrapper = mountModal({ isPublished: true, allowUserOverrides: false })
		const published = switchLabelled(wrapper, 'Published')
		const overrides = switchLabelled(wrapper, 'Allow per-user customisation')
		expect(published.props('modelValue')).toBe(true)
		expect(overrides.props('modelValue')).toBe(false)

		await published.vm.$emit('update:modelValue', false)
		await overrides.vm.$emit('update:modelValue', true)
		expect(wrapper.emitted('set-published')).toEqual([[false]])
		expect(wrapper.emitted('update:allow-overrides')).toEqual([[true]])
	})

	it('disables every control while busy', () => {
		const wrapper = mountModal({ busy: true, dataRegisters: [{ register: 'r' }] })
		// Published, per-user customisation and embedding (apps-embed-in-other-sites).
		expect(wrapper.findAll('.switch').map((s) => s.attributes('data-disabled'))).toEqual(['true', 'true', 'true'])
		expect(wrapper.findAll('button').every((b) => b.attributes('disabled') !== undefined)).toBe(true)
		expect(wrapper.find('.select').attributes('data-disabled')).toBe('true')
	})

	it('shows stored flows by their option, or names one that no longer exists', () => {
		const wrapper = mountModal({
			flows: [{ flow: 'f-1' }, { flow: 'f-gone', label: 'Old intake' }, { flow: 'f-lost' }],
			availableFlows: [{ value: 'f-1', label: 'Intake' }, { value: 'f-2', label: 'Review' }],
		})
		expect(wrapper.findComponent({ name: 'NcSelect' }).props('modelValue')).toEqual([
			{ value: 'f-1', label: 'Intake' },
			{ value: 'f-gone', label: 'Old intake' },
			{ value: 'f-lost', label: '{uuid} (no longer exists)' },
		])
	})

	it('emits the picked flows as bindings, and none when cleared', async () => {
		const wrapper = mountModal({ availableFlows: [{ value: 'f-1', label: 'Intake' }] })
		const select = wrapper.findComponent({ name: 'NcSelect' })
		await select.vm.$emit('update:modelValue', [{ value: 'f-1', label: 'Intake' }])
		await select.vm.$emit('update:modelValue', null)
		expect(wrapper.emitted('update:flows')).toEqual([[[{ flow: 'f-1', label: 'Intake' }]], [[]]])
	})

	it('hints that no flows exist only once loading has finished', () => {
		expect(mountModal({ loadingFlows: true }).text()).not.toContain('No flows exist on this instance yet.')
		const empty = mountModal({ loadingFlows: false, availableFlows: [] })
		expect(empty.text()).toContain('No flows exist on this instance yet. Create one in OpenRegister first.')
		const some = mountModal({ availableFlows: [{ value: 'f-1', label: 'Intake' }] })
		expect(some.text()).not.toContain('No flows exist on this instance yet.')
	})

	it('adds, edits and removes data-register rows from its fields and buttons', async () => {
		const wrapper = mountModal({ dataRegisters: [{ register: 'spectr' }] })
		const add = wrapper.findAll('button').find((b) => b.text() === 'Add data register')
		await add.trigger('click')
		const fields = wrapper.findAllComponents({ name: 'NcTextField' })
		await fields[3].vm.$emit('update:modelValue', 'Market data')
		await fields[2].vm.$emit('update:modelValue', ' leads ')
		const remove = wrapper.findAll('button').filter((b) => b.text() === 'Remove')
		await remove[0].trigger('click')

		const emitted = wrapper.emitted('update:data-registers')
		expect(emitted[emitted.length - 2][0]).toEqual([{ register: 'spectr' }, { register: 'leads', label: 'Market data' }])
		expect(emitted[emitted.length - 1][0]).toEqual([{ register: 'leads', label: 'Market data' }])
	})

	it('treats a data-registers value that is not a list as no rows', () => {
		const wrapper = mountModal({ dataRegisters: null })
		expect(wrapper.findAllComponents({ name: 'NcTextField' })).toHaveLength(0)
	})
})
