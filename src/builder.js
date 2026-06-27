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

// Top-bar branding state. A single observer drives every (re-)apply so that the
// early slug-based pass and the later manifest-name pass share one watcher.
let topBarBrand = null

/**
 * Rebrand the Nextcloud top-bar (app name + icon) to the virtual app's identity.
 *
 * The global top-bar is server-rendered chrome for the host `openbuild` app, so
 * there is no supported API to retitle it per virtual app. We patch the DOM
 * directly and keep it in sync with a MutationObserver, because Nextcloud's
 * app-menu is a Vue component that can re-render (resize, unified-search and
 * notification updates) and would otherwise reset our changes. `apply()` is
 * idempotent — it only writes when a value differs — so it never loops on its
 * own mutations.
 *
 * Call it twice: once early with a slug-humanised name (so the bar flips off
 * "OpenBuild" before the manifest request resolves), then again with the real
 * `manifest.name` to correct it. The second call only updates the shared state
 * and re-applies; it does not create a second observer.
 *
 * The icon uses the app's own light icon (`/icons/{slug}.svg`) forced white with
 * a CSS filter, because the coloured header needs a monochrome white glyph and
 * apps rarely upload a dedicated white variant (the `-dark` endpoint falls back
 * to a generic cube, which is why we do NOT use it here).
 *
 * @param {string} appName The virtual app's display name.
 * @param {string} appSlug The virtual app's slug, used for its icon endpoint.
 */
function brandTopBar(appName, appSlug) {
	if (typeof document === 'undefined') {
		return
	}
	const icon = generateUrl(`/apps/openbuild/icons/${appSlug}.svg`)
	if (topBarBrand) {
		// Refine an existing brand (e.g. slug-name → real manifest name).
		if (appName) {
			topBarBrand.name = appName
		}
		topBarBrand.icon = icon
		topBarBrand.apply()
		return
	}
	const state = { name: appName || appSlug, icon }
	state.apply = () => {
		const nameEl = document.querySelector('.app-menu__current-app-name')
		if (nameEl && state.name && nameEl.textContent !== state.name) {
			nameEl.textContent = state.name
		}
		const iconEl = document.querySelector('.app-menu__current-app-icon')
		if (iconEl && iconEl.getAttribute('src') !== state.icon) {
			iconEl.setAttribute('src', state.icon)
			iconEl.setAttribute('alt', state.name || '')
			// The header background is coloured; force any icon to white.
			iconEl.style.filter = 'brightness(0) invert(1)'
		}
		const trigger = document.querySelector('[aria-label^="Open apps menu, currently in"]')
		if (trigger && state.name) {
			const label = t('openbuild', 'Open apps menu, currently in {app}', { app: state.name })
			if (trigger.getAttribute('aria-label') !== label) {
				trigger.setAttribute('aria-label', label)
			}
		}
	}
	topBarBrand = state
	state.apply()
	const header = document.querySelector('header#header') || document.body
	if (header) {
		new MutationObserver(state.apply).observe(header, { childList: true, subtree: true, characterData: true })
	}
}

/**
 * Turn a slug into a human-readable title, e.g. `pet-store` → `Pet Store`. Used
 * for the early top-bar pass before the manifest (with the real name) loads.
 *
 * @param {string} value The slug.
 * @return {string}
 */
function humaniseSlug(value) {
	return String(value || '')
		.split(/[-_]+/)
		.filter(Boolean)
		.map((w) => w.charAt(0).toUpperCase() + w.slice(1))
		.join(' ')
}

/**
 * Fetch the app manifest, build its router, and mount the standalone shell.
 *
 * @return {Promise<void>}
 */
async function boot() {
	// Flip the top-bar off the host "OpenBuild" identity immediately using the
	// slug, so there's no visible "OpenBuild" flash while the manifest (which
	// carries the real display name) is still loading.
	if (slug) {
		brandTopBar(humaniseSlug(slug), slug)
	}
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
