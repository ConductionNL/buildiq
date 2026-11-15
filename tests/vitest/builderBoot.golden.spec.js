/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden output of the standalone runtime entry (`src/builder.js`): for each
 * slug, version and manifest response it imports the entry, waits for the
 * shell to mount, and pins the requests, the top-bar branding, the shell
 * props and slots, the router's routes, the data-sources loader, and what an
 * in-app save does to the router and the routed view's key.
 *
 * @spec openspec/specs/version-routing/spec.md#requirement-browser-reload-preserves-_version-bookmarkability
 */
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest'

const world = vi.hoisted(() => ({
	state: {},
	get: null,
	put: null,
	log: [],
	app: null,
}))

vi.mock('@conduction/nextcloud-vue', async (importOriginal) => ({
	...(await importOriginal()),
	CnAppNav: { name: 'CnAppNav', render: () => null },
	CnAppRoot: { name: 'CnAppRoot', render: () => null },
	CnPageRenderer: { name: 'CnPageRenderer', render: () => null },
	defaultPageTypes: { index: 'I', detail: 'D' },
	registerBuiltinDashboardWidgets: () =>
		world.log.push('registerBuiltinDashboardWidgets'),
	registerIcons: () => world.log.push('registerIcons'),
	registerTranslations: () => {
		world.log.push('registerTranslations')
		if (world.state.translationsThrow) {
			throw new Error('no translations')
		}
	},
}))
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: (url) => {
			world.log.push(`GET ${url}`)
			return world.get(url)
		},
		put: (url, body) => {
			world.log.push(`PUT ${url} ${JSON.stringify(body)}`)
			return world.put(url, body)
		},
	},
}))
vi.mock('@nextcloud/initial-state', () => ({
	loadState: (app, key, fallback) =>
		key in world.state ? world.state[key] : fallback,
}))
vi.mock('@nextcloud/l10n', async (importOriginal) => ({
	...(await importOriginal()),
	loadTranslations: () => {
		world.log.push('loadTranslations')
		if (world.state.l10nRejects) {
			return Promise.reject(new Error('l10n'))
		}
		return world.state.l10nResult
	},
	translate: (app, key, vars) => (vars ? `${key} ${JSON.stringify(vars)}` : key),
	translatePlural: (app, s, p, c) => (c === 1 ? s : p),
}))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => `/index.php${p}`,
}))
vi.mock('../../src/services/healthReporting.js', () => ({
	startHealthReporting: (opts) => {
		world.log.push(
			`health slug=${opts.slug} version=${opts.versionSlug} router=${typeof opts.router}`,
		)
		return { health: true }
	},
}))
vi.mock('../../src/services/appRegister.js', () => ({
	fetchAppRegister: async (s, v) => ({ register: `${s}@${v}` }),
}))
vi.mock('../../src/composables/useRegisterPicker.js', () => ({
	registerScope: (register, manifest) => ({
		register,
		pages: (manifest.pages || []).length,
	}),
	useRegisterPicker: (opts) => ({
		fetchDataSources: (scope) => ({ opts, scope }),
	}),
}))
vi.mock('vue', async (importOriginal) => {
	const vue = await importOriginal()
	return {
		...vue,
		createApp: (root) => {
			const app = {
				root,
				uses: [],
				mixins: [],
				directives: [],
				mounted: null,
				use(plugin) {
					this.uses.push(plugin)
					return this
				},
				mixin(m) {
					this.mixins.push(Object.keys(m.methods || {}).join(','))
					return this
				},
				directive(name) {
					this.directives.push(name)
					return this
				},
				mount(target) {
					this.mounted = target
				},
			}
			world.app = app
			return app
		},
	}
})

const MANIFESTS = {
	named: {
		version: '1.0.0',
		name: 'Pet Store',
		title: 'Ignored',
		menu: [{ id: 'm', route: 'Home' }],
		pages: [
			{ id: 'Home', route: '/', type: 'dashboard', config: [] },
			{ id: 'List', route: '/list', type: 'index', config: { register: 'r' } },
			{
				id: 'Item',
				route: '/list/:id',
				type: 'detail',
				config: { showTitle: false },
			},
		],
		support: { enabled: true },
		runtime: { user: { permissions: ['read'], isOwner: true } },
	},
	'title only': {
		version: '1.0.0',
		title: 'Titled',
		menu: [],
		pages: [
			{ id: 'A', route: '/a', type: 'index', primaryAction: { label: 'x' } },
		],
		runtime: { user: {} },
	},
	'no name, no pages': { version: '1.0.0', menu: [], pages: [] },
	'pages not an array': { version: '2', name: 'Bad', pages: {} },
	'nav primary action, route-less first page': {
		version: '1.0.0',
		name: 'Nav',
		nav: { primaryAction: { label: 'y' } },
		pages: [{ id: 'P', type: 'custom', config: 'x' }],
		runtime: { user: { isOwner: true, permissions: [] } },
	},
	'null data': null,
	'string data': 'nope',
}

const CASES = []
for (const [mname, manifest] of Object.entries(MANIFESTS)) {
	CASES.push({
		label: `pet-store / ${mname}`,
		state: { builderSlug: 'pet-store' },
		response: { data: manifest },
	})
}
CASES.push({
	label: 'version / named',
	state: { builderSlug: 'pet_store-x', builderVersion: 'dev 1' },
	response: { data: MANIFESTS.named },
})
CASES.push({
	label: 'version / title only',
	state: { builderSlug: 'a', builderVersion: 'v2' },
	response: { data: MANIFESTS['title only'] },
})
CASES.push({
	label: 'no slug / no name',
	state: {},
	response: { data: MANIFESTS['no name, no pages'] },
})
CASES.push({
	label: 'no slug / named',
	state: {},
	response: { data: MANIFESTS.named },
})
CASES.push({
	label: 'request fails',
	state: { builderSlug: 'gone' },
	error: 'HTTP 404',
})
CASES.push({ label: 'request fails, no slug', state: {}, error: 'HTTP 500' })
CASES.push({
	label: 'translations throw, l10n promise rejects',
	state: { builderSlug: 'pet-store', translationsThrow: true, l10nRejects: true },
	response: { data: MANIFESTS['title only'] },
})
CASES.push({
	label: 'l10n returns a non-promise',
	state: { builderSlug: 'pet-store', l10nResult: { then: 'no' } },
	response: { data: MANIFESTS['no name, no pages'] },
})

/**
 * Describe a value with functions and components shown by kind.
 *
 * @param {unknown} value - any value.
 * @return {string}
 */
function describeValue(value) {
	return JSON.stringify(value, (key, v) => {
		if (typeof v === 'function') {
			return '[fn]'
		}
		if (v && typeof v === 'object' && typeof v.render === 'function') {
			return `[component ${v.name}]`
		}
		return v
	})
}

/**
 * The router installed on the app.
 *
 * @return {object}
 */
function routerOf() {
	return world.app.uses.find((u) => u && typeof u.getRoutes === 'function')
}

/**
 * Describe the router's current routes.
 *
 * @return {string}
 */
function routesOf() {
	return routerOf()
		.getRoutes()
		.map(
			(r) =>
				`${String(r.name)} ${r.path} redirect=${describeValue(r.redirect)} props=${describeValue(r.props)} c=${r.components ? r.components.default.name : '-'}`,
		)
		.join('; ')
}

/**
 * Render the root and describe the vnode handed to the shell.
 *
 * @return {object}
 */
function renderRoot() {
	const vnode = world.app.root.render()
	return vnode
}

/**
 * Boot the entry for one case and describe everything it set up.
 *
 * @param {object} c - the case.
 * @return {Promise<Array<string>>}
 */
async function boot(c) {
	vi.resetModules()
	world.state = { ...c.state }
	world.log = []
	world.app = null
	world.get = () =>
		c.error
			? Promise.reject(new Error(c.error))
			: Promise.resolve(JSON.parse(JSON.stringify(c.response)))
	world.put = () => Promise.resolve({ data: {} })
	document.title = 'Host'
	document.body.innerHTML = `<header id="header"><span class="app-menu__current-app-name">Buildiq</span>
		<img class="app-menu__current-app-icon" src="/old.svg"><button aria-label="Open apps menu, currently in Buildiq"></button></header>`
	const errors = []
	const error = vi
		.spyOn(console, 'error')
		.mockImplementation((...a) =>
			errors.push(`error ${a[0]} ${a[1] && a[1].message}`),
		)
	const warn = vi
		.spyOn(console, 'warn')
		.mockImplementation((...a) =>
			errors.push(`warn ${a[0]} ${a[1] && a[1].message}`),
		)
	try {
		await import('../../src/builder.js')
		for (let i = 0; i < 50 && !(world.app && world.app.mounted); i++) {
			await new Promise((resolve) => setTimeout(resolve, 0))
		}
		await new Promise((resolve) => setTimeout(resolve, 0))
		const lines = []
		lines.push(`log: ${world.log.join(' | ')}`)
		lines.push(`title: ${document.title}`)
		const name = document.querySelector('.app-menu__current-app-name')
		const icon = document.querySelector('.app-menu__current-app-icon')
		const trigger = document.querySelector('button')
		lines.push(
			`topbar: ${name.textContent} | ${icon.getAttribute('src')} | ${icon.getAttribute('alt')} | ${icon.style.filter} | ${trigger.getAttribute('aria-label')}`,
		)
		const app = world.app
		lines.push(
			`app: mounted=${app.mounted} uses=${app.uses.length} mixins=${app.mixins.join('/')} directives=${app.directives.join(',')} root=${app.root.name}`,
		)
		const vnode = renderRoot()
		const props = vnode.props
		lines.push(`shell: ${vnode.type.name} keys=${Object.keys(props).join(',')}`)
		const { manifest, registry, pageTypes, ...rest } = props
		lines.push(`props: ${describeValue(rest)}`)
		lines.push(
			`registry: ${Object.keys(registry).sort().join(',')} pageTypes: ${describeValue(pageTypes)}`,
		)
		lines.push(`manifest: ${describeValue(manifest)}`)
		lines.push(
			`translate: ${props.translate('Hello', { a: 1 })} | ${props.translate('Plain')}`,
		)
		const slots = vnode.children
		lines.push(`slots: ${slots ? Object.keys(slots).join(',') : 'none'}`)
		if (slots && slots.menu) {
			for (const routeName of [undefined, 'A', 'Home', 'P']) {
				routerOf().currentRoute.value = { name: routeName }
				const nav = slots.menu({ navProp: 1 })
				lines.push(
					`menu@${routeName}: ${nav.type.name} ${describeValue(nav.props)} children=${Object.keys(nav.children || {}).join(',')}`,
				)
				if (nav.children && nav.children['primary-action']) {
					lines.push(
						`  primary: ${nav.children['primary-action']().type.name}`,
					)
				}
			}
		}
		lines.push(`routes: ${routesOf()}`)
		lines.push(`loader: ${describeValue(await props.dataSourcesLoader())}`)
		lines.push(...(await persistRounds(props)))
		lines.push(`console: ${errors.join(' || ')}`)
		return lines
	} finally {
		error.mockRestore()
		warn.mockRestore()
	}
}

/**
 * Run in-app saves that keep, change and break the page set.
 *
 * @param {object} props - the shell props.
 * @return {Promise<Array<string>>}
 */
async function persistRounds(props) {
	const lines = []
	const manifest = props.manifest
	const round = async (label, mutate) => {
		world.log = []
		mutate(manifest)
		let outcome = 'ok'
		try {
			await props.persistManifestDelta({ delta: 1 })
		} catch (e) {
			outcome = `err ${e.message}`
		}
		lines.push(
			`save ${label}: ${outcome} key=${renderRoot().props.routerViewKey} log=${world.log.join(' | ')}`,
		)
		lines.push(`  routes: ${routesOf()}`)
	}
	await round('unchanged', () => {})
	await round('menu only', (m) => {
		m.menu = [{ id: 'z' }]
	})
	await round('page added', (m) => {
		m.pages = [
			...(Array.isArray(m.pages) ? m.pages : []),
			{ id: 'New', route: '/new' },
		]
	})
	await round('same pages again', () => {})
	await round('route changed', (m) => {
		m.pages[m.pages.length - 1].route = '/renamed'
	})
	await round('duplicate name', (m) => {
		m.pages.push({ id: 'New', route: '/dup' })
	})
	await round('invalid route', (m) => {
		m.pages.push({ id: 'Bad', route: 'no-slash' })
	})
	await round('pages removed', (m) => {
		m.pages = []
	})
	await round('pages not an array', (m) => {
		m.pages = 'x'
	})
	world.put = () => Promise.reject(new Error('save failed'))
	await round('put fails', (m) => {
		m.pages = [{ id: 'After', route: '/after' }]
	})
	return lines
}

describe('builder boot golden', () => {
	// Transform the entry's module graph once, outside the per-case timeout.
	beforeAll(async () => {
		world.get = () => Promise.resolve({ data: null })
		world.put = () => Promise.resolve({ data: {} })
		await import('../../src/builder.js')
	}, 240000)

	afterEach(() => {
		document.body.innerHTML = ''
	})

	for (const c of CASES) {
		it(`pins ${c.label}`, async () => {
			const lines = await boot(c)
			expect(lines.join('\n')).toMatchSnapshot()
		}, 60000)
	}
})
