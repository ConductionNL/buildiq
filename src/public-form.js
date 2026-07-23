// SPDX-License-Identifier: EUPL-1.2
//
// public-form.js — the ANONYMOUS runtime entry for a public-forms-runtime
// share link (/apps/openbuild/public/forms/{token}). Carries NO Pinia
// auth-store assumptions and NO Nextcloud session assumptions — the visitor
// following this link has neither. Fetches a single-page manifest fragment
// from the token-scoped public API (never the authenticated
// /api/applications/{slug}/manifest endpoint) and mounts CnAppRoot for that
// one page only, mirroring builder.js's standalone-shell pattern but without
// any of its session-branding / in-app-editing machinery (a visitor never
// edits the app).
//
// Three render states: a password prompt (password-protected token), a
// not-found screen (unknown/revoked/expired token), and the resolved page.

import Vue from 'vue'
import VueRouter from 'vue-router'
import { PiniaVuePlugin } from 'pinia'
import { translate as t, translatePlural as n, loadTranslations } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { loadState } from '@nextcloud/initial-state'
import axios from '@nextcloud/axios'
import { NcButton, NcTextField, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import {
	CnAppRoot,
	CnPageRenderer,
	defaultPageTypes,
	registerBuiltinDashboardWidgets,
	registerIcons,
	registerTranslations,
} from '@conduction/nextcloud-vue'
import pinia from './pinia.js'
import { runtimeRegistry } from './runtimeRegistry.js'
import { registerDirectives } from './registerDirectives.js'

import '@conduction/nextcloud-vue/css/index.css'
import './assets/app.css'

Vue.mixin({ methods: { t, n } })
Vue.use(PiniaVuePlugin)
Vue.use(VueRouter)
registerDirectives()

registerIcons()
registerBuiltinDashboardWidgets()
try {
	registerTranslations()
} catch (e) {
	// eslint-disable-next-line no-console
	console.warn('[openbuild:public-form] registerTranslations failed; lib strings fall back to English source', e)
}

const token = loadState('openbuild', 'publicFormToken', '')

try {
	const r = loadTranslations('openbuild', () => {})
	if (r && typeof r.then === 'function') {
		r.then(() => {}, () => {})
	}
} catch {
	// no-op — best-effort, mirrors main.js / builder.js
}

const RoutePageRenderer = { ...CnPageRenderer }

/**
 * Translate manifest label keys via the `openbuild` domain.
 *
 * @param {string} key The label or i18n key.
 * @param {object} [vars] Interpolation vars.
 * @return {string}
 */
function translateForApp(key, vars) {
	return t('openbuild', key, vars)
}

/**
 * Find the rendered DOM input for the honeypot field (matched by its
 * `key`, which the manifest-driven form renderer emits as the field's
 * `name`/`id`) and mark it visually-hidden per design.md D5's WCAG-safe
 * hidden-field pattern: `aria-hidden="true"` + `tabindex="-1"` + off-screen
 * CSS (never `display:none`, which some screen readers still announce
 * inconsistently).
 *
 * Best-effort DOM patching, mirroring builder.js's `brandTopBar()`
 * MutationObserver pattern — CnFormPage (an external library component) has
 * no first-class "hidden field" contract to hook into instead (the
 * `formField` $def is a closed `additionalProperties: false` shape with no
 * such flag), so this is the same class of fallback already established in
 * this codebase for DOM state the manifest schema cannot carry.
 *
 * @param {string} fieldKey The honeypot field's `key` (== the rendered input's expected name/id).
 * @return {void}
 */
function hideHoneypotField(fieldKey) {
	if (!fieldKey || typeof document === 'undefined') {
		return
	}
	const selector = `[name="${CSS.escape(fieldKey)}"], #${CSS.escape(fieldKey)}`
	const style = document.createElement('style')
	style.textContent = `${selector} { position: absolute !important; left: -9999px !important; width: 1px !important; height: 1px !important; overflow: hidden !important; }`
	document.head.appendChild(style)

	const apply = () => {
		document.querySelectorAll(selector).forEach((el) => {
			if (el.getAttribute('aria-hidden') !== 'true') {
				el.setAttribute('aria-hidden', 'true')
				el.setAttribute('tabindex', '-1')
				el.setAttribute('autocomplete', 'off')
			}
			// Also hide the field's wrapping label/container when it carries
			// the same key as a data-attribute or id suffix — best-effort only.
			const wrapper = el.closest('[class*="field"]')
			if (wrapper && wrapper.getAttribute('aria-hidden') !== 'true') {
				wrapper.setAttribute('aria-hidden', 'true')
			}
		})
	}
	apply()
	const root = document.getElementById('content') || document.body
	if (root) {
		new MutationObserver(apply).observe(root, { childList: true, subtree: true })
	}
}

const state = Vue.observable({
	phase: 'loading', // 'loading' | 'password' | 'not_found' | 'ready' | 'error'
	manifest: null,
	honeypotField: '',
	passwordInput: '',
	passwordError: '',
})

/**
 * Fetch the public manifest fragment for the current token, optionally with
 * a password. Drives the `state.phase` transitions.
 *
 * @param {string|null} password Optional plaintext password.
 * @return {Promise<void>}
 * @spec openspec/changes/public-forms-runtime/specs/public-form-access/spec.md#requirement-public-render-endpoint-resolves-a-token-to-exactly-its-bound-page
 */
async function fetchPublicManifest(password = null) {
	if (!token) {
		state.phase = 'not_found'
		return
	}
	try {
		const url = generateUrl(`/apps/openbuild/api/public/forms/${encodeURIComponent(token)}`)
		const params = password ? { password } : {}
		const { data } = await axios.get(url, { params })
		state.manifest = data.manifest
		state.honeypotField = data.honeypotField || ''
		state.phase = 'ready'
		mountApp()
	} catch (e) {
		const status = e.response && e.response.status
		if (status === 401) {
			state.phase = 'password'
			state.passwordError = password ? t('openbuild', 'Incorrect password. Please try again.') : ''
			return
		}
		state.phase = 'not_found'
	}
}

/**
 * Password-prompt screen for a password-protected share link.
 */
const PasswordPrompt = {
	name: 'PublicFormPasswordPrompt',
	components: { NcButton, NcTextField },
	render(h) {
		return h('div', { staticClass: 'public-form-gate' }, [
			h('h2', t('openbuild', 'This link is password-protected')),
			h(NcTextField, {
				props: {
					value: state.passwordInput,
					type: 'password',
					label: t('openbuild', 'Password'),
					error: !!state.passwordError,
					helperText: state.passwordError,
				},
				on: { 'update:value': (v) => { state.passwordInput = v } },
			}),
			h(NcButton, {
				props: { type: 'primary' },
				on: { click: () => fetchPublicManifest(state.passwordInput) },
			}, t('openbuild', 'Continue')),
		])
	},
}

/**
 * Not-found / invalid-link screen.
 */
const NotFound = {
	name: 'PublicFormNotFound',
	components: { NcEmptyContent },
	render(h) {
		return h(NcEmptyContent, {
			props: {
				name: t('openbuild', 'This link is no longer valid'),
				description: t('openbuild', 'It may have expired, been revoked, or never existed.'),
			},
		})
	},
}

/**
 * Loading screen.
 */
const Loading = {
	name: 'PublicFormLoading',
	components: { NcLoadingIcon },
	render(h) {
		return h('div', { staticClass: 'public-form-gate' }, [h(NcLoadingIcon, { props: { size: 32 } })])
	},
}

let appMounted = false

/**
 * Mount the single-page CnAppRoot shell once the manifest fragment is ready.
 *
 * @return {void}
 */
function mountApp() {
	if (appMounted) {
		return
	}
	appMounted = true

	const pages = Array.isArray(state.manifest.pages) ? state.manifest.pages : []
	const routes = pages.map((page) => ({
		name: page.id,
		path: page.route || '/',
		component: RoutePageRenderer,
		props: typeof page.route === 'string' && page.route.includes(':'),
	}))
	if (routes.length === 0) {
		routes.push({ path: '*', component: NotFound })
	} else {
		routes.push({ path: '*', redirect: routes[0].path })
	}

	const router = new VueRouter({
		mode: 'history',
		base: generateUrl(`/apps/openbuild/public/forms/${token}`),
		routes,
	})

	if (state.honeypotField) {
		hideHoneypotField(state.honeypotField)
	}

	new Vue({
		pinia,
		router,
		render: (h) => h(CnAppRoot, {
			props: {
				appId: 'openbuild-public-form',
				appName: t('openbuild', 'Form'),
				manifest: state.manifest,
				isLoading: false,
				registry: { ...runtimeRegistry },
				pageTypes: { ...defaultPageTypes },
				translate: translateForApp,
				// No persistManifestDelta — a public visitor never edits the
				// app's structure, only submits the ONE rendered page's form.
			},
		}),
	}).$mount('#content')
}

let gateInstance = null

/**
 * Render the gate (loading/password/not-found) UI into #content until the
 * manifest fragment is ready, at which point `mountApp()` takes over the
 * SAME `#content` node with the real Vue Router app. Destroys any previous
 * gate instance first so re-renders (loading → password, password →
 * password-with-error) don't leak orphaned Vue instances.
 *
 * @return {void}
 */
function mountGate() {
	if (appMounted) {
		// mountApp() has already taken over #content — never re-render the gate.
		return
	}
	if (gateInstance) {
		gateInstance.$destroy()
	}
	gateInstance = new Vue({
		pinia,
		render: (h) => {
			if (state.phase === 'password') {
				return h(PasswordPrompt)
			}
			if (state.phase === 'not_found' || state.phase === 'error') {
				return h(NotFound)
			}
			return h(Loading)
		},
	}).$mount('#content')
}

// The gate mounts immediately (loading state); once fetchPublicManifest()
// resolves to 'ready' it replaces #content's contents via mountApp() — Vue 2
// happily remounts a fresh root onto the same DOM node.
mountGate()
fetchPublicManifest()

// Re-render the gate when phase/password-error changes. `state` is already
// a `Vue.observable` — this throwaway instance exists purely to get a
// `$watch` on it (no template/render of its own), the same "observable +
// watcher, no dedicated component" idiom builder.js uses for `shellState`.
new Vue({}).$watch(
	() => state.phase,
	(phase, previous) => {
		if (phase !== previous) {
			mountGate()
		}
	},
)
