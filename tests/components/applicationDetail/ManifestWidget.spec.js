// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/*
 * Vitest unit tests for ManifestWidget.vue (layered-versioned-app-deltas).
 *
 * The widget surfaces the three manifest customization layers (Base / Admin /
 * Your delta) and the per-user create/edit/reset affordances. These tests
 * verify: the three layers render; the "Create override" affordance shows only
 * when the app allows user overrides and the caller has none yet; Edit + Reset
 * show once a user delta exists; and "View versions" emits open-detail.
 *
 * axios + router are mocked so the component never hits the network.
 */

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { shallowMount } from '@vue/test-utils'

const { axiosGetMock, axiosPutMock, axiosDeleteMock } = vi.hoisted(() => ({
	axiosGetMock: vi.fn(),
	axiosPutMock: vi.fn(),
	axiosDeleteMock: vi.fn(),
}))
vi.mock('@nextcloud/axios', () => ({
	default: { get: axiosGetMock, put: axiosPutMock, delete: axiosDeleteMock },
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path, params) => {
		let out = path
		Object.entries(params || {}).forEach(([k, v]) => {
			out = out.replace(`{${k}}`, v)
		})
		return out
	},
}))

import ManifestWidget from '../../../src/components/applicationDetail/widgets/ManifestWidget.vue'

const stubs = {
	NcButton: { template: '<button class="ncbtn" @click="$emit(\'click\')"><slot /></button>' },
	NcLoadingIcon: { template: '<span class="ncloading" />' },
}

const flush = async (wrapper) => {
	await Promise.resolve()
	await wrapper.vm.$nextTick()
}

describe('ManifestWidget', () => {
	beforeEach(() => {
		axiosGetMock.mockReset()
		axiosPutMock.mockReset()
		axiosDeleteMock.mockReset()
		axiosGetMock.mockResolvedValue({ data: { allowed: false, exists: false, versionUuid: null } })
	})

	it('renders the three customization layers', async () => {
		const wrapper = shallowMount(ManifestWidget, {
			propsData: { appSlug: 'opencatalogi', isHybrid: true, allowUserOverrides: false },
			stubs,
		})
		await flush(wrapper)
		const text = wrapper.text()
		expect(text).toContain('Base')
		expect(text).toContain('Admin delta')
		expect(text).toContain('Your delta')
	})

	it('shows "Create override" only when allowed and no user delta exists', async () => {
		axiosGetMock.mockResolvedValue({ data: { allowed: true, exists: false, versionUuid: null } })
		const wrapper = shallowMount(ManifestWidget, {
			propsData: { appSlug: 'opencatalogi', isHybrid: true, allowUserOverrides: true },
			stubs,
		})
		await flush(wrapper)
		expect(wrapper.text()).toContain('Create override')
		expect(wrapper.text()).not.toContain('Reset')
	})

	it('shows Edit + Reset once a user delta exists', async () => {
		axiosGetMock.mockResolvedValue({ data: { allowed: true, exists: true, versionUuid: 'u1' } })
		const wrapper = shallowMount(ManifestWidget, {
			propsData: { appSlug: 'opencatalogi', isHybrid: true, allowUserOverrides: true },
			stubs,
		})
		await flush(wrapper)
		expect(wrapper.text()).toContain('Edit')
		expect(wrapper.text()).toContain('Reset')
		expect(wrapper.text()).not.toContain('Create override')
	})

	it('shows a Disabled badge when the app does not allow user overrides', async () => {
		const wrapper = shallowMount(ManifestWidget, {
			propsData: { appSlug: 'intake-tracker', isHybrid: false, allowUserOverrides: false },
			stubs,
		})
		await flush(wrapper)
		expect(wrapper.text()).toContain('Disabled')
		expect(wrapper.text()).not.toContain('Create override')
	})

	it('emits open-detail when "View versions" is clicked', async () => {
		const wrapper = shallowMount(ManifestWidget, {
			propsData: { appSlug: 'opencatalogi', isHybrid: true, allowUserOverrides: false },
			stubs,
		})
		await flush(wrapper)
		await wrapper.find('.ob-manifest-widget__view-all').trigger('click')
		expect(wrapper.emitted('open-detail')).toBeTruthy()
	})

	it('PUTs an empty delta and refetches on "Create override"', async () => {
		axiosGetMock.mockResolvedValue({ data: { allowed: true, exists: false, versionUuid: null } })
		axiosPutMock.mockResolvedValue({ data: { versionUuid: 'new' } })
		const wrapper = shallowMount(ManifestWidget, {
			propsData: { appSlug: 'opencatalogi', isHybrid: true, allowUserOverrides: true },
			stubs,
		})
		await flush(wrapper)
		await wrapper.vm.createOverride()
		expect(axiosPutMock).toHaveBeenCalledWith('/apps/openbuild/api/app-overrides/opencatalogi/user', {})
		expect(wrapper.emitted('changed')).toBeTruthy()
	})
})
