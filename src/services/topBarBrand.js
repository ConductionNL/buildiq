// SPDX-License-Identifier: EUPL-1.2
/**
 * Top-bar branding for the standalone virtual-app runtime (`builder.js`): the
 * Nextcloud app-menu name, icon and trigger label follow the virtual app.
 *
 * @module services/topBarBrand
 */
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

// Top-bar branding state. A single observer drives every (re-)apply so that the
// early slug-based pass and the later manifest-name pass share one watcher.
let topBarBrand = null

/**
 * Rebrand the Nextcloud top-bar (app name + icon) to the virtual app's identity.
 *
 * The global top-bar is server-rendered chrome for the host `buildiq` app, so
 * there is no supported API to retitle it per virtual app. We patch the DOM
 * directly and keep it in sync with a MutationObserver, because Nextcloud's
 * app-menu is a Vue component that can re-render (resize, unified-search and
 * notification updates) and would otherwise reset our changes. `apply()` is
 * idempotent — it only writes when a value differs — so it never loops on its
 * own mutations.
 *
 * Call it twice: once early with a slug-humanised name (so the bar flips off
 * "Buildiq" before the manifest request resolves), then again with the real
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
export function brandTopBar(appName, appSlug) {
	if (typeof document === 'undefined') {
		return
	}
	const icon = generateUrl(`/apps/buildiq/icons/${appSlug}.svg`)
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
		const trigger = document.querySelector(
			'[aria-label^="Open apps menu, currently in"]',
		)
		if (trigger && state.name) {
			const label = t('buildiq', 'Open apps menu, currently in {app}', {
				app: state.name,
			})
			if (trigger.getAttribute('aria-label') !== label) {
				trigger.setAttribute('aria-label', label)
			}
		}
	}
	topBarBrand = state
	state.apply()
	const header = document.querySelector('header#header') || document.body
	if (header) {
		new MutationObserver(state.apply).observe(header, {
			childList: true,
			subtree: true,
			characterData: true,
		})
	}
}
