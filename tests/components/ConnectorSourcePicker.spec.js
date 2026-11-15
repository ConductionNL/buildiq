import { mount } from '@vue/test-utils'
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for ConnectorSourcePicker.vue.
 *
 * Spec: openconnector-api-sources (REQ-OCAS-002, REQ-OCAS-004, REQ-OCAS-005).
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))

import axios from '@nextcloud/axios'
import ConnectorSourcePicker from '../../src/components/page-editor/ConnectorSourcePicker.vue'
import { clearAppStatusCache } from '../../src/composables/useAppStatus.js'

const NcSelectStub = {
	name: 'NcSelect',
	props: ['value', 'options', 'loading', 'inputLabel', 'placeholder', 'label'],
	template:
		'<div class="ncselect-stub" :data-label="inputLabel">{{ JSON.stringify(options) }}</div>',
}

const flush = () => new Promise((r) => setTimeout(r, 0))

describe('ConnectorSourcePicker', () => {
	beforeEach(() => {
		clearAppStatusCache()
		axios.get.mockReset()

		global.OC = { appswebroots: { integriq: '/apps/integriq' } }
	})

	function wireObjects({ sources = [], endpoints = [] } = {}) {
		axios.get.mockImplementation(async (url) => {
			if (url === '/apps/openregister/api/objects/integriq/source') {
				return { data: { results: sources } }
			}
			if (url === '/apps/openregister/api/objects/integriq/endpoint') {
				return { data: { results: endpoints } }
			}
			throw Object.assign(new Error('404'), { response: { status: 404 } })
		})
	}

	const KVK = {
		id: 'src-kvk',
		name: 'KvK',
		apikey: 'SECRET',
		password: 'SECRET2',
	}
	const BAG = { id: 'src-bag', name: 'BAG' }
	const ENDPOINTS = [
		{
			id: 'e1',
			endpoint: '/kvk/companies',
			targetType: 'api',
			targetId: 'src-kvk',
		},
		{
			id: 'e2',
			endpoint: 'bag/addresses',
			targetType: 'api',
			targetId: 'src-bag',
		},
	]

	it('reads sources and endpoints from the connector register', async () => {
		// Regression: the picker called /apps/openconnector/api/endpoints, a
		// route the connector app no longer has, so the list was always empty.
		wireObjects({ sources: [KVK, BAG], endpoints: ENDPOINTS })
		const wrapper = mount(ConnectorSourcePicker, {
			propsData: { binding: {} },
			stubs: { NcSelect: NcSelectStub },
		})
		await flush()
		await flush()
		const urls = axios.get.mock.calls.map((call) => call[0])
		expect(urls).toContain('/apps/openregister/api/objects/integriq/source')
		expect(urls).toContain('/apps/openregister/api/objects/integriq/endpoint')
		expect(urls.some((url) => url.includes('/api/endpoints'))).toBe(false)
		expect(wrapper.vm.sourceOptions).toEqual([
			{ label: 'KvK', id: 'src-kvk' },
			{ label: 'BAG', id: 'src-bag' },
		])
		expect(wrapper.vm.endpointOptions.map((o) => o.path)).toEqual([
			'kvk/companies',
			'bag/addresses',
		])
	})

	it('lists endpoints with path + source name only, never credentials', async () => {
		wireObjects({ sources: [KVK], endpoints: [ENDPOINTS[0]] })
		const wrapper = mount(ConnectorSourcePicker, {
			propsData: { binding: {} },
			stubs: { NcSelect: NcSelectStub },
		})
		await flush()
		await flush()
		const html = wrapper.html()
		expect(html).toContain('kvk/companies')
		expect(html).toContain('KvK')
		expect(html).not.toContain('SECRET')
	})

	it('offers only the endpoints of the picked source', async () => {
		wireObjects({ sources: [KVK, BAG], endpoints: ENDPOINTS })
		const wrapper = mount(ConnectorSourcePicker, {
			propsData: { binding: {} },
			stubs: { NcSelect: NcSelectStub },
		})
		await flush()
		await flush()
		wrapper.vm.onSelectSource({ id: 'src-bag', label: 'BAG' })
		expect(wrapper.vm.endpointOptions).toEqual([
			{ label: 'bag/addresses (BAG)', path: 'bag/addresses' },
		])
	})

	it('shows the source of the endpoint already bound, and unbinds it on a source switch', async () => {
		wireObjects({ sources: [KVK, BAG], endpoints: ENDPOINTS })
		const wrapper = mount(ConnectorSourcePicker, {
			propsData: { binding: { endpointPath: 'kvk/companies' } },
			stubs: { NcSelect: NcSelectStub },
		})
		await flush()
		await flush()
		expect(wrapper.vm.selectedSourceOption).toEqual({
			label: 'KvK',
			id: 'src-kvk',
		})
		expect(wrapper.vm.selectedOption).toEqual({
			label: 'kvk/companies (KvK)',
			path: 'kvk/companies',
		})
		wrapper.vm.onSelectSource({ id: 'src-bag', label: 'BAG' })
		expect(wrapper.emitted()['update:endpointPath'].pop()).toEqual([''])
	})

	it('carries an inputLabel on NcSelect (a11y gate)', async () => {
		wireObjects()
		const wrapper = mount(ConnectorSourcePicker, {
			propsData: { binding: {} },
			stubs: { NcSelect: NcSelectStub },
		})
		await flush()
		expect(wrapper.find('.ncselect-stub').attributes('data-label')).toBeTruthy()
	})

	it('degrades to a manual escape hatch when OpenConnector is absent', async () => {
		global.OC = { appswebroots: {} }
		axios.get.mockRejectedValueOnce({ response: { status: 404 } })
		const wrapper = mount(ConnectorSourcePicker, {
			propsData: { binding: {} },
			stubs: { NcSelect: NcSelectStub },
		})
		await flush()
		await flush()
		expect(wrapper.find('.connector-source-picker__manual').exists()).toBe(true)
		const input = wrapper.find('input[type="text"]')
		await input.setValue('kvk/companies')
		expect(wrapper.emitted()['update:endpointPath'].pop()).toEqual([
			'kvk/companies',
		])
	})

	it('strips scheme/host from a manually entered path', async () => {
		global.OC = { appswebroots: {} }
		axios.get.mockRejectedValueOnce({ response: { status: 404 } })
		const wrapper = mount(ConnectorSourcePicker, {
			propsData: { binding: {} },
			stubs: { NcSelect: NcSelectStub },
		})
		await flush()
		await flush()
		const input = wrapper.find('input[type="text"]')
		await input.setValue('https://evil.example/kvk/companies')
		expect(wrapper.emitted()['update:endpointPath'].pop()).toEqual([
			'kvk/companies',
		])
	})
	describe('fetchEndpoints and onSelectSource branches', () => {
		/**
		 * Mount the picker and run a fetch against the given responses.
		 *
		 * @param {object} responses `{ sources, endpoints }` response bodies.
		 * @param {object} binding The binding prop.
		 * @return {Promise<import('@vue/test-utils').Wrapper>}
		 */
		async function fetchWith({ sources, endpoints }, binding = {}) {
			axios.get.mockImplementation(async (url) =>
				url.endsWith('/source') ? { data: sources } : { data: endpoints },
			)
			const wrapper = mount(ConnectorSourcePicker, {
				propsData: { binding },
				stubs: { NcSelect: NcSelectStub },
			})
			await flush()
			await flush()
			await wrapper.vm.fetchEndpoints()
			return wrapper
		}

		it('reads bare lists and names sources by name, slug or key', async () => {
			const wrapper = await fetchWith({
				sources: [
					{ uuid: 'u1', id: 'i1', name: 'Named' },
					{ id: 'i2', slug: 'slugged' },
					{ '@self': { id: 's3' } },
					{ name: 'No id' },
				],
				endpoints: [
					{ endpoint: '//a', path: 'b', slug: 'c', targetId: 'u1' },
					{ path: '/b', targetId: 'i2' },
					{ slug: 'c', targetId: 'unknown' },
					{ targetId: 'u1' },
				],
			})
			expect(wrapper.vm.sources).toEqual([
				{ id: 'u1', name: 'Named' },
				{ id: 'i2', name: 'slugged' },
				{ id: 's3', name: 's3' },
			])
			expect(wrapper.vm.endpoints).toEqual([
				{ path: 'a', sourceId: 'u1', sourceName: 'Named' },
				{ path: 'b', sourceId: 'i2', sourceName: 'slugged' },
				{ path: 'c', sourceId: 'unknown', sourceName: '' },
			])
			expect(wrapper.vm.error).toBe(false)
			expect(wrapper.vm.loading).toBe(false)
		})

		it('keeps an endpoint without a target as unbound to any source', async () => {
			const wrapper = await fetchWith({ sources: [], endpoints: [{ endpoint: 'x' }] })
			expect(wrapper.vm.endpoints).toEqual([{ path: 'x', sourceId: '', sourceName: '' }])
		})

		it.each([[null], [{ results: 'nope' }], [{}], ['text']])('reads the response %j as no rows', async (body) => {
			const wrapper = await fetchWith({ sources: body, endpoints: body })
			expect(wrapper.vm.sources).toEqual([])
			expect(wrapper.vm.endpoints).toEqual([])
		})

		it('clears both lists and flags an error when a request fails', async () => {
			const wrapper = await fetchWith({ sources: [{ id: 'a' }], endpoints: [] })
			axios.get.mockRejectedValue(new Error('down'))
			await wrapper.vm.fetchEndpoints()
			expect(wrapper.vm.error).toBe(true)
			expect(wrapper.vm.sources).toEqual([])
			expect(wrapper.vm.endpoints).toEqual([])
			expect(wrapper.vm.loading).toBe(false)
		})

		it.each([
			[null, 'kvk/companies'],
			[{ id: 'src-kvk' }, 'kvk/companies'],
			[{ id: 'src-bag' }, ''],
			[{ id: 'src-bag' }, 'unknown/path'],
		])('keeps the binding when picking %j with %j bound', async (option, endpointPath) => {
			wireObjects({ sources: [KVK, BAG], endpoints: ENDPOINTS })
			const wrapper = mount(ConnectorSourcePicker, {
				propsData: { binding: { endpointPath } },
				stubs: { NcSelect: NcSelectStub },
			})
			await flush()
			await flush()
			wrapper.vm.onSelectSource(option)
			expect(wrapper.vm.pickedSourceId).toBe(option ? option.id : '')
			expect(wrapper.emitted('update:endpointPath')).toBeUndefined()
		})

		it('unbinds an endpoint bound with a leading slash on a source switch', async () => {
			wireObjects({ sources: [KVK, BAG], endpoints: ENDPOINTS })
			const wrapper = mount(ConnectorSourcePicker, {
				propsData: { binding: { endpointPath: '/kvk/companies' } },
				stubs: { NcSelect: NcSelectStub },
			})
			await flush()
			await flush()
			wrapper.vm.onSelectSource({ id: 'src-bag' })
			expect(wrapper.emitted('update:endpointPath')).toEqual([['']])
		})
	})
})
