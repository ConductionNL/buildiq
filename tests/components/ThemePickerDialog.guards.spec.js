import { mount } from '@vue/test-utils'
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest: ThemePickerDialog takes over the stored theme when it opens, ends a
 * live preview when it closes, and without a chosen token set neither checks
 * contrast nor saves (spec nldesign-theme-selection).
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const { axiosMock } = vi.hoisted(() => ({
	axiosMock: { get: vi.fn(), post: vi.fn() },
}))
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
	generateFilePath: (app, prefix, file) => `/${app}/${prefix}/${file}`,
}))

import ThemePickerDialog from '../../src/dialogs/ThemePickerDialog.vue'

const stubs = {
	NcDialog: {
		name: 'NcDialog',
		template: '<div><slot /><slot name="actions" /></div>',
	},
	NcButton: {
		name: 'NcButton',
		props: ['type', 'disabled'],
		template:
			'<button :disabled="disabled || false" @click="$emit(\'click\')"><slot /></button>',
	},
	NcSelect: {
		name: 'NcSelect',
		props: ['value', 'options', 'inputLabel'],
		template: '<div class="select-stub" />',
	},
}

function factory(props = {}) {
	return mount(ThemePickerDialog, { propsData: { open: false, ...props }, stubs })
}

describe('ThemePickerDialog without a choice', () => {
	beforeEach(() => {
		axiosMock.get.mockReset().mockResolvedValue({ data: { tokenSets: [] } })
		axiosMock.post.mockReset()
	})

	it('opening takes over the stored theme as the selection', async () => {
		const wrapper = factory({
			theme: { tokenSet: 'amsterdam', tokenSetName: 'Gemeente Amsterdam', preview: { primaryColor: '#004699' } },
		})
		await wrapper.setProps({ open: true })

		expect(wrapper.vm.selectedOption).toMatchObject({
			id: 'amsterdam',
			name: 'Gemeente Amsterdam',
			primaryColor: '#004699',
			backgroundColor: '',
		})
	})

	it('opening without a stored theme selects nothing', async () => {
		const wrapper = factory({ theme: null })
		wrapper.vm.selectedOption = { id: 'stale', name: 'Stale' }

		await wrapper.setProps({ open: true })

		expect(wrapper.vm.selectedOption).toBeNull()
		expect(wrapper.vm.candidate).toBeNull()
	})

	it('closing ends a live preview once', async () => {
		const wrapper = factory()
		await wrapper.setProps({ open: true })
		wrapper.vm.livePreview = true

		await wrapper.setProps({ open: false })

		expect(wrapper.vm.livePreview).toBe(false)
		expect(wrapper.emitted('preview')).toEqual([[null]])
	})

	it('without a candidate there is no contrast check, no theme and no save', async () => {
		const wrapper = factory()
		wrapper.vm.selectedOption = null
		wrapper.vm.contrastResults = { stale: true }

		await wrapper.vm.evaluateCandidateContrast()
		expect(wrapper.vm.contrastResults).toBeNull()
		expect(wrapper.vm.buildTheme()).toBeNull()

		wrapper.vm.onSave()
		expect(wrapper.emitted('save')).toBeUndefined()
		expect(wrapper.emitted('update:open')).toBeUndefined()
	})

	it('a candidate without a primary colour is not contrast-checked', async () => {
		const wrapper = factory()
		wrapper.vm.selectedOption = { id: 'plain', name: 'Plain', primaryColor: '' }
		await wrapper.vm.$nextTick()

		await wrapper.vm.evaluateCandidateContrast()

		expect(wrapper.vm.contrastResults).toBeNull()
	})
})
