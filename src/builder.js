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
 * Rebrand the Nextcloud top-bar (app name + icon) to the virtual app's identity.
 *
 * The global top-bar is server-rendered chrome for the host `openbuild` app, so
 * there is no supported API to retitle it per virtual app. We patch the DOM
 * directly and keep it in sync with a MutationObserver, because Nextcloud's
 * app-menu is a Vue component that can re-render (resize, unified-search and
 * notification updates) and would otherwise reset our changes. `apply()` is
 * idempotent — it only writes when the value differs — so it never loops on its
 * own mutations.
 *
 * @param {string} appName The virtual app's display name.
 * @param {string} appSlug The virtual app's slug, used for its icon endpoint.
 */
function brandTopBar(appName, appSlug) {
	if (!appName || typeof document === 'undefined') {
		return
	}
	// The coloured top-bar wants the white (dark-slot) icon; fall back to the
	// light icon if the app has no dark variant uploaded.
	const iconDark = generateUrl(`/apps/openbuild/icons/${appSlug}-dark.svg`)
	const iconLight = generateUrl(`/apps/openbuild/icons/${appSlug}.svg`)
	const apply = () => {
		const nameEl = document.querySelector('.app-menu__current-app-name')
		if (nameEl && nameEl.textContent !== appName) {
			nameEl.textContent = appName
		}
		const iconEl = document.querySelector('.app-menu__current-app-icon')
		if (iconEl) {
			const src = iconEl.getAttribute('src')
			if (src !== iconDark && src !== iconLight) {
				iconEl.onerror = () => { iconEl.onerror = null; iconEl.setAttribute('src', iconLight) }
				iconEl.setAttribute('src', iconDark)
				iconEl.setAttribute('alt', appName)
			}
		}
		const trigger = document.querySelector('[aria-label^="Open apps menu, currently in"]')
		if (trigger) {
			const label = t('openbuild', 'Open apps menu, currently in {app}', { app: appName })
			if (trigger.getAttribute('aria-label') !== label) {
				trigger.setAttribute('aria-label', label)
			}
		}
	}
	apply()
	const header = document.querySelector('header#header') || document.body
	if (header) {
		new MutationObserver(apply).observe(header, { childList: true, subtree: true, characterData: true })
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
		// Reflect the app's identity in the browser tab and the global NC top-bar.
		const appName = (manifest.name || manifest.title || slug)
		if (appName) {
			document.title = `${appName} – Nextcloud`
			brandTopBar(appName, slug)
		}
	} catch (e) {
		// Render an empty (but well-formed) shell; the app simply has no pages.
		// eslint-disable-next-line no-console
		console.error('[openbuild:builder] failed to load manifest for ' + slug, e)
	}

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
				// Persist in-app edits (pages / menu / settings / sidebar / actions)
				// back to the app's manifest. CnAppRoot's useManifestEditor mutates
				// THIS same `manifest` object in place while editing, so on Save we
				// PUT the full current manifest to the app's save endpoint. Without
				// a persist handler the editor computes a delta and discards it —
				// edits would vanish on refresh.
				persistManifestDelta: async () => {
					const saveUrl = generateUrl(`/apps/openbuild/api/applications/${slug}/manifest`)
					await axios.put(saveUrl, { manifest })
				},
			},
		}),
	}).$mount('#content')
}

boot()
