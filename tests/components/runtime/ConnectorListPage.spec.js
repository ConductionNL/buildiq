/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for ConnectorListPage (integrations-connector-bound-pages T02,
 * REQ-BQCB-002): title, search over the mapped fields, and the fetch states of
 * the ConnectorDataView it wraps, with the resolver mocked.
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

const { default: ConnectorListPage } =
	await import('../../../src/components/runtime/ConnectorListPage.vue')
const { getRuntimeRegistry } = await import('../../../src/runtimeRegistry.js')

const stubs = {
	NcButton: {
		name: 'NcButton',
		emits: ['click'],
		template:
			'<button class="retry" @click="$emit(\'click\')"><slot /></button>',
	},
	NcTextField: {
		name: 'NcTextField',
		props: ['modelValue', 'label'],
		emits: ['update:modelValue'],
		template:
			'<input class="search" :aria-label="label" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
	},
}

const connector = {
	endpointPath: 'suppliers',
	fields: { name: 'name', city: 'city' },
}

function mountPage(props = {}) {
	return mount(ConnectorListPage, {
		props: {
			title: 'Suppliers',
			dataSource: { connector },
			appId: 'app1',
			...props,
		},
		global: { stubs },
	})
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

describe('ConnectorListPage', () => {
	it('shows the title and one row per item with the mapped columns', () => {
		resolver.data.value = [
			{ name: 'Acme', city: 'Utrecht' },
			{ name: 'Globex', city: 'Delft' },
		]
		const wrapper = mountPage()

		expect(wrapper.find('h2').text()).toBe('Suppliers')
		expect(wrapper.findAll('th').map((th) => th.text())).toEqual([
			'name',
			'city',
		])
		expect(wrapper.findAll('tbody tr')).toHaveLength(2)
		expect(calls).toEqual([{ appId: 'app1', binding: connector }])
		expect(resolver.load).toHaveBeenCalledTimes(1)
	})

	it('filters the rows on the mapped fields, ignoring case', async () => {
		resolver.data.value = [
			{ name: 'Acme', city: 'Utrecht' },
			{ name: 'Globex', city: 'Delft' },
			{ name: 'Initech', city: null },
		]
		const wrapper = mountPage()

		await wrapper.find('.search').setValue('delft')

		const rows = wrapper.findAll('tbody tr')
		expect(rows).toHaveLength(1)
		expect(rows[0].text()).toContain('Globex')
	})

	it('says there are no results when the search matches nothing', async () => {
		resolver.data.value = [{ name: 'Acme', city: 'Utrecht' }]
		const wrapper = mountPage()

		await wrapper.find('.search').setValue('zzz')

		expect(wrapper.find('table').exists()).toBe(false)
		expect(wrapper.text()).toContain('No results.')
	})

	it('shows the empty state when the endpoint returns no items', () => {
		resolver.data.value = []
		const wrapper = mountPage()

		expect(wrapper.find('table').exists()).toBe(false)
		expect(wrapper.text()).toContain('No results.')
	})

	it('shows the error state with a Retry that asks the resolver again', async () => {
		resolver.error.value = new Error('502')
		const wrapper = mountPage()

		expect(wrapper.text()).toContain('Could not load data from OpenConnector.')
		await wrapper.find('.retry').trigger('click')
		expect(resolver.retry).toHaveBeenCalledTimes(1)
		expect(wrapper.find('table').exists()).toBe(false)
	})

	it('marks rows from a stale cache', () => {
		resolver.isStale.value = true
		resolver.data.value = [{ name: 'Acme', city: 'Utrecht' }]
		const wrapper = mountPage()

		expect(wrapper.text()).toContain('Showing cached data — a refresh failed.')
		expect(wrapper.findAll('tbody tr')).toHaveLength(1)
	})

	it('shows the loading state while loading', () => {
		resolver.loading.value = true
		const wrapper = mountPage()

		expect(wrapper.text()).toContain('Loading…')
	})

	it('offers no search box when no field is mapped', () => {
		const wrapper = mountPage({
			dataSource: { connector: { endpointPath: 'x', fields: {} } },
		})

		expect(wrapper.find('.search').exists()).toBe(false)
	})
})

describe('runtime registry', () => {
	it('registers connector-list as a page with an ADR-049 note', () => {
		const entry = getRuntimeRegistry()['connector-list']

		expect(entry.kind).toBe('page')
		expect(entry.component).toBe(ConnectorListPage)
		expect(entry._note).toMatch(/connector/)
	})

	it('hands each host its own copy of the same registry', () => {
		const a = getRuntimeRegistry()
		const b = getRuntimeRegistry()

		expect(a).not.toBe(b)
		expect(Object.keys(a)).toEqual(Object.keys(b))
		expect(a['connector-data']).toBe(b['connector-data'])
	})
})
