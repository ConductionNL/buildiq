/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for ConnectorDataView: it binds the resolver to the
 * connector block, loads on mount, and renders the loading, error (with a
 * retry), stale, empty and table states from the resolver's state.
 */

import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'

const resolver = {}
const calls = []

vi.mock('../../../src/composables/useConnectorDataSource.js', () => ({
	useConnectorDataSource: (opts) => {
		calls.push(opts)
		return resolver
	},
}))

const { default: ConnectorDataView } = await import('../../../src/components/runtime/ConnectorDataView.vue')

const stubs = {
	NcButton: {
		name: 'NcButton',
		emits: ['click'],
		template: '<button class="retry" @click="$emit(\'click\')"><slot /></button>',
	},
}

const connector = { endpointPath: 'kvk', fields: { name: 'naam', kvk: 'kvkNummer' } }

function mountView(dataSource = { connector }, appId = 'app1') {
	return mount(ConnectorDataView, { props: { dataSource, appId }, global: { stubs } })
}

beforeEach(() => {
	calls.length = 0
	Object.assign(resolver, {
		loading: ref(false),
		error: ref(null),
		isStale: ref(false),
		data: ref(null),
		load: vi.fn(),
		retry: vi.fn(),
	})
})

describe('ConnectorDataView', () => {
	it('binds the resolver to the connector block and the app, and loads on mount', () => {
		mountView()

		expect(calls).toEqual([{ appId: 'app1', binding: connector }])
		expect(resolver.load).toHaveBeenCalledTimes(1)
	})

	it('binds an empty connector when the data source has none', () => {
		mountView({})

		expect(calls[0].binding).toEqual({})
	})

	it('shows the loading state while loading', () => {
		resolver.loading.value = true
		const wrapper = mountView()

		expect(wrapper.text()).toBe('Loading…')
		expect(wrapper.find('table').exists()).toBe(false)
	})

	it('shows the error state with a retry that asks the resolver again', async () => {
		resolver.error.value = new Error('404')
		const wrapper = mountView()

		expect(wrapper.text()).toContain('Could not load data from OpenConnector.')
		await wrapper.find('.retry').trigger('click')
		expect(resolver.retry).toHaveBeenCalledTimes(1)
	})

	it('shows no results for an empty or missing result', () => {
		expect(mountView().text()).toContain('No results.')

		resolver.data.value = []
		expect(mountView().text()).toContain('No results.')
	})

	it('renders the rows under the connector field names, with null as an empty cell', () => {
		resolver.data.value = [{ name: 'Acme', kvk: '123' }, { name: 'Beta', kvk: null }]
		const wrapper = mountView()

		expect(wrapper.findAll('th').map((th) => th.text())).toEqual(['name', 'kvk'])
		expect(wrapper.findAll('tbody tr').map((tr) => tr.findAll('td').map((td) => td.text()))).toEqual([
			['Acme', '123'],
			['Beta', ''],
		])
		expect(wrapper.find('.connector-data-view__stale').exists()).toBe(false)
	})

	it('notes a stale result above the table', () => {
		resolver.data.value = [{ name: 'Acme', kvk: '123' }]
		resolver.isStale.value = true
		const wrapper = mountView()

		expect(wrapper.find('.connector-data-view__stale').text()).toBe('Showing cached data — a refresh failed.')
		expect(wrapper.find('table').exists()).toBe(true)
	})

	it('follows the resolver as its state changes', async () => {
		resolver.loading.value = true
		const wrapper = mountView()

		resolver.loading.value = false
		resolver.data.value = [{ name: 'Acme', kvk: '1' }]
		await wrapper.vm.$nextTick()

		expect(wrapper.findAll('tbody tr')).toHaveLength(1)
	})
})
