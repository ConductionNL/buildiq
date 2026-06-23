// SPDX-License-Identifier: EUPL-1.2
//
// builder.js — the STANDALONE runtime entry for a published virtual app.
//
// Served at /apps/openbuild/builder/{slug} (DashboardController::builder), this
// mounts the virtual app's own CnAppRoot as the TOP-LEVEL shell — its own menu,
// pages and routing resolved from GET /api/applications/{slug}/manifest. It is
// deliberately NOT the OpenBuild SPA: rendering the app inside OpenBuild's shell
// nests one NcContent in another (double chrome) and, worse, shares OpenBuild's
// router — which has none of the app's page routes, so page content never
// resolves. A separate entry gives the app a clean, single shell with a router
// built from its own manifest.
//
// Designer surfaces (/builder/{slug}/pages, /schemas) stay in the OpenBuild SPA;
// only the bare /builder/{slug} runtime is served by this entry.

import Vue from 'vue'
import VueRouter from 'vue-router'
import { PiniaVuePlugin } from 'pinia'
import { translate as t, translatePlural as n, loadTranslations } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { loadState } from '@nextcloud/initial-state'
import axios from '@nextcloud/axios'
import {
	CnAppRoot,
	CnPageRenderer,
	defaultPageTypes,
	registerIcons,
	registerTranslations,
} from '@conduction/nextcloud-vue'
import pinia from './pinia.js'
import { runtimeRegistry } from './runtimeRegistry.js'

import '@conduction/nextcloud-vue/css/index.css'
import './assets/app.css'

Vue.mixin({ methods: { t, n } })
Vue.use(PiniaVuePlugin)
Vue.use(VueRouter)

registerIcons()
try {
	registerTranslations()
} catch (e) {
	// eslint-disable-next-line no-console
	console.warn('[openbuild:builder] registerTranslations failed; lib strings fall back to English source', e)
}

const slug = loadState('openbuild', 'builderSlug', '')
const versionSlug = loadState('openbuild', 'builderVersion', '')

// Best-effort l10n load — boot must not depend on it (see main.js).
try {
	const r = loadTranslations('openbuild', () => {})
	if (r && typeof r.then === 'function') {
		r.then(() => {}, () => {})
	}
} catch {
	// no-op
}

// Shallow-clone CnPageRenderer — the lib's barrel exports are non-extensible
// ESM records and Vue.extend() attaches a `_Ctor` cache (see main.js).
const RoutePageRenderer = { ...CnPageRenderer }

/**
 * Build the vue-router config from a manifest — one route per page, named by
 * `page.id` (the lib's contract: CnPageRenderer matches by route name). The
 * fallback route redirects to the first page's route.
 *
 * @param {object} manifest The resolved virtual-app manifest.
 * @return {Array<object>} vue-router 3 routes.
 */
function routesFromManifest(manifest) {
	const pages = Array.isArray(manifest.pages) ? manifest.pages : []
	const routes = pages.map((page) => ({
		name: page.id,
		path: page.route,
		component: RoutePageRenderer,
		props: typeof page.route === 'string' && page.route.includes(':'),
	}))
	const home = (pages[0] && pages[0].route) || '/'
	routes.push({ path: '*', redirect: home })
	return routes
}

/**
 * Best-effort load of the app's OpenRegister registers + schemas, shaped for the
 * in-app pages editor (CnAppRoot's `dataSources`). The editor turns these into
 * Register / Schema / Columns dropdowns for index/detail pages so a created page
 * renders a table. Only this app's registers (slug prefix `openbuild-{slug}`) are
 * offered. Failures (RBAC, network) return null — the editor then falls back to
 * free-text slug inputs, so this never blocks boot.
 *
 * @return {Promise<object|null>} `{ registers: [{ value, label, schemas: [...] }] }` or null.
 */
async function loadDataSources() {
	try {
		const regUrl = generateUrl('/apps/openregister/api/registers') + '?_limit=1000'
		const { data } = await axios.get(regUrl)
		const all = (data && (data.results || data.registers)) || (Array.isArray(data) ? data : [])
		const prefix = `openbuild-${slug}`
		const mine = all.filter((r) => typeof r.slug === 'string' && r.slug.startsWith(prefix))
		if (!mine.length) return null

		// Resolve each register's schema ids to { value: slug, label, columns }.
		const ids = [...new Set(mine.flatMap((r) => (Array.isArray(r.schemas) ? r.schemas : [])).filter((x) => typeof x === 'number'))]
		const schemaById = {}
		await Promise.all(ids.map(async (id) => {
			try {
				const { data: s } = await axios.get(generateUrl(`/apps/openregister/api/schemas/${id}`))
				if (s && s.slug) {
					schemaById[id] = {
						value: s.slug,
						label: s.title || s.slug,
						columns: Object.keys(s.properties || {}),
					}
				}
			} catch {
				// skip a schema we can't read
			}
		}))

		const registers = mine.map((r) => ({
			value: r.slug,
			label: r.title || r.slug,
			schemas: (Array.isArray(r.schemas) ? r.schemas : []).map((id) => schemaById[id]).filter(Boolean),
		}))
		return registers.length ? { registers } : null
	} catch (e) {
		// eslint-disable-next-line no-console
		console.warn('[openbuild:builder] could not load data sources for the pages editor', e)
		return null
	}
}

/**
 * Translate manifest label keys. Virtual-app manifests usually carry plain
 * strings; t() returns them unchanged when no translation is registered.
 *
 * @param {string} key The label or i18n key.
 * @param {object} [vars] Interpolation vars.
 * @return {string}
 */
function translateForApp(key, vars) {
	return t('openbuild', key, vars)
}

/**
 * Normalise a loaded manifest's pages for the standalone runtime, in place:
 *
 * 1. `config` MUST be a plain object. An empty `config: {}` round-trips through
 *    PHP/JSON as `[]` (PHP can't tell an empty object from an empty list), and a
 *    page rendered with an array config silently loses its register/schema.
 * 2. Data pages (`index` / `detail`) default to `showTitle: true` so the app
 *    shows its page title inline — the standalone runtime renders the app as a
 *    real app, where a visible page header is expected (CnIndexPage's own
 *    default is `false`, which routes the title to an index sidebar that this
 *    runtime does not surface). An explicit `showTitle` is always respected.
 *
 * @param {object} manifest The resolved manifest (mutated in place).
 * @return {void}
 */
function normalizeManifestPages(manifest) {
	const pages = Array.isArray(manifest.pages) ? manifest.pages : []
	for (const page of pages) {
		if (!page || typeof page !== 'object') continue
		if (!page.config || typeof page.config !== 'object' || Array.isArray(page.config)) {
			page.config = {}
		}
		if ((page.type === 'index' || page.type === 'detail') && page.config.showTitle === undefined) {
			page.config.showTitle = true
		}
	}
}

/**
 * Fetch the app manifest, build its router, and mount the standalone shell.
 *
 * @return {Promise<void>}
 */
async function boot() {
	let manifest = { version: '1.0.0', menu: [], pages: [] }
	try {
		let url = generateUrl(`/apps/openbuild/api/applications/${slug}/manifest`)
		if (versionSlug) {
			url += `?_version=${encodeURIComponent(versionSlug)}`
		}
		const { data } = await axios.get(url)
		if (data && typeof data === 'object' && Array.isArray(data.pages)) {
			manifest = data
		}
		// Reflect the app's identity in the browser tab (the global NC top-bar
		// still shows the host 'OpenBuild' app — a virtual app is not a real
		// Nextcloud app, so its name/icon can't replace the host chrome there).
		const appName = (manifest.name || manifest.title || slug)
		if (appName) {
			document.title = `${appName} – Nextcloud`
		}
	} catch (e) {
		// Render an empty (but well-formed) shell; the app simply has no pages.
		// eslint-disable-next-line no-console
		console.error('[openbuild:builder] failed to load manifest for ' + slug, e)
	}

	// Normalise pages (config-as-object guard + inline page titles for data pages).
	normalizeManifestPages(manifest)

	// Load the app's registers/schemas for the in-app pages editor (best-effort;
	// null → the editor uses free-text register/schema fields).
	const dataSources = await loadDataSources()

	const router = new VueRouter({
		mode: 'history',
		base: generateUrl(`/apps/openbuild/builder/${slug}`),
		routes: routesFromManifest(manifest),
	})

	new Vue({
		pinia,
		router,
		render: (h) => h(CnAppRoot, {
			props: {
				appId: `openbuild-${slug}`,
				manifest,
				isLoading: false,
				registry: { ...runtimeRegistry },
				pageTypes: { ...defaultPageTypes },
				translate: translateForApp,
				// App registers/schemas so the Edit-pages modal offers Register /
				// Schema / Columns dropdowns for index/detail pages (null → free text).
				dataSources,
				// Persist in-app edits (pages / menu / settings / sidebar / actions)
				// back to the app's manifest. CnAppRoot's useManifestEditor mutates
				// THIS same `manifest` object in place while editing, so on Save we
				// PUT the full current manifest to the app's save endpoint. Without
				// a persist handler the editor computes a delta and discards it —
				// edits would vanish on refresh.
				persistManifestDelta: async () => {
					const saveUrl = generateUrl(`/apps/openbuild/api/applications/${slug}/manifest`)
					await axios.put(saveUrl, { manifest })
					// Rebuild the router from the just-saved manifest so pages added
					// or re-routed during this edit become navigable immediately —
					// without it a freshly-created menu item points at a route that
					// only exists after a full reload. Replacing `matcher` is the
					// vue-router 3 reset idiom (keeps `*` ordered last correctly).
					// Best-effort: the manifest is ALREADY persisted by the PUT above,
					// so a router-build error here (e.g. a duplicate route the user
					// created) must NOT reject the save — that would leave the editor
					// stuck "dirty" and confuse the user. Log and move on.
					try {
						const fresh = new VueRouter({
							mode: 'history',
							base: generateUrl(`/apps/openbuild/builder/${slug}`),
							routes: routesFromManifest(manifest),
						})
						router.matcher = fresh.matcher
					} catch (e) {
						// eslint-disable-next-line no-console
						console.warn('[openbuild:builder] router rebuild after save failed (edit is saved; reload to pick up new routes)', e)
					}
				},
			},
		}),
	}).$mount('#content')
}

boot()
