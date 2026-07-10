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
import { registerSlugForApp } from './store/schemas.js'

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
 * Build the CnAppRoot `dataSources` for the in-app pages editor (ADR-041), so
 * its Register / Schema / Columns pickers render as populated dropdowns instead
 * of free-text fields.
 *
 * Collects the registers already referenced by the manifest's data pages
 * (falling back to the app's own per-version register when a fresh app has none
 * yet), then fetches each register's schemas from OpenRegister to derive the
 * selectable schemas and their columns.
 *
 * @param {object} manifest - the resolved app manifest.
 * @return {Promise<{registers: Array}>}
 */
async function loadDataSources(manifest) {
	const pages = manifest && Array.isArray(manifest.pages) ? manifest.pages : []
	const registers = [...new Set(
		pages.map((p) => p && p.config && p.config.register).filter(Boolean),
	)]
	if (registers.length === 0) {
		registers.push(registerSlugForApp(slug, versionSlug))
	}

	const result = []
	for (const register of registers) {
		let schemas = []
		try {
			const url = generateUrl(
				`/apps/openregister/api/registers/${encodeURIComponent(register)}/schemas`,
			)
			const { data } = await axios.get(url)
			const list = Array.isArray(data)
				? data
				: (data && Array.isArray(data.results) ? data.results : [])
			schemas = list.map((s) => ({
				value: s.slug || s.id || s.title,
				label: s.title || s.slug || String(s.id),
				columns: Object.keys(
					(s.properties && typeof s.properties === 'object') ? s.properties : {},
				),
			}))
		} catch (e) {
			// Register unreadable (deleted / no access): leave its schema list
			// empty — the affected row falls back to free-text register/schema.
			schemas = []
		}
		result.push({ value: register, label: register, schemas })
	}
	return { registers: result }
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

	// Register/schema pickers for the in-app pages editor (ADR-041). Resolved
	// before mount so the Edit-pages modal's Register / Schema fields render as
	// populated dropdowns instead of free text.
	const dataSources = await loadDataSources(manifest)

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
				},
			},
		}),
	}).$mount('#content')
}

boot()
