/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * PromoteVersionDialog driven through its template: each strategy radio
 * selects its strategy, the destructive empty start asks for the typed slug,
 * and closing the dialog cancels.
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import PromoteVersionDialog from '../../src/dialogs/PromoteVersionDialog.vue'

const stubs = {
	NcDialog: {
		emits: ['closing'],
		template: '<div><button class="dialog-close" @click="$emit(\'closing\')" /><slot /><slot name="actions" /></div>',
	},
	NcCheckboxRadioSwitch: {
		props: ['modelValue', 'value', 'name', 'type'],
		emits: ['update:modelValue'],
		template: '<label class="radio"><input type="radio" :name="name" :value="value" :checked="modelValue === value" @change="$emit(\'update:modelValue\', value)" /><slot /></label>',
	},
	NcTextField: {
		props: ['modelValue', 'label'],
		emits: ['update:modelValue'],
		template: '<input class="typed-slug" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
	},
	NcButton: { props: ['disabled'], template: '<button :disabled="disabled"><slot /></button>' },
	NcNoteCard: { template: '<div><slot /></div>' },
}

function mountDialog () {
  return mount(PromoteVersionDialog, {
	props: {
		sourceVersion: { id: 'src', uuid: 'src', name: 'Development', slug: 'development' },
		targetVersion: { id: 'prod', uuid: 'prod', name: 'Production', slug: 'production' },
		application: { slug: 'hello-world', productionVersion: 'prod' },
	},
	global: { stubs },
})
}

describe('PromoteVersionDialog — fields', () => {
	it('selects each strategy through its radio, and asks for the slug before an empty start', async () => {
		const wrapper = mountDialog()
		const radio = (value) => wrapper.find(`input[value="${value}"]`)

		await radio('start-with-source-data').setValue(true)
		expect(wrapper.vm.selectedStrategy).toBe('start-with-source-data')
		expect(wrapper.find('.typed-slug').exists()).toBe(false)

		await radio('migrate-existing-data').setValue(true)
		expect(wrapper.vm.selectedStrategy).toBe('migrate-existing-data')

		await radio('empty-start').setValue(true)
		expect(wrapper.vm.selectedStrategy).toBe('empty-start')
		await wrapper.find('.typed-slug').setValue('hello-world')
		expect(wrapper.vm.typedSlug).toBe('hello-world')
	})

	it('cancels when the dialog closes', async () => {
		const wrapper = mountDialog()
		await wrapper.find('.dialog-close').trigger('click')
		expect(wrapper.emitted('cancel')).toHaveLength(1)
	})
})
