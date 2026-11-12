/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec pinning ApplicationIconTab.vue: the note shown while no
 * application is resolved, the upload section once it is, and the
 * `updated` payload it re-emits unchanged.
 */
import { shallowMount } from '@vue/test-utils'
import { afterAll, beforeAll, describe, expect, it, vi } from 'vitest'

const { axiosMock } = vi.hoisted(() => ({
	axiosMock: { get: vi.fn(), put: vi.fn() },
}))
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))

const realT = globalThis.t
beforeAll(() => {
	globalThis.t = (_app, key) => key
})
afterAll(() => {
	globalThis.t = realT
})

import ApplicationIconTab from '../../../src/components/tabs/ApplicationIconTab.vue'

const APP = { '@self': { id: 'uuid-1' }, slug: 'icons' }

describe('ApplicationIconTab', () => {
	it('hands the resolved application to the upload section', () => {
		const wrapper = shallowMount(ApplicationIconTab, { props: { object: APP } })
		const section = wrapper.findComponent({ name: 'IconUploadSection' })
		expect(section.exists()).toBe(true)
		expect(section.props('application')).toEqual(APP)
		expect(axiosMock.get).not.toHaveBeenCalled()
	})

	it('shows the note and no upload section without an application', () => {
		const wrapper = shallowMount(ApplicationIconTab)
		expect(wrapper.findComponent({ name: 'IconUploadSection' }).exists()).toBe(false)
		expect(wrapper.vm.obAppError).toBe('No application selected.')
	})

	it('re-emits the upload section payload as updated', async () => {
		const wrapper = shallowMount(ApplicationIconTab, { props: { object: APP } })
		await wrapper.findComponent({ name: 'IconUploadSection' }).vm.$emit('updated', { field: 'iconDark', ref: null })
		expect(wrapper.emitted('updated')).toEqual([[{ field: 'iconDark', ref: null }]])
	})
})
