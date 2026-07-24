/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for AppCustomThemeSection.vue.
 *
 * Spec: app-theming (requirements "appTheme manifest block declares logo,
 * colors and header style", "WCAG contrast guardrail blocks saving a
 * non-compliant theme", "Logo defaults to the Application's existing icon
 * fields").
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

const axiosPostMock = vi.fn()
vi.mock('@nextcloud/axios', () => ({
	default: { post: (...args) => axiosPostMock(...args) },
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (p) => p,
}))

const { default: AppCustomThemeSection } = await import('../../src/components/AppCustomThemeSection.vue')

const NcButtonStub = {
	name: 'NcButton',
	props: ['type', 'disabled', 'title'],
	template: '<button :disabled="disabled || false" @click="$emit(\'click\')"><slot /></button>',
}
const NcSelectStub = {
	name: 'NcSelect',
	props: ['value', 'options', 'inputLabel', 'clearable', 'label'],
	template: '<select :value="value && value.id" @change="$emit(\'input\', options.find(o => o.id === $event.target.value))"><option v-for="o in options" :key="o.id" :value="o.id">{{ o.label }}</option></select>',
}
const NcTextFieldStub = {
	name: 'NcTextField',
	props: ['value', 'label'],
	template: '<input :value="value" @input="$emit(\'update:value\', $event.target.value)">',
}

const stubs = { NcButton: NcButtonStub, NcSelect: NcSelectStub, NcTextField: NcTextFieldStub }

const theme = {
	logoRef: null,
	primaryColor: '#1D4ED8',
	secondaryColor: '#0F172A',
	accentColor: '#B45309',
	headerStyle: 'default',
}

const factory = (manifest, props = {}) => mount(AppCustomThemeSection, {
	propsData: { manifest, appSlug: 'kap', applicationUuid: 'app-uuid-1', ...props },
	stubs,
})

describe('AppCustomThemeSection', () => {
	beforeEach(() => {
		axiosPostMock.mockReset()
	})

	it('renders the Default (Nextcloud) state when no appTheme is set', () => {
		const wrapper = factory({})
		expect(wrapper.find('.ob-app-theme-section__default').exists()).toBe(true)
		expect(wrapper.find('.ob-app-theme-section__editor').exists()).toBe(false)
	})

	it('"Add a custom theme" seeds verified-passing defaults', () => {
		const wrapper = factory({})
		wrapper.vm.addTheme()
		const emitted = wrapper.emitted()['update:manifest'][0][0]
		expect(emitted.runtime.appTheme.primaryColor).toBe('#1D4ED8')
		expect(emitted.runtime.appTheme.headerStyle).toBe('default')
	})

	it('renders color fields + swatches when themed', () => {
		const wrapper = factory({ runtime: { appTheme: theme } })
		expect(wrapper.findAll('.ob-app-theme-section__color-field')).toHaveLength(3)
		expect(wrapper.findAll('.ob-app-theme-section__swatch')).toHaveLength(3)
	})

	it('editing a color field emits an updated manifest, never touching dependencies', () => {
		const wrapper = factory({ runtime: { appTheme: theme }, dependencies: ['procest'] })
		wrapper.vm.setColor('primaryColor', '#000000')
		const emitted = wrapper.emitted()['update:manifest'][0][0]
		expect(emitted.runtime.appTheme.primaryColor).toBe('#000000')
		expect(emitted.dependencies).toEqual(['procest'])
	})

	it('shows no contrast failures for a passing theme', () => {
		const wrapper = factory({ runtime: { appTheme: theme } })
		expect(wrapper.find('.ob-app-theme-section__contrast-failures').exists()).toBe(false)
	})

	it('shows inline per-pair contrast failures — pair, ratio, required threshold', () => {
		const failingTheme = { ...theme, accentColor: '#F59E0B' }
		const wrapper = factory({ runtime: { appTheme: failingTheme } })
		const failuresBlock = wrapper.find('.ob-app-theme-section__contrast-failures')
		expect(failuresBlock.exists()).toBe(true)
		expect(failuresBlock.text()).toContain('accentColor-on-background')
		expect(failuresBlock.text()).toContain('3:1')
	})

	it('removing the theme deletes runtime.appTheme', () => {
		const wrapper = factory({ runtime: { appTheme: theme } })
		wrapper.vm.removeTheme()
		const emitted = wrapper.emitted()['update:manifest'][0][0]
		expect(emitted.runtime).toBeUndefined()
	})

	it('defaults the logo preview to the app icon URL', () => {
		const wrapper = factory({ runtime: { appTheme: theme } })
		expect(wrapper.vm.defaultIconUrl).toBe('/apps/openbuild/icons/kap.svg')
		expect(wrapper.vm.usesDedicatedLogo).toBe(false)
	})

	it('uploading a dedicated logo sets logoRef via the existing OR files endpoint', async () => {
		axiosPostMock.mockResolvedValue({ data: {} })
		const wrapper = factory({ runtime: { appTheme: theme } })
		const file = { name: 'brand.svg', text: () => Promise.resolve('<svg></svg>') }
		await wrapper.vm.onLogoFileChange({ target: { files: [file] } })
		expect(axiosPostMock).toHaveBeenCalledWith(
			'/apps/openregister/api/objects/openbuild/application/app-uuid-1/files',
			expect.objectContaining({ name: 'theme-logo.svg' }),
		)
		const emitted = wrapper.emitted()['update:manifest'].at(-1)[0]
		expect(emitted.runtime.appTheme.logoRef).toEqual({ ref: 'theme-logo.svg' })
	})

	it('rejects a non-svg upload without calling the API', async () => {
		const wrapper = factory({ runtime: { appTheme: theme } })
		const file = { name: 'brand.png', text: () => Promise.resolve('x') }
		await wrapper.vm.onLogoFileChange({ target: { files: [file] } })
		expect(axiosPostMock).not.toHaveBeenCalled()
		expect(wrapper.vm.logoUploadError).toBeTruthy()
	})

	it('changing header style updates the theme', () => {
		const wrapper = factory({ runtime: { appTheme: theme } })
		wrapper.vm.headerStyleOption = { id: 'branded', label: 'Branded' }
		const emitted = wrapper.emitted()['update:manifest'][0][0]
		expect(emitted.runtime.appTheme.headerStyle).toBe('branded')
	})
})
