// SPDX-License-Identifier: EUPL-1.2
import Vue from 'vue'
import VueRouter from 'vue-router'
import { PiniaVuePlugin } from 'pinia'
import { translate as t, translatePlural as n, loadTranslations } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	CnPageRenderer,
	defaultPageTypes,
	registerIcons,
	registerTranslations,
} from '@conduction/nextcloud-vue'
import pinia from './pinia.js'
import App from './App.vue'
import bundledManifest from './manifest.json'
import registry from './registry.js'

// Library CSS — must be an explicit import (webpack tree-shakes side-effect imports from aliased packages).
import '@conduction/nextcloud-vue/css/index.css'

// Global (unscoped) app styles.
import './assets/app.css'

Vue.mixin({ methods: { t, n } })
Vue.use(PiniaVuePlugin)
Vue.use(VueRouter)

// Library-side icon set + lib translations (best effort).
registerIcons()
try {
	registerTranslations()
} catch (e) {
	// eslint-disable-next-line no-console
	console.warn('[openbuild] registerTranslations failed; lib strings fall back to English source', e)
}

// Fire-and-forget translation load. `@nextcloud/l10n`'s loadTranslations()
// fetches l10n/<locale>.json and the returned promise rejects on a 404
// (and on dev installs that rewrite non-allowlisted paths to index.php it
// always 404s). Boot MUST NOT depend on this resolving — strings just fall
// back to their source on miss.
function tryLoadTranslations() {
	try {
		const result = loadTranslations('openbuild', () => {})
		if (result && typeof result.then === 'function') {
			result.then(() => {}, () => {})
		}
	} catch {
		// no-op — translations are best-effort
	}
}

// Shallow-clone CnPageRenderer because the lib's barrel exports are
// non-extensible ESM module records; Vue 2's Vue.extend() attaches an
// internal `_Ctor` cache to the component definition, which throws
// "Cannot add property _Ctor, object is not extensible" against a frozen
// source. Cloning gives vue-router an extensible options object.
const RoutePageRenderer = { ...CnPageRenderer }

/**
 * ADR-037: merge modular manifest fragments from src/manifest.d/*.json onto the
 * bundled base manifest. Each OpenSpec change drops its own fragment (pages/menu)
 * instead of editing the monolith src/manifest.json, so concurrent builds touch
 * disjoint files. `pages` and `menu` arrays are concatenated.
 *
 * @param {object} base The bundled base manifest.
 * @return {object} The manifest with all fragment pages/menu appended.
 */
function mergeManifestFragments(base) {
	const merged = { ...base, pages: [...(base.pages || [])], menu: [...(base.menu || [])] }
	// require.context is resolved at build time; src/manifest.d/ must exist (it
	// ships with a placeholder). It is a no-op when the directory holds no fragments.
	const ctx = require.context('./manifest.d/', false, /\.json$/)
	ctx.keys().sort().forEach((key) => {
		const frag = ctx(key)
		if (Array.isArray(frag.pages)) {
			merged.pages.push(...frag.pages)
		}
		if (Array.isArray(frag.menu)) {
			merged.menu.push(...frag.menu)
		}
	})
	return merged
}

const mergedManifest = mergeManifestFragments(bundledManifest)

/**
 * Build the vue-router config from the manifest. Each manifest page becomes
 * one route whose `name` IS `page.id` (the lib's manifest contract — menu
 * entries reference pages by id, and CnPageRenderer matches by route name).
 * Routes whose path declares a `:` parameter get `props: true` so route
 * params reach the rendered page.
 *
 * Page order in the manifest matters: more specific routes
 * (`/builder/:slug/schemas`, `/builder/:slug/schemas/:schemaId`) are
 * declared before the `/builder/:slug/:pathMatch(.*)?` wildcard so
 * vue-router matches them first.
 *
 * @param {object} manifest The bundled manifest (with `pages[]`).
 * @return {Array<object>} vue-router 3 routes config.
 */
function routesFromManifest(manifest) {
	const routes = manifest.pages.map((page) => ({
		name: page.id,
		path: page.route,
		component: RoutePageRenderer,
		props: page.route.includes(':'),
	}))
	// Catch-all redirect to the dashboard.
	routes.push({ path: '*', redirect: '/' })
	return routes
}

const router = new VueRouter({
	// History mode (clean path URLs + working deep-links e.g.
	// /apps/openbuild/applications/{id}). This relies on the AppHost engine's
	// SPA catch-all (\OCA\OpenRegister\AppHost\Routes::standard adds
	// dashboard#catchAll for `/{path}`) serving the SPA index on any sub-path —
	// without it, history-mode deep-links 404 at the server (the reason the app
	// previously fell back to hash mode, fleet #133).
	mode: 'history',
	base: generateUrl('/apps/openbuild'),
	routes: routesFromManifest(mergedManifest),
})

tryLoadTranslations()

// Pass shallow copies of the registry maps — the lib exports `defaultPageTypes`
// (and consumers' `registry`) as frozen module objects in some bundle shapes,
// and Vue.extend() mutates component definitions to attach `_Ctor`. Cloning
// yields extensible objects without changing the values the lib resolves at
// render time.
const pageTypesProp = { ...defaultPageTypes }
const registryProp = { ...registry }

// Create the Vue instance — this installs Pinia and sets it active, so the
// Pinia stores are usable from App.vue's created() hook. App.vue runs
// initializeStores() there (idempotent). Mount immediately so the App
// renders (NC32 needs #content to be taken over).
new Vue({
	pinia,
	router,
	render: h => h(App, {
		props: {
			manifest: mergedManifest,
			registry: registryProp,
			pageTypes: pageTypesProp,
		},
	}),
}).$mount('#content')
