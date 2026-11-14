import { mount } from '@vue/test-utils'
/**
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Host parity guard for connector-bound pages (integrations-connector-bound-pages
 * T03, REQ-BQCB-004): the preview host (BuilderHost.vue) and the published
 * runtime (builder.js) both run `normalizeConnectorPages` and both take their
 * registry from `getRuntimeRegistry()`, so a page that renders in the preview
 * renders in the published app. builder.js boots on import, so its half is a
 * source guard; BuilderHost's half is exercised through its fetcher.
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it, vi } from 'vitest'
import { h, ref } from 'vue'

const get = vi.fn()

vi.mock('@nextcloud/axios', () => ({ default: { get: (...args) => get(...args) } }))

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))

vi.mock('../../src/composables/useApplicationVersion.js', () => ({
	useApplicationVersion: () => ({
		applicationVersion: ref({ manifest: {} }),
		loading: ref(false),
		error: ref(null),
		ready: Promise.resolve(),
	}),
}))

vi.mock('../../src/composables/useRegisterPicker.js', () => ({
	useRegisterPicker: () => ({
		fetchDataSources: vi.fn().mockResolvedValue({ registers: [] }),
	}),
	registerScope: () => ({}),
}))

const CnAppRootStub = {
	name: 'CnAppRoot',
	props: [
		'appId',
		'aiCompanion',
		'supportDialog',
		'bundledManifest',
		'registry',
		'dataSourcesLoader',
		'options',
	],
	render() {
		return h('div', { class: 'cn-app-root-stub' })
	},
}

const BuilderHost = (await import('../../src/views/BuilderHost.vue')).default

const connector = { endpointPath: 'suppliers', fields: { name: 'name' } }
function storedManifest() {
	return {
		pages: [
			{
				id: 'suppliers',
				route: '/suppliers',
				title: 'Suppliers',
				type: 'index',
				config: { dataSource: { connector } },
			},
		],
	}
}

function mountHost() {
	return mount(BuilderHost, {
		mocks: { $route: { params: { slug: 'petstore' }, query: {} } },
		stubs: { CnAppRoot: CnAppRootStub },
	})
}

describe('preview host (BuilderHost.vue)', () => {
	it('loads the manifest through a fetcher that runs the connector normaliser', async () => {
		const stored = storedManifest()
		get.mockResolvedValue({ status: 200, data: stored })
		const wrapper = mountHost()
		const options = wrapper.findComponent(CnAppRootStub).props('options')

		const response = await options.fetcher(options.endpoint)

		expect(get).toHaveBeenCalledWith(
			'/apps/buildiq/api/applications/petstore/manifest',
		)
		expect(response.status).toBe(200)
		expect(response.data.pages[0].type).toBe('custom')
		expect(response.data.pages[0].component).toBe('connector-list')
		expect(stored).toEqual(storedManifest())
	})

	it('passes a non-object response through untouched', async () => {
		const html = { status: 200, data: '<html></html>' }
		get.mockResolvedValue(html)
		const wrapper = mountHost()
		const options = wrapper.findComponent(CnAppRootStub).props('options')

		expect(await options.fetcher(options.endpoint)).toBe(html)
	})

	it('hands CnAppRoot the runtime registry with the connector page and widget', () => {
		const registry = mountHost().findComponent(CnAppRootStub).props('registry')

		expect(registry['connector-list'].kind).toBe('page')
		expect(registry['connector-data'].kind).toBe('widget')
	})
})

describe('published runtime (builder.js)', () => {
	const source = readFileSync(resolve(__dirname, '../../src/builder.js'), 'utf8')

	it('runs the connector normaliser on the manifest it mounts', () => {
		expect(source).toMatch(
			/import \{[^}]*normalizeConnectorPages[^}]*\} from '\.\/services\/connectorPages\.js'/,
		)
		expect(source).toMatch(/const manifest = normalizeConnectorPages\(loaded\)/)
	})

	it('saves the authored shape, not the rewritten one', () => {
		expect(source).toMatch(
			/axios\.put\(saveUrl, \{ manifest: restoreConnectorPages\(manifest\) \}\)/,
		)
	})

	it('takes its registry from the same accessor as the preview host', () => {
		expect(source).toMatch(/registry: getRuntimeRegistry\(\)/)
		expect(source).not.toMatch(/\.\.\.runtimeRegistry/)
	})
})
