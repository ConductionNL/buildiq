/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for ThemeSection.vue: the Change and Remove buttons and the
 * events the picker and confirm dialogs send back, driven through the
 * rendered children rather than the component's methods.
 *
 * Spec: nldesign-theme-selection (REQ-NTS-002, REQ-NTS-005).
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import ThemeSection from '../../src/components/ThemeSection.vue'

const NcButtonStub = {
	name: 'NcButton',
	props: ['disabled', 'title', 'variant'],
	emits: ['click'],
	template: '<button :disabled="disabled || false" @click="$emit(\'click\')"><slot /></button>',
}

const ThemePickerDialogStub = {
	name: 'ThemePickerDialog',
	props: ['open', 'theme', 'nldesignAvailable', 'previewAvailable'],
	emits: ['update:open', 'save', 'clear', 'preview'],
	template: '<div class="picker-stub" />',
}

const ConfirmActionDialogStub = {
	name: 'ConfirmActionDialog',
	props: ['open', 'name', 'message', 'confirmLabel', 'destructive'],
	emits: ['update:open', 'confirm'],
	template: '<div class="confirm-stub" />',
}

const theme = {
	source: 'nldesign',
	tokenSet: 'utrecht',
	preview: { primaryColor: '#CC0000', backgroundColor: '#FAFAFA' },
}

/**
 * Mount the section.
 *
 * @param {object} manifest - the manifest.
 * @param {object} props - further props.
 * @return {object} The wrapper.
 */
function factory(manifest, props = {}) {
	return mount(ThemeSection, {
		props: { manifest, ...props },
		global: {
			stubs: {
				NcButton: NcButtonStub,
				ThemePickerDialog: ThemePickerDialogStub,
				ConfirmActionDialog: ConfirmActionDialogStub,
			},
		},
	})
}

describe('ThemeSection actions', () => {
	it('Change opens the picker when NL Design is available', async () => {
		const wrapper = factory({})

		await wrapper.findAll('button').at(0).trigger('click')

		expect(wrapper.findComponent(ThemePickerDialogStub).props('open')).toBe(true)
	})

	it('the picker stays closed without NL Design, even when asked directly', () => {
		const wrapper = factory({ runtime: { theme } }, { nldesignAvailable: false })

		wrapper.vm.openPicker()

		expect(wrapper.vm.dialogOpen).toBe(false)
		expect(wrapper.findAllComponents(NcButtonStub).at(0).props('title')).toBe(
			'NL Design is not installed or enabled on this instance.',
		)
	})

	it('saving from the picker writes runtime.theme and keeps the other runtime keys', () => {
		const wrapper = factory({ runtime: { locale: 'nl' }, pages: [] })

		wrapper.findComponent(ThemePickerDialogStub).vm.$emit('save', theme)

		expect(wrapper.emitted('update:manifest')[0][0]).toEqual({
			runtime: { locale: 'nl', theme },
			pages: [],
		})
	})

	it('the Remove button and the picker clear both ask first; confirming removes only the theme', async () => {
		const wrapper = factory({ runtime: { theme, locale: 'nl' } })
		const confirm = wrapper.findComponent(ConfirmActionDialogStub)

		await wrapper.findAll('button').at(1).trigger('click')
		expect(confirm.props('open')).toBe(true)

		wrapper.findComponent(ThemePickerDialogStub).vm.$emit('clear')
		expect(wrapper.emitted('update:manifest')).toBeUndefined()

		confirm.vm.$emit('confirm')
		expect(wrapper.emitted('update:manifest')[0][0]).toEqual({ runtime: { locale: 'nl' } })
		expect(wrapper.vm.confirmRemoveOpen).toBe(false)
	})

	it('passes the picker preview on, and shows the theme swatches or the defaults', () => {
		const wrapper = factory({ runtime: { theme } })

		wrapper.findComponent(ThemePickerDialogStub).vm.$emit('preview', theme)
		expect(wrapper.emitted('preview')[0][0]).toEqual(theme)

		expect(wrapper.vm.primaryColor).toBe('#CC0000')
		expect(wrapper.vm.backgroundColor).toBe('#FAFAFA')
		expect(wrapper.text()).toContain('utrecht')

		const plain = factory({ runtime: { theme: { tokenSet: 'x' } } })
		expect(plain.vm.primaryColor).toBe('var(--color-primary-element)')
		expect(plain.vm.backgroundColor).toBe('var(--color-main-background)')
	})
})
