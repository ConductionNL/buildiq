/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for binding a page to another app's schema
 * (capability-requirements-backfill T23, REQ-BQCV-023). The page editor's
 * register and schema pickers list what OpenRegister lets the maker read, not
 * only the app's own register; picking another app's register and schema
 * writes only the page's `config.register` / `config.schema`, so the page
 * reads that app's records in place. No request copies or changes a schema.
 *
 * The composable runs for real; only the network edge (`fetch`, axios) is
 * stubbed, so every request the editor makes is visible here.
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/page-designer-ui/spec.md#requirement-a-page-binds-to-another-app-s-schema-without-copying-it-req-bqcv-023
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (path) => path,
}))

vi.mock('@nextcloud/auth', () => ({
	getRequestToken: () => 'token',
}))

const axiosCalls = []
vi.mock('@nextcloud/axios', () => {
	const record = (method) => async (url) => {
		axiosCalls.push({ method, url })
		return { data: [] }
	}
	return {
		default: {
			get: record('get'),
			post: record('post'),
			put: record('put'),
			patch: record('patch'),
			delete: record('delete'),
		},
	}
})

const IndexPageEditor = (await import('../../../src/components/page-editor/IndexPageEditor.vue')).default
const { registerScope } = await import('../../../src/composables/useRegisterPicker.js')

const OWN = 'buildiq-vergunningen-development'
const OTHER = 'buildiq-klantbeeld-production'
const PERSON = {
	id: 31,
	slug: 'person',
	title: 'Person',
	properties: { name: { type: 'string' }, bsn: { type: 'string' } },
}

const RESPONSES = {
	'/apps/openregister/api/registers': [
		{ id: 1, slug: 'buildiq', title: 'Buildiq' },
		{ id: 2, slug: OTHER, title: 'Klantbeeld' },
		{ id: 3, slug: OWN, title: 'Vergunningen' },
	],
	[`/apps/openregister/api/registers/${OWN}/schemas`]: [{ id: 40, slug: 'permit', title: 'Permit', properties: {} }],
	[`/apps/openregister/api/registers/${OTHER}/schemas`]: [PERSON],
}

let fetchCalls

/**
 * A fetch stand-in answering the OpenRegister list endpoints.
 *
 * @param {string} url - the request URL.
 * @param {object} [init] - the request options.
 * @return {Promise<object>} the response.
 */
async function fakeFetch(url, init = {}) {
	fetchCalls.push({ url, method: (init.method || 'GET').toUpperCase() })
	const body = RESPONSES[url]
	return {
		ok: body !== undefined,
		json: async () => ({ results: structuredClone(body) }),
	}
}

/**
 * Mount the index page editor of the `vergunningen` app, playing the page
 * designer: each emitted config goes back in as the new prop.
 *
 * @return {Promise<object>} the wrapper.
 */
async function editor() {
	const wrapper = mount(IndexPageEditor, {
		props: {
			config: {},
			appSlug: 'vergunningen',
			appRegister: OWN,
			'onUpdate:config': (next) => wrapper.setProps({ config: next }),
		},
	})
	await flushPromises()
	return wrapper
}

/**
 * The option values of a picker.
 *
 * @param {object} select - the select wrapper.
 * @return {string[]}
 */
function optionValues(select) {
	return select.findAll('option').map((option) => option.element.value)
}

describe('IndexPageEditor binds a page to another app\'s schema (REQ-BQCV-023)', () => {
	let originalFetch

	beforeEach(() => {
		originalFetch = global.fetch
		global.fetch = vi.fn(fakeFetch)
		fetchCalls = []
		axiosCalls.length = 0
	})

	afterEach(() => {
		global.fetch = originalFetch
	})

	it('lists every register the maker may read, the app\'s own first', async () => {
		const wrapper = await editor()
		const [registerSelect] = wrapper.findAll('select')
		expect(optionValues(registerSelect)).toEqual(['', OWN, 'buildiq', OTHER])
	})

	it('picks klantbeeld\'s register and the person schema and saves only the binding', async () => {
		const wrapper = await editor()
		await wrapper.findAll('select')[0].setValue(OTHER)
		await flushPromises()

		const schemaSelect = wrapper.findAll('select')[1]
		expect(optionValues(schemaSelect)).toEqual(['', 'person'])
		await schemaSelect.setValue('person')
		await flushPromises()

		// The page reads klantbeeld's person records in place.
		expect(wrapper.props('config')).toEqual({ register: OTHER, schema: 'person' })
		// The schema's own fields are offered as columns.
		expect(wrapper.vm.schemaProperties).toEqual(PERSON.properties)
	})

	it('copies nothing into the app\'s register and changes no schema', async () => {
		const wrapper = await editor()
		await wrapper.findAll('select')[0].setValue(OTHER)
		await flushPromises()
		await wrapper.findAll('select')[1].setValue('person')
		await flushPromises()

		expect(fetchCalls.length).toBeGreaterThan(0)
		expect(fetchCalls.every((call) => call.method === 'GET')).toBe(true)
		expect(fetchCalls.some((call) => call.url.includes(`/registers/${OWN}/`))).toBe(false)
		expect(axiosCalls.filter((call) => call.method !== 'get')).toEqual([])
		expect(RESPONSES[`/apps/openregister/api/registers/${OWN}/schemas`].map((s) => s.slug)).toEqual(['permit'])
		expect(RESPONSES[`/apps/openregister/api/registers/${OTHER}/schemas`]).toEqual([PERSON])
	})

	it('puts the other app\'s register in the scope the built app loads its data sources from', () => {
		const manifest = { pages: [{ id: 'people', type: 'index', config: { register: OTHER, schema: 'person' } }] }
		expect(registerScope(OWN, manifest)).toEqual([OWN, OTHER])
	})
})
