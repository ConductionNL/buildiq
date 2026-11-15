// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/*
 * ManifestWidget — the guards and the plain emits the main spec leaves out:
 * without an app slug no override endpoint is called at all, a second
 * "Create override" while one is in flight is ignored, and the Edit button and
 * the keyboard path of "View versions" emit to the parent.
 *
 * axios + router are mocked so the component never hits the network.
 */

import { shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

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
	NcButton: {
		emits: ['click'],
		template:
			'<button class="ncbtn" @click="$emit(\'click\')"><slot /></button>',
	},
	NcLoadingIcon: { template: '<span class="ncloading" />' },
}

async function flush(wrapper) {
	await new Promise((resolve) => setTimeout(resolve, 0))
	await wrapper.vm.$nextTick()
}

function mountWidget(props = {}) {
	return shallowMount(ManifestWidget, {
		propsData: {
			appSlug: 'opencatalogi',
			isHybrid: true,
			allowUserOverrides: true,
			...props,
		},
		stubs,
	})
}

describe('ManifestWidget guards', () => {
	beforeEach(() => {
		axiosGetMock.mockReset()
		axiosPutMock.mockReset()
		axiosDeleteMock.mockReset()
		axiosGetMock.mockResolvedValue({
			data: { allowed: true, exists: true, versionUuid: 'u1' },
		})
		axiosPutMock.mockResolvedValue({ data: {} })
		axiosDeleteMock.mockResolvedValue({ data: {} })
	})

	it('calls no endpoint on mount when there is no app slug', async () => {
		const wrapper = mountWidget({ appSlug: '' })
		await flush(wrapper)

		expect(axiosGetMock).not.toHaveBeenCalled()
		expect(wrapper.vm.userOverrideCount).toBe(null)
		expect(wrapper.vm.userLoading).toBe(false)
	})

	it('neither creates nor resets an override without an app slug', async () => {
		const wrapper = mountWidget({ appSlug: '' })
		await flush(wrapper)

		await wrapper.vm.createOverride()
		await wrapper.vm.resetOverride()

		expect(axiosPutMock).not.toHaveBeenCalled()
		expect(axiosDeleteMock).not.toHaveBeenCalled()
		expect(wrapper.vm.creating).toBe(false)
		expect(wrapper.emitted('changed')).toBeFalsy()
	})

	it('ignores "Create override" while a create is already in flight', async () => {
		const wrapper = mountWidget()
		await flush(wrapper)

		wrapper.vm.creating = true
		await wrapper.vm.createOverride()

		expect(axiosPutMock).not.toHaveBeenCalled()
		expect(wrapper.vm.creating).toBe(true)
	})

	it('DELETEs the caller\'s delta, refetches and emits changed on reset', async () => {
		const wrapper = mountWidget()
		await flush(wrapper)
		axiosGetMock.mockClear()

		await wrapper.vm.resetOverride()

		expect(axiosDeleteMock).toHaveBeenCalledWith(
			'/apps/buildiq/api/app-overrides/opencatalogi/user',
		)
		expect(axiosGetMock).toHaveBeenCalledWith(
			'/apps/buildiq/api/app-overrides/opencatalogi/user',
		)
		expect(wrapper.emitted('changed')).toHaveLength(1)
	})

	it('emits edit-override when Edit is clicked on an existing delta', async () => {
		const wrapper = mountWidget()
		await flush(wrapper)

		const edit = wrapper.findAll('button.ncbtn').find((b) => b.text() === 'Edit')
		expect(edit).toBeTruthy()
		await edit.trigger('click')

		expect(wrapper.emitted('edit-override')).toHaveLength(1)
	})

	it('emits open-detail when Enter is pressed on "View versions"', async () => {
		const wrapper = mountWidget({ allowUserOverrides: false })
		await flush(wrapper)

		await wrapper.find('.ob-manifest-widget__view-all').trigger('keyup.enter')

		expect(wrapper.emitted('open-detail')).toHaveLength(1)
	})
})
