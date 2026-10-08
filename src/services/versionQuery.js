// SPDX-License-Identifier: EUPL-1.2
/**
 * versionQuery: keep `?_version=` on every link inside a running app.
 *
 * A running app opened as `/builder/<slug>?_version=development` shows that
 * version. Its menu links are built from route names and carried no query,
 * so one click inside the app dropped `?_version=`, and the next reload
 * showed the production version instead.
 *
 * @module services/versionQuery
 * @spec openspec/specs/version-routing/spec.md#requirement-browser-reload-preserves-_version-bookmarkability
 */

/**
 * A route location with `_version` added to its query.
 *
 * @param {string|object} to - a vue-router location.
 * @param {string} versionSlug - the version to keep.
 * @return {string|object} the location, carrying the version.
 * @spec openspec/specs/version-routing/spec.md#requirement-browser-reload-preserves-_version-bookmarkability
 */
export function withVersion(to, versionSlug) {
	if (!versionSlug || to === null || to === undefined) {
		return to
	}
	if (typeof to === 'string') {
		if (/[?&]_version=/.test(to)) {
			return to
		}
		const hash = to.indexOf('#')
		const path = hash === -1 ? to : to.slice(0, hash)
		const fragment = hash === -1 ? '' : to.slice(hash)
		const joiner = path.includes('?') ? '&' : '?'
		return `${path}${joiner}_version=${encodeURIComponent(versionSlug)}${fragment}`
	}
	if (typeof to !== 'object') {
		return to
	}
	const query = to.query || {}
	if (query._version) {
		return to
	}
	return { ...to, query: { ...query, _version: versionSlug } }
}

/**
 * Make a router keep `?_version=` on every href it builds and every
 * navigation it makes.
 *
 * @param {object} router - a vue-router 4 router.
 * @param {string} versionSlug - the version to keep; nothing changes when empty.
 * @return {object} the same router.
 * @spec openspec/specs/version-routing/spec.md#requirement-browser-reload-preserves-_version-bookmarkability
 */
export function keepVersionQuery(router, versionSlug) {
	if (!router || !versionSlug) {
		return router
	}
	// RouterLink builds its href through router.resolve().
	const resolve = router.resolve.bind(router)
	router.resolve = (to, current) => resolve(withVersion(to, versionSlug), current)
	// push() and replace() resolve internally, so a guard covers them too.
	router.beforeEach((to) => {
		if (to.query && to.query._version) {
			return true
		}
		return {
			path: to.path,
			query: { ...to.query, _version: versionSlug },
			hash: to.hash,
		}
	})
	return router
}
