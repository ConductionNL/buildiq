// SPDX-License-Identifier: EUPL-1.2
import { createApp } from 'vue'
import { createRouter, createWebHistory } from 'vue-router'
import {
	translate as t,
	translatePlural as n,
	loadTranslations,
} from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	CnPageRenderer,
	buildManifest,
	defaultPageTypes,
	registerBuiltinDashboardWidgets,
	registerIcons,
	registerTranslations,
} from '@conduction/nextcloud-vue'
import pinia from './pinia.js'
import App from './App.vue'
import bundledManifest from './manifest.json'
import menuLayout from './menu-layout.json'
import registry from './registry.js'
import appIcons from './icons.js'
import { registerDirectives } from './registerDirectives.js'

// Library CSS — must be an explicit import (webpack tree-shakes side-effect imports from aliased packages).
import '@conduction/nextcloud-vue/css/index.css'

// nc-vue's CnDashboardGrid/CnWidgetGrid no longer bundle gridstack's JS or
// CSS (nc-vue#557) — it is a peerDependency now, so this app must supply
// both. Without this import every grid item renders 0px wide (height comes
// from JS and is set correctly; width comes from this CSS via
// `--gs-column-width`, so only a missing/mismatched stylesheet makes width
// silently disagree with height). gridstack-extra.min.css does not exist in
// v12 — the main stylesheet is all that is needed.
import 'gridstack/dist/gridstack.min.css'

// Global (unscoped) app styles.
import './assets/app.css'

// Vue 3 has no global Vue constructor: t/n, pinia, the router and the global
// directives are all installed on the app instance created at the bottom of
// this file. `registerDirectives()` is called with that app.

// Library-side icon set + lib translations (best effort).
registerIcons(appIcons)
// Populate the dashboard widget catalog. The library self-registers its widgets
// via bare side-effect imports in the barrel, which webpack may drop — leaving
// the registry empty, so widgets render "Widget not available". This exported
// no-op forces the registration module to be evaluated.
registerBuiltinDashboardWidgets()
try {
	registerTranslations()
} catch (e) {
	// eslint-disable-next-line no-console
	console.warn(
		'[openbuild] registerTranslations failed; lib strings fall back to English source',
		e,
	)
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
			result.then(
				() => {},
				() => {},
			)
		}
	} catch {
		// no-op — translations are best-effort
	}
}

// Shallow-clone CnPageRenderer because the lib's barrel exports are
// non-extensible ESM module records. Vue 3 no longer attaches `_Ctor`, but the
// clone is kept: the router still stores per-route bookkeeping against the
// component object, and a frozen module record is a fragile thing to hand it.
const RoutePageRenderer = { ...CnPageRenderer }

// ADR-044 §1: build the effective manifest through the shared
// `@conduction/nextcloud-vue` `buildManifest(base, fragments, menuLayout)`
// pipeline instead of a per-app re-implementation. The only app-local step is
// collecting the `src/manifest.d/*.json` fragments (ADR-037) — `require.context`
// is resolved by OpenBuild's own webpack build, so we gather the fragment
// objects here and hand them in. `menu-layout.json` (relocations / removals /
// settingsSection) is the single declarative home for future navigation-IA
// changes; it ships all-empty today, so `buildManifest()` reproduces the prior
// pages/menu concatenation exactly.
const manifestFragmentContext = require.context('./manifest.d/', false, /\.json$/)
const manifestFragments = manifestFragmentContext
	.keys()
	.sort()
	.map((key) => manifestFragmentContext(key))
const mergedManifest = buildManifest(bundledManifest, manifestFragments, menuLayout)

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
 * @return {Array<object>} vue-router 4 routes config.
 */
function routesFromManifest(manifest) {
	const routes = manifest.pages.map((page) => ({
		name: page.id,
		path: page.route,
		component: RoutePageRenderer,
		props: page.route.includes(':'),
	}))
	// Catch-all redirect to the dashboard. vue-router 4 dropped the bare `*`
	// wildcard in favour of an explicitly-named catch-all param.
	routes.push({ path: '/:catchAll(.*)', redirect: '/' })
	return routes
}

const router = createRouter({
	// History mode (clean path URLs + working deep-links e.g.
	// /apps/openbuild/applications/{id}). This relies on the AppHost engine's
	// SPA catch-all (\OCA\OpenRegister\AppHost\Routes::standard adds
	// dashboard#catchAll for `/{path}`) serving the SPA index on any sub-path —
	// without it, history-mode deep-links 404 at the server (the reason the app
	// previously fell back to hash mode, fleet #133).
	//
	// vue-router 4 replaces `mode: 'history'` + `base` with a history object
	// that takes the base as its argument.
	history: createWebHistory(generateUrl('/apps/openbuild')),
	routes: routesFromManifest(mergedManifest),
})

tryLoadTranslations()

// Pass shallow copies of the registry maps — the lib exports `defaultPageTypes`
// (and consumers' `registry`) as frozen module objects in some bundle shapes,
// and the renderer merges into them. Cloning yields extensible objects without
// changing the values the lib resolves at render time.
const pageTypesProp = { ...defaultPageTypes }
const registryProp = { ...registry }

// Create the app. Props are the second argument to createApp() in Vue 3 —
// there is no render/h wrapper and no `props:` nesting (that was Vue 2's
// createElement data object; in Vue 3 props are passed flat).
const app = createApp(App, {
	manifest: mergedManifest,
	registry: registryProp,
	pageTypes: pageTypesProp,
})

// t/n were a global Vue.mixin under Vue 2; in Vue 3 globals are per-app.
app.mixin({ methods: { t, n } })

// Installing pinia also sets it active, so the stores are usable from App.vue's
// created() hook, which runs initializeStores() (idempotent).
app.use(pinia)
app.use(router)

// Global directives (e.g. v-tooltip) live in registerDirectives.js — add new
// ones there rather than registering per-component, and keep this call in every
// entry (main / builder / settings) since each creates its own app.
registerDirectives(app)

// Mount immediately so the App renders (NC32 needs #content to be taken over).
app.mount('#content')
