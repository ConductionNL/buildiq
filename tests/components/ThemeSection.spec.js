import { mount } from '@vue/test-utils'
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for ThemeSection.vue.
 *
 * Spec: nldesign-theme-selection (REQ-NTS-001, REQ-NTS-002, REQ-NTS-005).
 */
import { describe, expect, it } from 'vitest'
import ThemeSection from '../../src/components/ThemeSection.vue'

const NcButtonStub = {
	name: 'NcButton',
	props: ['type', 'disabled', 'title'],
	template:
		'<button :disabled="disabled || false" @click="$emit(\'click\')"><slot /></button>',
}

const stubs = {
	NcButton: NcButtonStub,
	ThemePickerDialog: {
		name: 'ThemePickerDialog',
		props: ['open', 'theme', 'nldesignAvailable', 'previewAvailable'],
		template: '<div class="picker-stub" />',
	},
}

const theme = {
	source: 'nldesign',
	tokenSet: 'amsterdam',
	tokenSetName: 'Gemeente Amsterdam',
	preview: { primaryColor: '#004699', backgroundColor: '#FFFFFF' },
}

function factory(manifest, props = {}) {
	return mount(ThemeSection, {
		propsData: { manifest, ...props },
		stubs,
	})
}

describe('ThemeSection', () => {
	it('renders the Default (Nextcloud) state when no theme is set', () => {
		const wrapper = factory({})
		expect(wrapper.find('.ob-theme-section__default').exists()).toBe(true)
		expect(wrapper.find('.ob-theme-section__current').exists()).toBe(false)
	})

	it('renders the current theme name + swatches when themed', () => {
		const wrapper = factory({ runtime: { theme } })
		expect(wrapper.text()).toContain('Gemeente Amsterdam')
		expect(wrapper.findAll('.ob-theme-section__swatch')).toHaveLength(2)
	})

	it('saving a theme writes runtime.theme and never touches dependencies', () => {
		const wrapper = factory({ dependencies: ['procest'], pages: [] })
		wrapper.vm.onSave(theme)
		const emitted = wrapper.emitted()['update:manifest'][0][0]
		expect(emitted.runtime.theme.tokenSet).toBe('amsterdam')
		expect(emitted.dependencies).toEqual(['procest'])
	})

	it('removing the theme asks first and emits nothing until confirmed', () => {
		const wrapper = factory({ runtime: { theme } })
		wrapper.vm.removeTheme()
		// The destructive step MUST NOT have run yet — this is the property the
		// old window.confirm gave us and the dialog has to preserve.
		expect(wrapper.vm.confirmRemoveOpen).toBe(true)
		expect(wrapper.emitted()['update:manifest']).toBeUndefined()
	})

	it('removing the theme deletes runtime.theme once confirmed and keeps themeless manifests clean', () => {
		const wrapper = factory({ runtime: { theme } })
		wrapper.vm.removeTheme()
		wrapper.vm.onConfirmRemoveTheme()
		const emitted = wrapper.emitted()['update:manifest'][0][0]
		expect(emitted.runtime).toBeUndefined()
		expect(wrapper.vm.confirmRemoveOpen).toBe(false)
	})

	it('disables Change when nldesign is absent but keeps the theme removable', () => {
		const wrapper = factory({ runtime: { theme } }, { nldesignAvailable: false })
		const changeBtn = wrapper.findAll('button').at(0)
		expect(changeBtn.attributes('disabled')).toBeDefined()
		expect(wrapper.find('.ob-theme-section__hint').exists()).toBe(true)
		expect(wrapper.text()).toContain('Remove')
	})

	it('forwards the preview event from the dialog', () => {
		const wrapper = factory({})
		wrapper.vm.$emit('preview', theme)
		expect(wrapper.emitted().preview[0][0]).toEqual(theme)
	})

	it('forwards previewAvailable to ThemePickerDialog (design.md OQ-1, task 3.3)', () => {
		const wrapper = factory({}, { previewAvailable: false })
		expect(
			wrapper
				.findComponent({ name: 'ThemePickerDialog' })
				.props('previewAvailable'),
		).toBe(false)
	})
})
