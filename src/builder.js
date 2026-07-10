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
import { NcEmptyContent } from '@nextcloud/vue'
import {
	CnAppRoot,
	CnPageRenderer,
	defaultPageTypes,
	registerIcons,
	registerTranslations,
} from '@conduction/nextcloud-vue'
import pinia from './pinia.js'
import { runtimeRegistry } from './runtimeRegistry.js'
import { registerDirectives } from './registerDirectives.js'
import { useRegisterPicker } from './composables/useRegisterPicker.js'
import { registerSlugForApp } from './store/schemas.js'

import '@conduction/nextcloud-vue/css/index.css'
import './assets/app.css'

Vue.mixin({ methods: { t, n } })
Vue.use(PiniaVuePlugin)
Vue.use(VueRouter)
registerDirectives()

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

// Rendered at the catch-all route when the manifest has no pages — e.g. it
// failed to load (404: app deleted / never published) or the app genuinely
// has none yet. Replaces the previous `* → '/'` redirect, which looped
// forever when there was no real home route (`/` matches `*` → redirects to
// `/` → …) and overflowed the call stack.
const AppNotFound = {
	name: 'AppNotFound',
	render(h) {
		return h(NcEmptyContent, {
			props: {
				name: t('openbuild', 'App not found'),
				description: t('openbuild', 'This app could not be loaded — it may have been deleted, or it has no pages yet.'),
			},
		})
	},
}

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
	if (pages.length === 0) {
		// No pages → no real home route. A `* → '/'` redirect would loop
		// forever (`/` matches `*` again), so render a clean not-found screen.
		routes.push({ path: '*', component: AppNotFound })
		return routes
	}
	const home = pages[0].route || '/'
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

	// Normalise pages (config-as-object guard + inline page titles for data pages).
	normalizeManifestPages(manifest)

	// Registers/schemas (+ columns) for the in-app pages editor. Provided to
	// CnAppRoot as `dataSources` so the edit-pages / page-config modals show
	// searchable Register / Schema / Columns dropdowns instead of free-text slug
	// inputs. Awaited before mount so the value is fully populated when
	// CnAppRoot's provide() captures it (provide runs once, non-reactively).
	// Best-effort: on failure the editor keeps its free-text fallback.
	let dataSources = { registers: [] }
	try {
		dataSources = await useRegisterPicker({ appSlug: slug }).fetchDataSources()
	} catch (e) {
		// eslint-disable-next-line no-console
		console.warn('[openbuild:builder] failed to load data sources for the pages editor', e)
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
				// The app's display name — drives the support dialog title etc.
				// Without it CnAppRoot falls back to the appId ("openbuild-{slug}").
				appName: manifest.name || manifest.title || slug,
				manifest,
				isLoading: false,
				registry: { ...runtimeRegistry },
				pageTypes: { ...defaultPageTypes },
				// App registers/schemas so the Edit-pages modal offers Register /
				// Schema / Columns dropdowns for index/detail pages (null → free text).
				dataSources,
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
