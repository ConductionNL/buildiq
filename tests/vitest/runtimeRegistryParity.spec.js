/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Parity guard for the runtime-registry seam (connector-widget-runtime
 * REQ-CWR-001).
 *
 * A virtual app is mounted by two hosts: the published standalone entry
 * `src/builder.js` (/apps/buildiq/builder/{slug}) and the in-SPA preview host
 * `src/views/BuilderHost.vue`. Both must hand CnAppRoot the output of
 * `getRuntimeRegistry()` as its `registry` prop. A host that does not renders
 * every registry-only widget key (e.g. `connector-data`) as CnUnknownWidget
 * with only a console warning, so this spec asserts on each host's MOUNT
 * CONFIGURATION — the value it actually passes — not on the registry module.
 *
 * @spec openspec/changes/openbuild-connector-widget-runtime/specs/connector-widget-runtime/spec.md#requirement-req-cwr-001-single-runtime-registry-seam-for-every-virtual-app-host
 */
import { mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { afterAll, beforeAll, describe, expect, it, vi } from 'vitest'
import { h, ref, toRaw } from 'vue'

const seen = vi.hoisted(() => ({
	// Every object getRuntimeRegistry() handed out, in call order.
	issued: [],
	// The `registry` prop each host passed to CnAppRoot.
	published: undefined,
	publishedMounted: false,
	preview: undefined,
}))

vi.mock('../../src/runtimeRegistry.js', async (importOriginal) => {
	const real = await importOriginal()
	return {
		...real,
		getRuntimeRegistry: () => {
			const value = real.getRuntimeRegistry()
			seen.issued.push(value)
			return value
		},
	}
})

vi.mock('@conduction/nextcloud-vue', async (importOriginal) => ({
	...(await importOriginal()),
	CnAppRoot: {
		name: 'CnAppRoot',
		inheritAttrs: false,
		setup(_props, { attrs }) {
			// Only the published entry mounts this mocked CnAppRoot; the
			// preview host is mounted below with its own stub.
			seen.published = attrs.registry
			seen.publishedMounted = true
			return () => h('div', { class: 'published-root' })
		},
	},
	CnAppNav: { name: 'CnAppNav', render: () => null },
	CnPageRenderer: { name: 'CnPageRenderer', render: () => null },
	defaultPageTypes: {},
	registerBuiltinDashboardWidgets: () => {},
	registerIcons: () => {},
	registerTranslations: () => {},
}))
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: async () => ({
			data: { version: '1.0.0', name: 'Parity', menu: [], pages: [] },
		}),
		put: async () => ({ data: {} }),
	},
}))
vi.mock('@nextcloud/initial-state', () => ({
	loadState: (app, key, fallback) =>
		key === 'builderSlug' ? 'parity-fixture' : fallback,
}))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))
vi.mock('../../src/services/healthReporting.js', () => ({
	startHealthReporting: () => ({}),
}))
vi.mock('../../src/services/appRegister.js', () => ({
	fetchAppRegister: async () => ({}),
}))
vi.mock('../../src/services/topBarBrand.js', () => ({
	brandTopBar: () => {},
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
	useRegisterPicker: () => ({ fetchDataSources: async () => ({}) }),
	registerScope: () => ({}),
}))
vi.mock('../../src/store/schemas.js', () => ({
	registerSlugForApp: (slug) => `buildiq-${slug}`,
}))

const UNKNOWN_WIDGET_CONSEQUENCE
	= 'registry-only widget keys (e.g. "connector-data") would render CnUnknownWidget on that path'

/**
 * Assert a host passed CnAppRoot a value the canonical accessor issued.
 *
 * @param {string} host The host's source path, named in the failure.
 * @param {object|undefined} registry The `registry` prop the host passed.
 * @return {void}
 */
function expectAccessorOutput(host, registry) {
	const raw = registry === undefined ? undefined : toRaw(registry)
	expect(
		raw !== undefined && seen.issued.includes(raw),
		`${host} does not pass getRuntimeRegistry() as CnAppRoot's \`registry\` prop — ${UNKNOWN_WIDGET_CONSEQUENCE}`,
	).toBe(true)
}

/**
 * Whether a published registry covers every key of a preview registry with
 * the very same entry.
 *
 * @param {object} published The published path's registry.
 * @param {object} preview The preview path's registry.
 * @return {string[]} preview keys the published path lacks or resolves differently.
 */
function missingFromPublished(published, preview) {
	return Object.keys(preview).filter(
		(key) => !(key in published) || published[key] !== preview[key],
	)
}

let ConnectorDataView
let previewWrapper

beforeAll(async () => {
	ConnectorDataView = (
		await import('../../src/components/runtime/ConnectorDataView.vue')
	).default

	// Published path: boot the standalone entry against a real DOM root.
	const root = document.createElement('div')
	root.id = 'content'
	document.body.appendChild(root)
	await import('../../src/builder.js')
	await vi.waitFor(() => {
		expect(seen.publishedMounted).toBe(true)
	})

	// Preview path: mount the in-SPA host.
	const BuilderHost = (await import('../../src/views/BuilderHost.vue')).default
	previewWrapper = mount(BuilderHost, {
		mocks: { $route: { params: { slug: 'parity-fixture' }, query: {} } },
		stubs: {
			CnAppRoot: {
				name: 'CnAppRoot',
				props: ['registry'],
				render() {
					return h('div', { class: 'preview-root' })
				},
			},
		},
	})
	await previewWrapper.vm.$nextTick()
	seen.preview = previewWrapper
		.findComponent({ name: 'CnAppRoot' })
		.props('registry')
})

afterAll(() => {
	previewWrapper?.unmount()
})

describe('runtime-registry seam (REQ-CWR-001)', () => {
	it('the accessor returns the full registry, connector-data included', async () => {
		const real = await vi.importActual('../../src/runtimeRegistry.js')
		const registry = real.getRuntimeRegistry()
		expect(Object.keys(registry).sort()).toEqual(
			Object.keys(real.runtimeRegistry).sort(),
		)
		expect(registry['connector-data'].component).toBe(ConnectorDataView)
		expect(registry['connector-data'].kind).toBe('widget')
		// A fresh copy per call: no host can mutate another host's registry.
		expect(registry).not.toBe(real.runtimeRegistry)
		expect(real.getRuntimeRegistry()).not.toBe(registry)
	})

	it('the published entry src/builder.js passes the accessor output to CnAppRoot', () => {
		expectAccessorOutput('src/builder.js', seen.published)
		expect(seen.published['connector-data'].component).toBe(ConnectorDataView)
	})

	it('the preview host src/views/BuilderHost.vue passes the accessor output to CnAppRoot', () => {
		expectAccessorOutput('src/views/BuilderHost.vue', seen.preview)
		expect(toRaw(seen.preview)['connector-data'].component).toBe(
			ConnectorDataView,
		)
	})

	it('the published path renders every widget key the preview path offers', () => {
		const missing = missingFromPublished(seen.published, toRaw(seen.preview))
		expect(
			missing,
			`preview offers ${missing.join(', ')} which the published path cannot resolve — ${UNKNOWN_WIDGET_CONSEQUENCE}`,
		).toEqual([])
	})

	it('flags a key added to the preview path only', () => {
		const preview = { ...toRaw(seen.preview), 'preview-only': { kind: 'widget' } }
		expect(missingFromPublished(seen.published, preview)).toEqual([
			'preview-only',
		])
	})

	it('flags a host that resolves a key to a different entry', () => {
		const published = {
			...seen.published,
			'connector-data': { kind: 'widget', component: {} },
		}
		expect(missingFromPublished(published, toRaw(seen.preview))).toEqual([
			'connector-data',
		])
	})

	it.each(['src/builder.js', 'src/views/BuilderHost.vue'])(
		'%s does not wire the raw runtimeRegistry by hand',
		(host) => {
			const source = readFileSync(resolve(__dirname, '../..', host), 'utf8')
			expect(
				/import\s*\{[^}]*\bruntimeRegistry\b[^}]*\}\s*from/.test(source),
				`${host} imports runtimeRegistry directly; use getRuntimeRegistry()`,
			).toBe(false)
		},
	)
})
