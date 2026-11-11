// SPDX-License-Identifier: EUPL-1.2
//
// builder.js — the STANDALONE runtime entry for a published virtual app.
//
// Served at /apps/buildiq/builder/{slug} (DashboardController::builder), this
// mounts the virtual app's own CnAppRoot as the TOP-LEVEL shell — its own menu,
// pages and routing resolved from GET /api/applications/{slug}/manifest. It is
// deliberately NOT the Buildiq SPA: rendering the app inside Buildiq's shell
// nests one NcContent in another (double chrome) and, worse, shares Buildiq's
// router — which has none of the app's page routes, so page content never
// resolves. A separate entry gives the app a clean, single shell with a router
// built from its own manifest.
//
// Designer surfaces (/builder/{slug}/pages, /schemas) stay in the Buildiq SPA;
// only the bare /builder/{slug} runtime is served by this entry.

import {
	CnAppNav,
	CnAppRoot,
	CnPageRenderer,
	defaultPageTypes,
	registerBuiltinDashboardWidgets,
	registerIcons,
	registerTranslations,
} from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { loadState } from '@nextcloud/initial-state'
import {
	loadTranslations,
	translatePlural as n,
	translate as t,
} from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcEmptyContent } from '@nextcloud/vue'
import { createApp, h, reactive } from 'vue'
import { createRouter, createWebHistory } from 'vue-router'
import BackToVirtualApps, {
	declaresPrimaryAction,
	showBackToVirtualApps,
} from './components/runtime/BackToVirtualApps.vue'
import { registerScope, useRegisterPicker } from './composables/useRegisterPicker.js'
import pinia from './pinia.js'
import { registerDirectives } from './registerDirectives.js'
import { runtimeRegistry } from './runtimeRegistry.js'
import { fetchAppRegister } from './services/appRegister.js'
import { startHealthReporting } from './services/healthReporting.js'
import { normalizeManifestPages } from './services/manifestPageDefaults.js'
import { normalizeManifestRouting } from './services/manifestRouting.js'
import { brandTopBar } from './services/topBarBrand.js'
import { keepVersionQuery } from './services/versionQuery.js'
import { virtualAppSupportDialog } from './utils/virtualAppSupportDialog.js'

import '@conduction/nextcloud-vue/css/index.css'
// Toast styles: without them every toast renders as bare text in a corner.
import '@nextcloud/dialogs/style.css'
// nc-vue's CnDashboardGrid/CnWidgetGrid no longer bundle gridstack's JS or
// CSS (nc-vue#557) — it is a peerDependency now. A virtual app's manifest
// can declare a type:"dashboard" page (this entry calls
// registerBuiltinDashboardWidgets() below), so this runtime needs the same
// stylesheet main.js does or that page's grid items render 0px wide.
import 'gridstack/dist/gridstack.min.css'
import './assets/app.css'

// Vue 3 has no global Vue constructor — t/n, pinia, the router and the global
// directives are installed on the app instance created in boot().

registerIcons()
// Populate the dashboard widget catalog (stat / delta / gauge / chart / table …).
// The library self-registers these via bare side-effect imports in its barrel;
// webpack is free to drop those, and does here — leaving the registry empty, so
// every manifest widget rendered "Widget not available" and the Add-widget picker
// came up with no types. This exported no-op forces the module to be evaluated.
registerBuiltinDashboardWidgets()
try {
	registerTranslations()
} catch (e) {
	// eslint-disable-next-line no-console
	console.warn(
		'[buildiq:builder] registerTranslations failed; lib strings fall back to English source',
		e,
	)
}

const slug = loadState('buildiq', 'builderSlug', '')
const versionSlug = loadState('buildiq', 'builderVersion', '')

// Best-effort l10n load — boot must not depend on it (see main.js).
try {
	const r = loadTranslations('buildiq', () => {})
	if (r && typeof r.then === 'function') {
		r.then(
			() => {},
			() => {},
		)
	}
} catch {
	// no-op
}

// Shallow-clone CnPageRenderer — the lib's barrel exports are non-extensible
// ESM records and the router keeps per-route bookkeeping on them (see main.js).
const RoutePageRenderer = { ...CnPageRenderer }

// Name of the catch-all route. vue-router 4 removes routes BY NAME, and the
// router is rebuilt in place whenever an in-app edit changes the page set, so
// the catch-all needs a stable identifier to be swapped out with the rest.
const CATCH_ALL_ROUTE = 'openbuild-builder-catch-all'

// Rendered at the catch-all route when the manifest has no pages — e.g. it
// failed to load (404: app deleted / never published) or the app genuinely
// has none yet. Replaces the previous `* → '/'` redirect, which looped
// forever when there was no real home route (`/` matches `*` → redirects to
// `/` → …) and overflowed the call stack.
const AppNotFound = {
	name: 'AppNotFound',
	render() {
		return h(NcEmptyContent, {
			name: t('buildiq', 'App not found'),
			description: t(
				'buildiq',
				'This app could not be loaded. It may have been deleted, or it has no pages yet.',
			),
		})
	},
}

/**
 * Build the vue-router config from a manifest — one route per page, named by
 * `page.id` (the lib's contract: CnPageRenderer matches by route name). The
 * fallback route redirects to the first page's route.
 *
 * The catch-all is NAMED so it can be removed by name when the router is
 * rebuilt after a page-set change (vue-router 4 removes routes by name).
 *
 * @param {object} manifest The resolved virtual-app manifest.
 * @return {Array<object>} vue-router 4 routes.
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
		// No pages → no real home route. A catch-all redirect to '/' would loop
		// forever ('/' matches the catch-all again), so render a not-found screen.
		routes.push({
			name: CATCH_ALL_ROUTE,
			path: '/:catchAll(.*)',
			component: AppNotFound,
		})
		return routes
	}
	const home = pages[0].route || '/'
	routes.push({ name: CATCH_ALL_ROUTE, path: '/:catchAll(.*)', redirect: home })
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
	return t('buildiq', key, vars)
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
 * The manifest endpoint URL, pinned to the previewed version when there is one.
 *
 * @return {string}
 */
function manifestUrl() {
	const url = generateUrl(`/apps/buildiq/api/applications/${slug}/manifest`)
	return versionSlug ? `${url}?_version=${encodeURIComponent(versionSlug)}` : url
}

/**
 * Whether a manifest response is usable: an object with a pages list.
 *
 * @param {unknown} data The response body.
 * @return {boolean}
 */
function isUsableManifest(data) {
	return Boolean(data) && typeof data === 'object' && Array.isArray(data.pages)
}

/**
 * The app's display name: its manifest name, title, or slug.
 *
 * @param {object} manifest The resolved manifest.
 * @return {string}
 */
function appNameOf(manifest) {
	return manifest.name || manifest.title || slug
}

/**
 * Reflect the app's identity in the browser tab and the global NC top-bar.
 *
 * @param {object} manifest The resolved manifest.
 * @return {void}
 */
function brandFromManifest(manifest) {
	const appName = appNameOf(manifest)
	if (appName) {
		document.title = `${appName} – Nextcloud`
		brandTopBar(appName, slug)
	}
}

/**
 * Fetch the app manifest. A failed request yields an empty (but well-formed)
 * manifest, so the shell renders with no pages.
 *
 * @return {Promise<object>}
 */
async function loadManifest() {
	let manifest = { version: '1.0.0', menu: [], pages: [] }
	try {
		const { data } = await axios.get(manifestUrl())
		if (isUsableManifest(data)) {
			manifest = data
		}
		// runtime-group-scoped-access REQ-1/REQ-2: the server already computed
		// and filtered the caller's view (ManifestResolverService::
		// filterManifestForCaller / injectPermissionsSignal) — `menu`/`pages`
		// entries the caller lacks `permission` for are already stripped, and
		// `manifest.runtime.user.permissions` carries the ready-to-forward set
		// (empty for admins/owners/editors, who received the FULL manifest and
		// so must see everything client-side too — CnAppNav treats an empty
		// array as "show everything").
		brandFromManifest(manifest)
	} catch (e) {
		// eslint-disable-next-line no-console
		console.error('[buildiq:builder] failed to load manifest for ' + slug, e)
	}
	return manifest
}

/**
 * The page set of a manifest as one string, to tell whether a save changed it.
 *
 * @param {object} manifest The manifest.
 * @return {string}
 */
function pageSignature(manifest) {
	return Array.isArray(manifest.pages)
		? manifest.pages.map((p) => `${p && p.id}:${p && p.route}`).join('|')
		: ''
}

/**
 * The permission set the server forwarded for the caller, or none.
 *
 * runtime-group-scoped-access REQ-1: forwarded to CnAppNav / CnPageRenderer's
 * permission filter — client-side mirror of the server-side filtering already
 * applied to `manifest.menu`/`pages`.
 *
 * @param {object} manifest The manifest.
 * @return {Array<string>}
 */
function runtimePermissions(manifest) {
	return (
		(manifest.runtime
			&& manifest.runtime.user
			&& manifest.runtime.user.permissions)
		|| []
	)
}

/**
 * Remove every named route from the router.
 *
 * @param {object} router The app's router.
 * @return {void}
 */
function dropNamedRoutes(router) {
	for (const record of router.getRoutes()) {
		if (record.name && router.hasRoute(record.name)) {
			router.removeRoute(record.name)
		}
	}
}

/**
 * Rebuild the router in place from a saved manifest.
 *
 * vue-router 4 removed the `matcher` swap Vue Router 3 allowed, so every route
 * we added (all are named — see CATCH_ALL_ROUTE) is dropped and re-added.
 * Best-effort: the manifest is ALREADY persisted, so a router-build error (e.g.
 * a duplicate route the user created) is logged and never rejects the save.
 *
 * @param {object} router The app's router.
 * @param {object} manifest The saved manifest.
 * @return {void}
 */
function rebuildRoutes(router, manifest) {
	try {
		dropNamedRoutes(router)
		for (const record of routesFromManifest(manifest)) {
			router.addRoute(record)
		}
	} catch (e) {
		// eslint-disable-next-line no-console
		console.warn(
			'[buildiq:builder] router rebuild after save failed (edit is saved; reload to pick up new routes)',
			e,
		)
	}
}

/**
 * Persist in-app edits (pages / menu / settings / sidebar / actions) back to the
 * app's manifest. CnAppRoot's useManifestEditor mutates THIS same `manifest`
 * object in place while editing, so on Save the full current manifest is PUT to
 * the app's save endpoint. Without a persist handler the editor computes a delta
 * and discards it, so edits would vanish on refresh.
 *
 * @param {object} manifest The manifest the shell edits in place.
 * @param {{router: object, shellState: object}} shell The app's router and the shell's reactive state.
 * @return {() => Promise<void>} The `persistManifestDelta` handler.
 */
function manifestPersister(manifest, shell) {
	const { router, shellState } = shell
	let lastPageSig = pageSignature(manifest)
	return async () => {
		const saveUrl = generateUrl(
			`/apps/buildiq/api/applications/${slug}/manifest`,
		)
		await axios.put(saveUrl, { manifest })
		// Only touch the router when the PAGE SET actually changed — a
		// menu / settings / sidebar / actions edit leaves routes intact.
		const sig = pageSignature(manifest)
		if (sig === lastPageSig) {
			return
		}
		lastPageSig = sig
		// Rebuild the router from the just-saved manifest so pages added
		// or re-routed during this edit become navigable immediately.
		rebuildRoutes(router, manifest)
		// Remount the routed view AFTER the router rebuild so it mounts
		// the added/removed page routes (see shellState / routerViewKey).
		shellState.routerEpoch++
	}
}

/**
 * The navigation slots of the shell.
 *
 * The way back to Buildiq's app list, for the people building the app, at
 * the top of the app's navigation. A slot, not a menu entry: the manifest
 * never carries it, so an in-app save cannot store it. The navigation's
 * top region belongs to the app's own primary action when the page on
 * screen declares one, so the link steps aside there.
 *
 * @param {object} manifest The manifest.
 * @param {object} router The app's router.
 * @return {object}
 */
function navSlots(manifest, router) {
	if (!showBackToVirtualApps({ versionSlug, manifest })) {
		return {}
	}
	return {
		menu: (navProps) =>
			h(
				CnAppNav,
				navProps,
				declaresPrimaryAction(manifest, router.currentRoute.value.name)
					? {}
					: { 'primary-action': () => h(BackToVirtualApps) },
			),
	}
}

/**
 * Fetch the app manifest, build its router, and mount the standalone shell.
 *
 * @return {Promise<void>}
 */
async function boot() {
	// Flip the top-bar off the host "Buildiq" identity immediately using the
	// slug, so there's no visible "Buildiq" flash while the manifest (which
	// carries the real display name) is still loading.
	if (slug) {
		brandTopBar(humaniseSlug(slug), slug)
	}
	const manifest = await loadManifest()

	// Normalise pages (config-as-object guard + inline page titles for data pages).
	normalizeManifestPages(manifest)
	// Name every page and point every menu entry at one, so a manifest written
	// before page ids existed — or one whose menu carries paths — still opens
	// with its navigation instead of an empty shell.
	normalizeManifestRouting(manifest)

	// Registers/schemas (+ columns) for the in-app pages editor, passed to CnAppRoot
	// as a LOADER rather than a pre-fetched snapshot. The modals re-invoke it every
	// time they open, so a schema created after boot shows up without a reload — a
	// snapshot captured here could not, because provide() runs once.
	//
	// It also stops the fetch happening at boot at all: this list is only ever read
	// inside an editor modal, which most users never open.
	const dataSourcesLoader = async () =>
		useRegisterPicker({ appSlug: slug }).fetchDataSources(
			registerScope(await fetchAppRegister(slug, versionSlug), manifest),
		)

	const router = createRouter({
		history: createWebHistory(generateUrl(`/apps/buildiq/builder/${slug}`)),
		routes: routesFromManifest(manifest),
	})
	// A development preview stays on its version while you click through it.
	keepVersionQuery(router, versionSlug)
	// Counts of requests, failures and page loads for the app's health section.
	const healthListener = startHealthReporting({ router, slug, versionSlug })

	// Bumped AFTER a router rebuild that changed the PAGE SET, to remount the
	// shell's <router-view> (via CnAppRoot's `routerViewKey`). Swapping route
	// records resolves new hrefs but leaves the view's component-instance cache
	// tied to the OLD records, so SPA-navigating to a page added this session
	// renders blank until reload; remounting the view drops that cache. Only the
	// routed view remounts — the shell and its teleported modals stay put — and
	// only when pages actually change, so menu / settings / sidebar edits never
	// disturb it.
	const shellState = reactive({ routerEpoch: 0 })
	const persistManifestDelta = manifestPersister(manifest, { router, shellState })

	// Static props for the shell. `routerViewKey` is deliberately NOT in here:
	// props handed to createApp() are evaluated exactly once, so reading
	// `shellState.routerEpoch` there would freeze it at 0 and the routed view
	// would never remount after a page-set change. It is applied in the root
	// render function below, which re-runs when the reactive epoch changes.
	const shellProps = {
		appId: `openbuild-${slug}`,
		// The app's display name — drives the support dialog title etc.
		// Without it CnAppRoot falls back to the appId ("buildiq-{slug}").
		appName: appNameOf(manifest),
		// Buildiq's own support note does not belong on an app a user built.
		// Off unless the app's author switched it on; see the helper.
		supportDialog: virtualAppSupportDialog(manifest),
		manifest,
		permissions: runtimePermissions(manifest),
		isLoading: false,
		registry: { ...runtimeRegistry },
		pageTypes: { ...defaultPageTypes },
		// App registers/schemas so the Edit-pages modal offers Register /
		// Schema / Columns dropdowns for index/detail pages. Re-run on every
		// modal open, so schemas created after boot appear without a reload.
		dataSourcesLoader,
		translate: translateForApp,
		// nextcloud-vue's diagnostics channel feeds the health counts.
		diagnostics: healthListener,
		persistManifestDelta,
	}

	// A thin root whose render re-runs on `routerEpoch`, so bumping it remounts
	// the routed view (CnAppRoot keys its <router-view> on `routerViewKey`).
	const slots = navSlots(manifest, router)

	const app = createApp({
		name: 'BuildiqBuilderRoot',
		render: () =>
			h(
				CnAppRoot,
				{ ...shellProps, routerViewKey: shellState.routerEpoch },
				slots,
			),
	})

	app.mixin({ methods: { t, n } })
	app.use(pinia)
	app.use(router)
	registerDirectives(app)
	app.mount('#content')
}

boot()
