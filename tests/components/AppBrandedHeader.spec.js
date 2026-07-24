/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for AppBrandedHeader.vue.
 *
 * Spec: app-theming (requirement "Logo defaults to the Application's
 * existing icon fields").
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

const flushPromises = () => new Promise((resolve) => setTimeout(resolve, 0))

const axiosGetMock = vi.fn()
vi.mock('@nextcloud/axios', () => ({
	default: { get: (...args) => axiosGetMock(...args) },
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (p) => p,
}))

const { default: AppBrandedHeader } = await import('../../src/components/AppBrandedHeader.vue')

const factory = (props = {}) => mount(AppBrandedHeader, {
	propsData: { appSlug: 'kap', appName: 'KAP', logoRef: null, applicationUuid: '', ...props },
})

describe('AppBrandedHeader', () => {
	beforeEach(() => {
		axiosGetMock.mockReset()
	})

	it('defaults to the app-icon URL when logoRef is null', () => {
		const wrapper = factory()
		expect(wrapper.find('img').attributes('src')).toBe('/apps/openbuild/icons/kap.svg')
	})

	it('shows the app name next to the logo', () => {
		const wrapper = factory()
		expect(wrapper.text()).toContain('KAP')
	})

	it('resolves a dedicated theme logo via the existing OR files-listing endpoint', async () => {
		axiosGetMock.mockResolvedValue({
			data: { results: [{ title: 'theme-logo.svg', downloadUrl: 'https://files.example/theme-logo.svg' }] },
		})
		const wrapper = factory({ logoRef: { ref: 'theme-logo.svg' }, applicationUuid: 'app-uuid-1' })
		await flushPromises()
		expect(axiosGetMock).toHaveBeenCalledWith('/apps/openregister/api/objects/openbuild/application/app-uuid-1/files')
		expect(wrapper.find('img').attributes('src')).toBe('https://files.example/theme-logo.svg')
	})

	it('falls back to the app icon when the dedicated file cannot be resolved', async () => {
		axiosGetMock.mockResolvedValue({ data: { results: [] } })
		const wrapper = factory({ logoRef: { ref: 'missing.svg' }, applicationUuid: 'app-uuid-1' })
		await flushPromises()
		expect(wrapper.find('img').attributes('src')).toBe('/apps/openbuild/icons/kap.svg')
	})

	it('falls back to the app icon on a network error', async () => {
		axiosGetMock.mockRejectedValue(new Error('network'))
		const wrapper = factory({ logoRef: { ref: 'theme-logo.svg' }, applicationUuid: 'app-uuid-1' })
		await flushPromises()
		expect(wrapper.find('img').attributes('src')).toBe('/apps/openbuild/icons/kap.svg')
	})

	it('falls back to the app icon when the resolved image itself fails to load', async () => {
		axiosGetMock.mockResolvedValue({
			data: { results: [{ title: 'theme-logo.svg', downloadUrl: 'https://files.example/theme-logo.svg' }] },
		})
		const wrapper = factory({ logoRef: { ref: 'theme-logo.svg' }, applicationUuid: 'app-uuid-1' })
		await flushPromises()
		await wrapper.find('img').trigger('error')
		expect(wrapper.find('img').attributes('src')).toBe('/apps/openbuild/icons/kap.svg')
	})

	it('does not call the files endpoint when no applicationUuid is known yet', async () => {
		factory({ logoRef: { ref: 'theme-logo.svg' }, applicationUuid: '' })
		await flushPromises()
		expect(axiosGetMock).not.toHaveBeenCalled()
	})
})
