/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for `src/modals/AppSettingsModal.vue`'s Languages section
 * (experience-multilingual-apps, REQ-BQML-001, task T03): picking the written
 * language, adding a language, and removing one only after confirmation.
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import AppSettingsModal from '../../src/modals/AppSettingsModal.vue'

const stubs = {
	NcModal: { name: 'NcModal', props: ['name'], template: '<div><slot /></div>' },
	NcButton: {
		name: 'NcButton',
		props: ['variant', 'disabled', 'ariaLabel'],
		emits: ['click'],
		template: '<button :disabled="disabled || undefined" @click="$emit(\'click\')"><slot /></button>',
	},
	NcCheckboxRadioSwitch: { name: 'NcCheckboxRadioSwitch', props: ['modelValue'], template: '<span />' },
	NcTextField: { name: 'NcTextField', props: ['modelValue'], template: '<input>' },
	NcSelect: {
		name: 'NcSelect',
		props: ['modelValue', 'options', 'multiple', 'disabled', 'loading', 'inputLabel', 'clearable'],
		emits: ['update:modelValue'],
		template: '<div class="select" :data-label="inputLabel" :data-disabled="String(!!disabled)" />',
	},
}

/**
 * Mount the modal with an app written in Dutch.
 *
 * @param {object} props Props to override.
 * @return {import('@vue/test-utils').VueWrapper}
 */
function mountModal(props = {}) {
	return mount(AppSettingsModal, {
		props: {
			open: true,
			appName: 'Vergunningen',
			sourceLanguage: 'nl',
			languages: ['de'],
			canEditLanguages: true,
			...props,
		},
		global: { stubs },
	})
}

/**
 * The NcSelect with the given input label.
 *
 * @param {import('@vue/test-utils').VueWrapper} wrapper The modal.
 * @param {string} label The input label.
 * @return {import('@vue/test-utils').VueWrapper}
 */
function select(wrapper, label) {
	return wrapper.findAllComponents({ name: 'NcSelect' }).find((s) => s.props('inputLabel') === label)
}

describe('AppSettingsModal languages (REQ-BQML-001)', () => {
	it('shows the written language and the translated ones', () => {
		const wrapper = mountModal()

		expect(select(wrapper, 'Labels are written in').props('modelValue')).toEqual({ label: 'Dutch (nl)', value: 'nl' })
		expect(wrapper.findAll('[data-test="app-settings-language"]').map((li) => li.find('span').text())).toEqual(['German (de)'])
	})

	it('adds English to a Dutch app (scenario)', async () => {
		const wrapper = mountModal({ languages: [] })
		const add = select(wrapper, 'Add a language')

		expect(add.props('options').some((o) => o.value === 'nl')).toBe(false)
		await add.vm.$emit('update:modelValue', { label: 'English (en)', value: 'en' })

		expect(wrapper.emitted('update:languages')).toEqual([[{ sourceLanguage: 'nl', languages: ['en'] }]])
	})

	it('drops a language from the translated ones when it becomes the written one', async () => {
		const wrapper = mountModal({ languages: ['en', 'de'] })

		await select(wrapper, 'Labels are written in').vm.$emit('update:modelValue', { value: 'en' })

		expect(wrapper.emitted('update:languages')).toEqual([[{ sourceLanguage: 'en', languages: ['de'] }]])
	})

	it('asks before removing a language, and emits only on confirmation', async () => {
		const wrapper = mountModal({ languages: ['en', 'de'] })

		await wrapper.findAll('[data-test="app-settings-language-remove"]')[1].trigger('click')
		expect(wrapper.emitted('update:languages')).toBeUndefined()
		const germanRow = wrapper.findAll('[data-test="app-settings-language"]')[1]
		expect(germanRow.text()).toContain('German (de)')
		expect(germanRow.find('[role="alert"]').exists()).toBe(true)

		await wrapper.find('[data-test="app-settings-language-confirm-remove"]').trigger('click')
		expect(wrapper.emitted('update:languages')).toEqual([[{ sourceLanguage: 'nl', languages: ['en'] }]])
		expect(wrapper.find('[role="alert"]').exists()).toBe(false)
	})

	it('disables the language controls for a viewer who may not change them', () => {
		const wrapper = mountModal({ canEditLanguages: false })

		expect(select(wrapper, 'Add a language').props('disabled')).toBe(true)
		expect(select(wrapper, 'Labels are written in').props('disabled')).toBe(true)
		expect(wrapper.find('[data-test="app-settings-language-remove"]').attributes('disabled')).toBeDefined()
	})

	it('asks for the languages each time it opens', async () => {
		const wrapper = mountModal({ open: false })
		expect(wrapper.emitted('loadLanguages')).toBeUndefined()

		await wrapper.setProps({ open: true })
		await wrapper.setProps({ open: false })
		await wrapper.setProps({ open: true })

		expect(wrapper.emitted('loadLanguages')).toHaveLength(2)
	})
})
