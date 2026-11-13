/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec pinning ExportJobsTab.vue: it hands the resolved application's
 * slug and uuid to ExportJobsList, and shows the resolution error otherwise.
 */
import { flushPromises, shallowMount } from '@vue/test-utils'
import { afterAll, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const { fetchApplicationRecord } = vi.hoisted(() => ({
	fetchApplicationRecord: vi.fn(),
}))
vi.mock('../../../src/composables/useApplicationRecord.js', () => ({
	fetchApplicationRecord,
}))
vi.mock('../../../src/views/ExportJobsList.vue', () => ({
	default: {
		name: 'ExportJobsList',
		props: ['applicationSlug', 'applicationUuid'],
		template: '<div class="export-jobs-list-stub" />',
	},
}))

const realT = globalThis.t
beforeAll(() => {
	globalThis.t = (_app, key) => key
})
afterAll(() => {
	globalThis.t = realT
})

import ExportJobsTab from '../../../src/components/tabs/ExportJobsTab.vue'

describe('ExportJobsTab', () => {
	beforeEach(() => {
		fetchApplicationRecord.mockReset()
	})

	it('passes the slug and uuid of an application object to the list', () => {
		const wrapper = shallowMount(ExportJobsTab, {
			props: { object: { '@self': { id: 'uuid-1' }, slug: 'permits' } },
		})
		const list = wrapper.findComponent({ name: 'ExportJobsList' })
		expect(list.exists()).toBe(true)
		expect(list.props('applicationSlug')).toBe('permits')
		expect(list.props('applicationUuid')).toBe('uuid-1')
		expect(wrapper.find('.ob-exports-tab__error').exists()).toBe(false)
		expect(fetchApplicationRecord).not.toHaveBeenCalled()
	})

	it('fetches the application by objectId and renders the list once resolved', async () => {
		fetchApplicationRecord.mockResolvedValue({
			results: { '@self': { id: 'uuid-2' }, slug: 'grants' },
		})
		const wrapper = shallowMount(ExportJobsTab, { props: { objectId: 'uuid-2' } })
		expect(wrapper.findComponent({ name: 'ExportJobsList' }).exists()).toBe(false)
		await flushPromises()
		expect(fetchApplicationRecord).toHaveBeenCalledWith('uuid-2')
		const list = wrapper.findComponent({ name: 'ExportJobsList' })
		expect(list.props('applicationSlug')).toBe('grants')
		expect(list.props('applicationUuid')).toBe('uuid-2')
	})

	it('shows the error and no list when no application is selected', () => {
		const wrapper = shallowMount(ExportJobsTab)
		expect(wrapper.findComponent({ name: 'ExportJobsList' }).exists()).toBe(false)
		expect(wrapper.find('.ob-exports-tab__error').text()).toBe(
			'No application selected.',
		)
	})

	it('shows the load failure when fetching the application fails', async () => {
		fetchApplicationRecord.mockRejectedValue(new Error('404'))
		const wrapper = shallowMount(ExportJobsTab, { props: { objectId: 'gone' } })
		await flushPromises()
		expect(wrapper.find('.ob-exports-tab__error').text()).toBe(
			'Failed to load application: 404',
		)
	})

	it('renders neither list nor error for a resolved application without a slug', async () => {
		fetchApplicationRecord.mockResolvedValue({ '@self': { id: 'uuid-3' } })
		const wrapper = shallowMount(ExportJobsTab, { props: { objectId: 'uuid-3' } })
		await flushPromises()
		expect(wrapper.findComponent({ name: 'ExportJobsList' }).exists()).toBe(false)
		expect(wrapper.find('.ob-exports-tab__error').exists()).toBe(false)
	})
})
