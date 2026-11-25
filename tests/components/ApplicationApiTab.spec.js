/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for `ApplicationApiTab`: the selected version's cut document
 * handed to nextcloud-vue's `CnApiReference` (calls, fields and download live
 * there), the base address, and no request leaving the instance.
 *
 * @spec openspec/changes/archive/2026-11-25-operate-api-docs/tasks.md#t04
 *
 * @spec openspec/specs/app-api-docs/spec.md
 */
import { flushPromises, shallowMount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const { axiosMock } = vi.hoisted(() => ({ axiosMock: { get: vi.fn() } }))

vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/l10n', async (importOriginal) => ({
	...(await importOriginal()),
	translate: (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (_, k) => vars[k] ?? `{${k}}`),
}))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (path, params = {}) => path.replace(/\{(\w+)\}/g, (_, k) => params[k] ?? `{${k}}`),
}))

import ApplicationApiTab from '../../src/components/tabs/ApplicationApiTab.vue'

const REGISTER = 'openbuild-vergunningen-production'

const VERSIONS = [
	{ id: 'dev', slug: 'development', name: 'Development', semver: '1.5.0', manifest: { pages: [] } },
	{
		uuid: 'prod',
		slug: 'production',
		name: 'Production',
		semver: '1.4.0',
		manifest: {
			pages: [
				{ id: 'permits', config: { register: REGISTER, schema: 'permit' } },
				{ id: 'weather', title: 'Weather', config: { dataSource: { connector: { endpointPath: '/api/endpoints/weather' } } } },
			],
		},
	},
]

const DOCUMENT = {
	openapi: '3.0.0',
	paths: {
		[`/objects/${REGISTER}/permit`]: {
			get: { summary: 'List permits', responses: { 200: { content: { 'application/json': { schema: { type: 'array', items: { $ref: '#/components/schemas/Permit' } } } } } } },
		},
		[`/objects/${REGISTER}/permit/{id}`]: {
			delete: { summary: 'Delete a permit', responses: { 204: {} } },
		},
		[`/objects/${REGISTER}/archive`]: {
			get: { summary: 'List archive', responses: { 200: { content: { 'application/json': { schema: { $ref: '#/components/schemas/Archive' } } } } } },
		},
	},
	components: {
		schemas: {
			Permit: { type: 'object', properties: { title: { type: 'string', maxLength: 255 }, status: { type: 'string', enum: ['new', 'granted'] } } },
			Archive: { type: 'object', properties: {} },
		},
	},
}

/**
 * Mount the tab with the versions and the register document answering.
 *
 * @param {object} query The route query.
 * @return {Promise<import('@vue/test-utils').Wrapper>}
 */
async function mountTab(query = {}) {
	axiosMock.get.mockImplementation((url) => {
		if (url === '/apps/buildiq/api/applications/vergunningen/versions') {
			return Promise.resolve({ data: VERSIONS })
		}
		if (url === `/apps/openregister/api/registers/${REGISTER}/oas`) {
			return Promise.resolve({ data: DOCUMENT })
		}
		return Promise.reject(new Error(`unexpected ${url}`))
	})
	const wrapper = shallowMount(ApplicationApiTab, {
		props: { object: { uuid: 'app', slug: 'vergunningen', name: 'Vergunningen', productionVersion: 'prod' } },
		global: {
			mocks: { $route: { query } },
			stubs: {
				CnApiReference: {
					name: 'CnApiReference',
					props: ['document', 'downloadName'],
					template: '<div class="cn-api-reference-stub"><slot name="intro" /></div>',
				},
			},
		},
	})
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	axiosMock.get.mockReset()
})

afterEach(() => {
	vi.restoreAllMocks()
})

describe('ApplicationApiTab', () => {
	it('hands the production version\'s cut document to CnApiReference, with the base address', async () => {
		const wrapper = await mountTab()
		expect(wrapper.find('h3').text()).toBe('API: Production (1.4.0)')
		const reference = wrapper.findComponent({ name: 'CnApiReference' })
		expect(reference.exists()).toBe(true)
		const document = reference.props('document')
		expect(Object.keys(document.paths)).toEqual([`/objects/${REGISTER}/permit`, `/objects/${REGISTER}/permit/{id}`])
		expect(Object.keys(document.components.schemas)).toEqual(['Permit'])
		expect(document.components.schemas.Permit.properties.status.enum).toEqual(['new', 'granted'])
		expect(wrapper.find('.ob-api-tab__base').text()).toBe(`${window.location.origin}/apps/openregister/api`)
		expect(wrapper.text()).toContain('app password')
	})

	it('lists the connector page as not documented here', async () => {
		const wrapper = await mountTab()
		expect(wrapper.find('.ob-api-tab__connectors').text()).toContain('Weather: /api/endpoints/weather')
	})

	it('follows the version the page has selected', async () => {
		const wrapper = await mountTab({ _version: 'development' })
		expect(wrapper.find('h3').text()).toBe('API: Development (1.5.0)')
		expect(wrapper.text()).toContain('The pages of this version use no OpenRegister schema.')
		expect(Object.keys(wrapper.findComponent({ name: 'CnApiReference' }).props('document').paths)).toEqual([])
	})

	it('names the download after the app and the version, titled after the app', async () => {
		const wrapper = await mountTab()
		const reference = wrapper.findComponent({ name: 'CnApiReference' })
		expect(reference.props('downloadName')).toBe('vergunningen-1.4.0')
		expect(reference.props('document').info).toEqual({ title: 'Vergunningen', version: '1.4.0' })
	})

	it('says when a register did not describe a bound schema', async () => {
		axiosMock.get.mockReset()
		const wrapper = await mountTab()
		// The register document is answered, so the permit schema is documented.
		expect(wrapper.text()).not.toContain('Its register did not describe this schema.')
		wrapper.vm.documents = {}
		await wrapper.vm.$nextTick()
		expect(wrapper.text()).toContain('permit: Its register did not describe this schema.')
	})

	it('makes no request outside the instance', async () => {
		await mountTab()
		const urls = axiosMock.get.mock.calls.map(([url]) => url)
		expect(urls.length).toBeGreaterThan(0)
		expect(urls.every((url) => url.startsWith('/apps/'))).toBe(true)
	})
})
