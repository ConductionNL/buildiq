import { mount } from '@vue/test-utils'
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for ThemePickerDialog.vue.
 *
 * Spec: theme-picker-consumes-nldesign, delta of nldesign-theme-selection
 * (REQ-NTS-002, REQ-NTS-006, REQ-NTS-008).
 *
 * `useScopedTheme` is imported from `@conduction/nextcloud-vue`, which
 * vitest aliases to `tests/vitest/stubs/conduction-nextcloud-vue.js` — that
 * stub re-exports the REAL published `useScopedTheme` leaf (subpath import,
 * bypassing the alias), so these mounts exercise the actual beta.221
 * `listTokenSets`/`evaluateContrast` logic against a mocked `@nextcloud/axios`
 * HTTP boundary, not a hand-rolled fake.
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

const catalogue = [
	{
		id: 'amsterdam',
		name: 'Gemeente Amsterdam',
		design_system: 'nldesign',
		theming: { primary_color: '#004699', background_color: '#FFFFFF' },
	},
	{
		id: 'rijkshuisstijl',
		name: 'Rijkshuisstijl',
		design_system: 'nldesign',
		theming: { primary_color: '#154273' },
	},
]

function factory(props = {}) {
	return mount(ThemePickerDialog, { propsData: { open: false, ...props }, stubs })
}

describe('ThemePickerDialog', () => {
	beforeEach(() => {
		axiosMock.get.mockReset()
		axiosMock.post.mockReset()
	})

	it('populates the catalogue via listTokenSets() and builds a theme on save (REQ-NTS-002)', async () => {
		axiosMock.get.mockResolvedValueOnce({ data: { tokenSets: catalogue } })
		const wrapper = factory()
		await wrapper.setProps({ open: true })
		await new Promise((r) => setTimeout(r, 0))
		expect(wrapper.vm.tokenSetOptions).toHaveLength(2)
		wrapper.vm.selectedOption = wrapper.vm.tokenSetOptions[0]
		await wrapper.vm.$nextTick()
		wrapper.vm.onSave()
		const saved = wrapper.emitted().save[0][0]
		expect(saved).toMatchObject({
			source: 'nldesign',
			tokenSet: 'amsterdam',
			tokenSetName: 'Gemeente Amsterdam',
		})
		expect(saved.preview.primaryColor).toBe('#004699')
	})

	it('renders the absence hint (not a free-text fallback) when listTokenSets() resolves [] (REQ-NTS-002/005)', async () => {
		axiosMock.get.mockResolvedValueOnce({ data: { tokenSets: [] } })
		const wrapper = factory()
		await wrapper.setProps({ open: true })
		await new Promise((r) => setTimeout(r, 0))
		expect(wrapper.vm.tokenSetOptions).toHaveLength(0)
		expect(wrapper.find('.ob-theme-picker__hint').exists()).toBe(true)
		expect(wrapper.find('.text-stub').exists()).toBe(false)
		expect(wrapper.find('.select-stub').exists()).toBe(false)
		expect(wrapper.vm.candidate).toBeNull()
	})

	it('renders the absence hint when listTokenSets() fails (network error collapses to the same [] state)', async () => {
		axiosMock.get.mockRejectedValueOnce(new Error('network down'))
		const wrapper = factory()
		await wrapper.setProps({ open: true })
		await new Promise((r) => setTimeout(r, 0))
		expect(wrapper.vm.tokenSetOptions).toHaveLength(0)
		expect(wrapper.find('.ob-theme-picker__hint').exists()).toBe(true)
	})

	it('emits clear for Default (Nextcloud)', () => {
		const wrapper = factory()
		wrapper.vm.onClearTheme()
		expect(wrapper.emitted().clear).toBeTruthy()
	})

	it('emits the candidate on preview toggle and a revert (null) on cancel', async () => {
		axiosMock.get.mockResolvedValueOnce({ data: { tokenSets: catalogue } })
		const wrapper = factory()
		await wrapper.setProps({ open: true })
		await new Promise((r) => setTimeout(r, 0))
		wrapper.vm.selectedOption = wrapper.vm.tokenSetOptions[0]
		wrapper.vm.livePreview = true
		wrapper.vm.onPreviewToggle()
		expect(wrapper.emitted().preview[0][0]).toMatchObject({
			tokenSet: 'amsterdam',
		})
		wrapper.vm.onClose()
		expect(wrapper.emitted().preview.at(-1)[0]).toBeNull()
	})

	it('disables the preview toggle with a hint when previewAvailable is false (design.md OQ-1, task 3.3)', () => {
		const wrapper = factory({ previewAvailable: false })
		const checkbox = wrapper.find('.ob-theme-picker__toggle input')
		expect(checkbox.attributes('disabled')).toBeDefined()
		expect(wrapper.text()).toContain(
			'Live preview is not available in this designer session.',
		)
	})

	it('shows warn-only contrast facts without disabling Save (REQ-NTS-008)', async () => {
		axiosMock.get.mockResolvedValueOnce({ data: { tokenSets: catalogue } })
		axiosMock.post.mockResolvedValueOnce({
			data: {
				results: [
					{ name: 'Primary', ratio: 2.1, level: 'fail', pass: false },
				],
			},
		})
		const wrapper = factory()
		await wrapper.setProps({ open: true })
		await new Promise((r) => setTimeout(r, 0))
		wrapper.vm.selectedOption = wrapper.vm.tokenSetOptions[0]
		await new Promise((r) => setTimeout(r, 0))
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.contrastResults).toEqual([
			{ name: 'Primary', ratio: 2.1, level: 'fail', pass: false },
		])
		const saveBtn = wrapper.findAll('button').at(2)
		expect(saveBtn.attributes('disabled')).toBeUndefined()
	})

	it('never calls any nldesign settings/* route or a direct css/tokens fetch (REQ-NTS-006)', async () => {
		axiosMock.get.mockResolvedValueOnce({ data: { tokenSets: catalogue } })
		const wrapper = factory()
		await wrapper.setProps({ open: true })
		await new Promise((r) => setTimeout(r, 0))
		wrapper.vm.selectedOption = wrapper.vm.tokenSetOptions[0]
		await new Promise((r) => setTimeout(r, 0))
		for (const call of axiosMock.get.mock.calls) {
			expect(String(call[0])).not.toMatch(
				/\/settings\/tokensets|\/settings\/tokenset-preview|css\/tokens\//,
			)
		}
	})
})
