/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for ThemePickerDialog.vue.
 *
 * Spec: nldesign-theme-selection (REQ-NTS-002).
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

const { axiosMock } = vi.hoisted(() => ({ axiosMock: { get: vi.fn() } }))
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (p) => p,
	generateFilePath: (app, prefix, file) => `/${app}/${prefix}/${file}`,
}))

import ThemePickerDialog from '../../src/dialogs/ThemePickerDialog.vue'

const stubs = {
	NcDialog: { name: 'NcDialog', template: '<div><slot /><slot name="actions" /></div>' },
	NcButton: { name: 'NcButton', props: ['type', 'disabled'], template: '<button :disabled="disabled || false" @click="$emit(\'click\')"><slot /></button>' },
	NcSelect: { name: 'NcSelect', props: ['value', 'options', 'inputLabel'], template: '<div class="select-stub" />' },
	NcTextField: { name: 'NcTextField', props: ['value', 'label'], template: '<input class="text-stub" @input="$emit(\'update:value\', $event.target.value)" >' },
}

const adminList = [
	{ id: 'amsterdam', name: 'Gemeente Amsterdam', description: 'A', theming: { primary_color: '#004699', background_color: '#FFF' } },
	{ id: 'rijkshuisstijl', name: 'Rijkshuisstijl', theming: { primary_color: '#154273' } },
]

const factory = (props = {}) => mount(ThemePickerDialog, { propsData: { open: false, ...props }, stubs })

describe('ThemePickerDialog', () => {
	beforeEach(() => { axiosMock.get.mockReset() })

	it('populates the admin list and builds a theme on save', async () => {
		axiosMock.get.mockResolvedValueOnce({ data: adminList })
		const wrapper = factory()
		await wrapper.setProps({ open: true })
		await new Promise((r) => setTimeout(r, 0))
		expect(wrapper.vm.listAvailable).toBe(true)
		wrapper.vm.selectedOption = wrapper.vm.tokenSetOptions[0]
		await wrapper.vm.$nextTick()
		wrapper.vm.onSave()
		const saved = wrapper.emitted().save[0][0]
		expect(saved).toMatchObject({ source: 'nldesign', tokenSet: 'amsterdam', tokenSetName: 'Gemeente Amsterdam' })
		expect(saved.preview.primaryColor).toBe('#004699')
	})

	it('falls back to validated free-text on a 403 list probe', async () => {
		// Reset the module-level session probe by validating the asset fetch path.
		axiosMock.get.mockRejectedValueOnce({ response: { status: 403 } }) // list 403
		axiosMock.get.mockResolvedValueOnce({ data: ':root { --nldesign-color-primary: #154273; }' }) // asset 200
		const wrapper = factory()
		await wrapper.setProps({ open: true })
		await new Promise((r) => setTimeout(r, 0))
		expect(wrapper.vm.listAvailable).toBe(false)
		await wrapper.vm.onFreeTextInput('rijkshuisstijl')
		expect(wrapper.vm.freeTextError).toBe(false)
		expect(wrapper.vm.candidate).toMatchObject({ tokenSet: 'rijkshuisstijl', primaryColor: '#154273' })
	})

	it('rejects an unknown free-text id (404) inline', async () => {
		axiosMock.get.mockRejectedValueOnce({ response: { status: 404 } }) // asset 404
		const wrapper = factory()
		await wrapper.vm.onFreeTextInput('not-a-real-set')
		expect(wrapper.vm.freeTextError).toBe(true)
		expect(wrapper.vm.candidate).toBeNull()
	})

	it('emits clear for Default (Nextcloud)', () => {
		const wrapper = factory()
		wrapper.vm.onClearTheme()
		expect(wrapper.emitted().clear).toBeTruthy()
	})

	it('emits a preview revert (null) on cancel after a live preview', async () => {
		const wrapper = factory()
		wrapper.vm.livePreview = true
		wrapper.vm.onClose()
		expect(wrapper.emitted().preview.at(-1)[0]).toBeNull()
	})
})
