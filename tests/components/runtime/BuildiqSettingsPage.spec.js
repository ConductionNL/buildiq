/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for BuildiqSettingsPage: the running app's settings page keeps
 * what its users save (capability-requirements-backfill T11, REQ-BQCV-011).
 *
 * CnSettingsPage is replaced by a stand-in with its save contract: it starts
 * from `initialValues` and PUTs its form data to `saveEndpoint` on save.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const axiosGet = vi.fn()
const axiosPut = vi.fn()

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: (...args) => axiosGet(...args),
		put: (...args) => axiosPut(...args),
	},
}))

vi.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) => '/index.php' + path.replace(/\{(\w+)\}/g, (_, key) => params[key]),
}))

vi.mock('@nextcloud/initial-state', () => ({
	loadState: (app, key, fallback) => (key === 'builderSlug' ? 'permits' : fallback),
}))

const CnSettingsPageStandIn = {
	name: 'CnSettingsPage',
	props: {
		initialValues: { type: Object, default: () => ({}) },
		saveEndpoint: { type: String, default: '' },
		showSaveBar: { type: Boolean, default: true },
		sections: { type: Array, default: () => [] },
	},
	methods: {
		async save(formData) {
			if (this.saveEndpoint) {
				await axiosPut(this.saveEndpoint, formData)
			}
		},
	},
	render() {
		return h('div', { class: 'settings-stand-in' }, JSON.stringify(this.initialValues))
	},
}

vi.mock('@conduction/nextcloud-vue', () => ({ CnSettingsPage: CnSettingsPageStandIn }))

const { default: BuildiqSettingsPage } = await import('../../../src/components/runtime/BuildiqSettingsPage.vue')

const SECTIONS = [{ title: 'Replies', fields: [{ key: 'replyPeriod', type: 'number', label: 'Reply period (days)', default: 14 }] }]
const URL = '/index.php/apps/buildiq/api/applications/permits/page-settings/settings'

/**
 * Mount the page on the route of page `settings`.
 *
 * @param {object} props Extra props.
 * @return {object} The wrapper.
 */
function mountPage(props = {}) {
	return mount(BuildiqSettingsPage, {
		props: { sections: SECTIONS, ...props },
		global: { mocks: { $route: { name: 'settings', meta: {} } } },
	})
}

describe('BuildiqSettingsPage (REQ-BQCV-011)', () => {
	beforeEach(() => {
		axiosGet.mockReset()
		axiosPut.mockReset()
	})

	it('shows the reply period a user saved earlier on the next visit', async () => {
		axiosGet.mockResolvedValue({ data: { values: { replyPeriod: 21 }, canSave: true } })
		const wrapper = mountPage()
		await flushPromises()

		expect(axiosGet).toHaveBeenCalledWith(URL)
		const page = wrapper.findComponent(CnSettingsPageStandIn)
		expect(page.props('initialValues')).toEqual({ replyPeriod: 21 })
		expect(page.props('saveEndpoint')).toBe(URL)
		expect(page.props('showSaveBar')).toBe(true)
		expect(page.props('sections')).toEqual(SECTIONS)
	})

	it('saves a changed reply period to the page settings of the app', async () => {
		axiosGet.mockResolvedValue({ data: { values: {}, canSave: true } })
		const wrapper = mountPage()
		await flushPromises()

		await wrapper.findComponent(CnSettingsPageStandIn).vm.save({ replyPeriod: 30 })

		expect(axiosPut).toHaveBeenCalledWith(URL, { replyPeriod: 30 })
	})

	it('hides the save bar from a user who may not save', async () => {
		axiosGet.mockResolvedValue({ data: { values: { replyPeriod: 21 }, canSave: false } })
		const wrapper = mountPage()
		await flushPromises()

		expect(wrapper.findComponent(CnSettingsPageStandIn).props('showSaveBar')).toBe(false)
	})

	it('offers no save when the saved values cannot be read', async () => {
		axiosGet.mockRejectedValue(new Error('403'))
		const wrapper = mountPage()
		await flushPromises()

		const page = wrapper.findComponent(CnSettingsPageStandIn)
		expect(page.props('initialValues')).toEqual({})
		expect(page.props('showSaveBar')).toBe(false)
	})

	it('keeps a save address the maker typed and does not read from buildiq', async () => {
		const wrapper = mountPage({ saveEndpoint: '/apps/other/api/settings' })
		await flushPromises()

		expect(axiosGet).not.toHaveBeenCalled()
		expect(wrapper.findComponent(CnSettingsPageStandIn).props('saveEndpoint')).toBe('/apps/other/api/settings')
	})
})
