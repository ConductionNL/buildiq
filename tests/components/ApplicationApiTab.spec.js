/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for `ApplicationApiTab`: the selected version's bound schemas
 * with their calls and fields, the base address, the download of the cut
 * document, and no request leaving the instance.
 *
 * @spec openspec/changes/operate-api-docs/specs/app-api-docs/spec.md
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
		global: { mocks: { $route: { query } }, stubs: { NcButton: { emits: ['click'], template: '<button @click="$emit(\'click\')"><slot /></button>' } } },
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
	it('shows the production version\'s schema with its calls and fields, and the base address', async () => {
		const wrapper = await mountTab()
		const text = wrapper.text()
		expect(wrapper.find('h3').text()).toBe('API: Production (1.4.0)')
		expect(text).toContain(`GET /objects/${REGISTER}/permit`)
		expect(text).toContain(`DELETE /objects/${REGISTER}/permit/{id}`)
		expect(text).not.toContain('/archive')
		expect(wrapper.vm.schemaCards[0].fields).toEqual([
			{ name: 'title', type: 'string', rules: 'maxLength: 255' },
			{ name: 'status', type: 'string', rules: 'enum: new, granted' },
		])
		expect(wrapper.find('.ob-api-tab__base').text()).toBe(`${window.location.origin}/apps/openregister/api`)
		expect(text).toContain('app password')
	})

	it('lists the connector page as not documented here', async () => {
		const wrapper = await mountTab()
		expect(wrapper.find('.ob-api-tab__connectors').text()).toContain('Weather: /api/endpoints/weather')
	})

	it('follows the version the page has selected', async () => {
		const wrapper = await mountTab({ _version: 'development' })
		expect(wrapper.find('h3').text()).toBe('API: Development (1.5.0)')
		expect(wrapper.text()).toContain('The pages of this version use no OpenRegister schema.')
	})

	it('downloads exactly the cut document, titled after the app and carrying the version', async () => {
		const wrapper = await mountTab()
		let saved = null
		vi.spyOn(URL, 'createObjectURL').mockImplementation((blob) => {
			saved = blob
			return 'blob:x'
		})
		vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => {})
		const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function () {
			expect(this.download).toBe('vergunningen-1.4.0.openapi.json')
		})

		await wrapper.find('.ob-api-tab__download').trigger('click')

		expect(click).toHaveBeenCalledTimes(1)
		const document = JSON.parse(await saved.text())
		expect(document.info).toEqual({ title: 'Vergunningen', version: '1.4.0' })
		expect(Object.keys(document.paths)).toEqual([`/objects/${REGISTER}/permit`, `/objects/${REGISTER}/permit/{id}`])
		expect(Object.keys(document.components.schemas)).toEqual(['Permit'])
	})

	it('makes no request outside the instance', async () => {
		await mountTab()
		const urls = axiosMock.get.mock.calls.map(([url]) => url)
		expect(urls.length).toBeGreaterThan(0)
		expect(urls.every((url) => url.startsWith('/apps/'))).toBe(true)
	})
})
