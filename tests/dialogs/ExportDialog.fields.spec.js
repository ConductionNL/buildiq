/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * ExportDialog driven through its template: the version, target and licence
 * pickers, the seed-data, data-register, flow and server-bundle switches and
 * the GitHub fields write the export form, and closing the dialog emits
 * `close` unless an export is being submitted.
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({
	default: {
		post: vi.fn(() => Promise.resolve({ data: { uuid: 'job' } })),
		get: vi.fn(() => Promise.resolve({ data: [] })),
	},
}))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))

import ExportDialog from '../../src/dialogs/ExportDialog.vue'

const stubs = {
	NcDialog: {
		emits: ['closing'],
		template: '<div><button class="dialog-close" @click="$emit(\'closing\')" /><slot /><slot name="actions" /></div>',
	},
	NcSelect: {
		props: ['modelValue', 'inputLabel', 'options'],
		emits: ['update:modelValue'],
		template: '<select :data-label="inputLabel" @change="$emit(\'update:modelValue\', options.find((o) => String(o.value) === $event.target.value))"><option v-for="o in options" :key="o.value" :value="o.value">{{ o.label }}</option></select>',
	},
	NcCheckboxRadioSwitch: {
		props: ['modelValue'],
		emits: ['update:modelValue'],
		template: '<label class="switch"><input type="checkbox" :checked="modelValue" @change="$emit(\'update:modelValue\', $event.target.checked)" /><slot /></label>',
	},
	NcTextField: {
		props: ['modelValue', 'label'],
		emits: ['update:modelValue'],
		template: '<input :data-label="label" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
	},
	NcButton: { template: '<button><slot /></button>' },
	NcNoteCard: { template: '<div><slot /></div>' },
	NcLoadingIcon: { template: '<span />' },
}

function mountDialog (props = {}) {
  return mount(ExportDialog, {
	props: { applicationSlug: 'permit-tracker', availableVersions: [{ id: 'v1', version: '1.0.0' }], ...props },
	global: { stubs },
})
}
const switchFor = (wrapper, text) => wrapper.findAll('.switch').find((s) => s.text().includes(text)).find('input')

describe('ExportDialog — fields', () => {
	it('writes the target, licence and seed-data choice', async () => {
		const wrapper = mountDialog()

		await wrapper.find('[data-label="Target"]').setValue('server')
		await wrapper.find('[data-label="License"]').setValue('MIT')
		await switchFor(wrapper, 'Include seed data').setValue(true)

		expect(wrapper.vm.form.target.value).toBe('server')
		expect(wrapper.vm.form.license).toEqual({ label: 'MIT', value: 'MIT' })
		expect(wrapper.vm.form.includeSeedData).toBe(true)
		expect(wrapper.find('[data-test="export-server-section"]').exists()).toBe(true)

		await switchFor(wrapper, 'Docker Compose file').setValue(true)
		expect(wrapper.vm.form.includeServerBundle).toBe(true)
	})

	it('switches row data on per register, and a flow off', async () => {
		const wrapper = mountDialog({
			dataRegisters: [{ register: 'bag-adressen', label: 'BAG' }],
			flows: [{ flow: 'notify-applicant', label: 'Notify applicant' }],
		})

		await wrapper.findAll('.switch')[1].find('input').setValue(true)
		await wrapper.find('[data-test="export-flow-notify-applicant"] input').setValue(false)

		expect(wrapper.vm.dataRegisterChoices[0].includeData).toBe(true)
		expect(wrapper.vm.flowChoices[0].include).toBe(false)
	})

	it('writes the GitHub organisation, repository and visibility', async () => {
		const wrapper = mountDialog()
		await wrapper.find('[data-label="Target"]').setValue('github')

		await wrapper.find('[data-label="GitHub organisation"]').setValue('gemeente-voorbeeld')
		await wrapper.find('[data-label="Repository name"]').setValue('permit-tracker')
		await wrapper.find('[data-label="Visibility"]').setValue(wrapper.vm.visibilityOptions[1].value)

		expect(wrapper.vm.form.githubOrg).toBe('gemeente-voorbeeld')
		expect(wrapper.vm.form.githubRepo).toBe('permit-tracker')
		expect(wrapper.vm.form.githubVisibility).toEqual(wrapper.vm.visibilityOptions[1])
	})

	it('emits close when the dialog closes, but not while an export is submitted', async () => {
		const wrapper = mountDialog()
		await wrapper.find('.dialog-close').trigger('click')
		expect(wrapper.emitted('close')).toHaveLength(1)

		wrapper.vm.submitting = true
		await wrapper.find('.dialog-close').trigger('click')
		expect(wrapper.emitted('close')).toHaveLength(1)
	})
})
