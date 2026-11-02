// SPDX-License-Identifier: EUPL-1.2
/**
 * manifestRouting: make a manifest navigable before the router is built.
 *
 * The runtime names every route after its page `id`, and CnAppNav points each
 * menu entry at a route NAME. Two manifests in the wild break that silently and
 * identically: nothing throws, the app just opens empty.
 *
 * - A page with no `id` becomes a route with `name: undefined`. CnPageRenderer
 *   matches by name, so the page renders nothing. Apps published before page
 *   ids existed arrive from the store this way.
 * - A menu entry whose `route` is a path (`/incidents`) rather than a page id
 *   resolves to no route at all. vue-router logs one error per entry and the
 *   entry disappears, so the app has no navigation.
 *
 * Both are repaired here, in place, from what the manifest already says: a page
 * without an id is named after its own route, and a menu entry pointing at a
 * page's path is repointed at that page's id.
 *
 * @module services/manifestRouting
 * @spec openspec/specs/openbuild-runtime/spec.md
 */

/**
 * The pages array of a manifest, or an empty array.
 *
 * @param {?object} manifest - the app manifest.
 * @return {Array<object>} the pages.
 * @spec exclude shape helper
 */
function pagesOf(manifest) {
	return manifest && Array.isArray(manifest.pages) ? manifest.pages : []
}

/**
 * Whether a value is a string with at least one character.
 *
 * @param {unknown} value - the value to test.
 * @return {boolean}
 * @spec exclude shape helper
 */
function isFilled(value) {
	return typeof value === 'string' && value !== ''
}

/**
 * Name every page without an id after its own route.
 *
 * The route is the only stable thing such a page carries, and it is unique
 * within a manifest, so it doubles as the route name.
 *
 * @param {Array<object>} pages - the pages.
 * @return {void}
 * @spec exclude step of normalizeManifestRouting
 */
function nameUnnamedPages(pages) {
	for (const page of pages) {
		if (!page || typeof page !== 'object' || isFilled(page.id)) {
			continue
		}
		if (isFilled(page.route)) {
			page.id = page.route
		}
	}
}

/**
 * The page ids of a manifest, and the first page id per page path.
 *
 * @param {Array<object>} pages - the pages.
 * @return {{ids: Set<string>, idByRoute: Map<string,string>}} the index.
 * @spec exclude step of normalizeManifestRouting
 */
function pageIndex(pages) {
	const idByRoute = new Map()
	const ids = new Set()
	for (const page of pages) {
		if (!page || !isFilled(page.id)) {
			continue
		}
		ids.add(page.id)
		if (isFilled(page.route) && !idByRoute.has(page.route)) {
			idByRoute.set(page.route, page.id)
		}
	}
	return { ids, idByRoute }
}

/**
 * Repoint one level of menu entries, then their children.
 *
 * @param {Array<object>} items - menu entries.
 * @param {{ids: Set<string>, idByRoute: Map<string,string>}} index - the page
 *   ids this manifest declares, and the page id per page path.
 * @return {void}
 * @spec exclude recursion helper for normalizeManifestRouting
 */
function repointMenu(items, index) {
	for (const item of items) {
		if (!item || typeof item !== 'object') {
			continue
		}
		if (
			isFilled(item.route)
			&& !index.ids.has(item.route)
			&& index.idByRoute.has(item.route)
		) {
			item.route = index.idByRoute.get(item.route)
		}
		if (Array.isArray(item.children)) {
			repointMenu(item.children, index)
		}
	}
}

/**
 * Give every page an id, and point every menu entry at one.
 *
 * Mutates the manifest in place, because the runtime hands this same object to
 * the shell and to the in-app editor.
 *
 * @param {?object} manifest - the app manifest.
 * @return {object} the same manifest.
 * @spec openspec/specs/openbuild-runtime/spec.md
 */
export function normalizeManifestRouting(manifest) {
	if (!manifest || typeof manifest !== 'object') {
		return manifest
	}

	const pages = pagesOf(manifest)
	nameUnnamedPages(pages)
	repointMenu(Array.isArray(manifest.menu) ? manifest.menu : [], pageIndex(pages))

	return manifest
}
