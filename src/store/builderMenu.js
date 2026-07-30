/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The builder-context "Schemas" navigation entry (REQ-OBR-007a).
 *
 * WHAT THIS IMPLEMENTS
 * --------------------
 * `openspec/specs/openbuild-runtime/spec.md` REQ-OBR-007a: BuilderHost SHALL
 * surface a **Schemas** entry in the OpenBuild outer-shell secondary navigation
 * while the user is in a virtual app's builder context; activating it SHALL
 * route to `/builder/{slug}/schemas`, and it SHALL use the translation key
 * `openbuild.builder.menu.schemas`.
 *
 * The route has existed and worked all along — `/builder/:slug/schemas` renders
 * `SchemaDesigner` (and, per REQ-OBR-006a, does NOT mount the virtual app). Only
 * the affordance was missing, so the surface was reachable by typing a URL and
 * from a deep link `PageDesignerHost` builds by hand, but never from the nav.
 *
 * WHY AN `href` AND NOT A ROUTE NAME
 * ----------------------------------
 * nc-vue's `CnAppNav.itemTo()` builds `{ name, query }` only — it has no
 * `params` support:
 *
 *     return item.query ? { name: item.route, query: item.query } : { name: item.route }
 *
 * `/builder/:slug/schemas` is parameterised by definition, so a `route` entry
 * cannot address it and no STATIC manifest entry ever could. `item.action` is a
 * fixed library enum (`user-settings` / `admin-settings` / `replay-walkthrough`),
 * not a callback. That leaves `item.href`, which `CnAppNav.itemHref()` supports
 * and `NcAppNavigationItem` renders as a real anchor — internal app paths
 * navigate in the same tab.
 *
 * Trade-off, stated rather than hidden: an `href` is a full page load, not an
 * in-SPA `router.push`. Adding `params` support to `CnAppNav.itemTo()` would let
 * this become a route entry and is the better long-term fix; it belongs in
 * nc-vue, not here.
 *
 * WHY MUTATING `manifest.menu` IS THE SUPPORTED PATTERN
 * ----------------------------------------------------
 * `CnAppRoot` documents that it hands `CnAppNav` the live manifest BY IDENTITY
 * specifically so "async backend-merge updates" re-render the menu (see its
 * `reactive-menu tests`). `main.js` therefore wraps the merged manifest in
 * `reactive()` and this module edits `menu` in place.
 */

/** Stable id for the injected entry, so it can be found and removed again. */
export const BUILDER_SCHEMAS_MENU_ID = 'BuilderSchemas'

/**
 * Build the Schemas menu entry for one virtual app.
 *
 * @param {string} slug The virtual app's slug.
 * @param {Function} urlBuilder Maps an app-relative path to a URL — pass
 *   `generateUrl` from `@nextcloud/router`. Injected so this stays unit-testable
 *   without a Nextcloud global.
 * @param {Function} translate Maps a key to its translation — pass the app's `t`.
 * @return {object} A `CnAppNav` menu item.
 */
export function buildSchemasMenuEntry(slug, urlBuilder, translate) {
	return {
		id: BUILDER_SCHEMAS_MENU_ID,
		// REQ-OBR-007a mandates this key; `t` falls back to the literal when the
		// catalogue has not loaded, which is why the readable string is the key's
		// own default in l10n/en.json.
		label: translate('openbuild', 'openbuild.builder.menu.schemas'),
		icon: 'icon-category-organization',
		href: urlBuilder(`/apps/openbuild/builder/${slug}/schemas`),
		// Sits directly under the app-level entries, above the footer group.
		order: 40,
	}
}

/**
 * Show the Schemas entry for `slug`, replacing any entry left by another app.
 *
 * Idempotent: calling it repeatedly for the same slug leaves exactly one entry,
 * so a re-render or a `?_version=` change cannot accumulate duplicates.
 *
 * @param {object} manifest The live (reactive) app manifest.
 * @param {string} slug The virtual app's slug.
 * @param {Function} urlBuilder See {@link buildSchemasMenuEntry}.
 * @param {Function} translate See {@link buildSchemasMenuEntry}.
 * @return {void}
 */
export function showBuilderSchemasEntry(manifest, slug, urlBuilder, translate) {
	if (!manifest || !Array.isArray(manifest.menu) || !slug) {
		return
	}
	const entry = buildSchemasMenuEntry(slug, urlBuilder, translate)
	const existing = manifest.menu.findIndex((m) => m && m.id === BUILDER_SCHEMAS_MENU_ID)
	if (existing === -1) {
		manifest.menu.push(entry)
		return
	}
	// Splice rather than assign the fields one by one: the array is the reactive
	// unit CnAppNav re-renders from.
	manifest.menu.splice(existing, 1, entry)
}

/**
 * Remove the Schemas entry — the user has left the builder context.
 *
 * @param {object} manifest The live (reactive) app manifest.
 * @return {void}
 */
export function hideBuilderSchemasEntry(manifest) {
	if (!manifest || !Array.isArray(manifest.menu)) {
		return
	}
	const existing = manifest.menu.findIndex((m) => m && m.id === BUILDER_SCHEMAS_MENU_ID)
	if (existing !== -1) {
		manifest.menu.splice(existing, 1)
	}
}
