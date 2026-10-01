// SPDX-License-Identifier: EUPL-1.2
/**
 * pageCopy: copy one page of a manifest (change apps-copy-app-and-page,
 * REQ-BQCP-003). The copy is a deep copy inserted below its source, with an
 * id and a route no other page has and a title that says it is a copy. Menu
 * entries are not copied: a copied page is not in the menu until the maker
 * adds it.
 *
 * @spec openspec/specs/copy-app-page-and-form/spec.md#requirement-a-maker-copies-a-page-req-bqcp-003
 */

/**
 * The first `<base>-copy`, `<base>-copy-2`, ... that `taken` does not hold.
 *
 * @param {string} base The value to suffix.
 * @param {Function} taken `(candidate) => boolean`.
 * @return {string}
 */
function nextFree(base, taken) {
	let candidate = base + '-copy'
	let n = 2
	while (taken(candidate)) {
		candidate = base + '-copy-' + n
		n += 1
	}
	return candidate
}

/**
 * A route with its last static segment suffixed, unique among `routes`.
 *
 * @param {string} route The source route, such as `/permits/:id`.
 * @param {Set<string>} routes The routes in use.
 * @return {string}
 */
function copyRoute(route, routes) {
	const parts = String(route || '').split('/')
	let at = -1
	parts.forEach((part, i) => {
		if (part !== '' && !part.startsWith(':')) {
			at = i
		}
	})
	if (at === -1) {
		return route
	}
	const base = parts[at]
	const withSuffix = (suffixed) =>
		parts.map((part, i) => (i === at ? suffixed : part)).join('/')
	const suffixed = nextFree(base, (candidate) => routes.has(withSuffix(candidate)))
	return withSuffix(suffixed)
}

/**
 * The pages with a copy of `pages[index]` inserted below it.
 *
 * @param {Array<object>} pages The manifest's pages.
 * @param {number} index The page to copy.
 * @param {Function} titleOf `(title) => string`, the copy's title.
 * @return {Array<object>} A new array; the input is not changed.
 *
 * @spec openspec/specs/copy-app-page-and-form/spec.md#requirement-a-maker-copies-a-page-req-bqcp-003
 */
export function copyPage(pages, index, titleOf) {
	const source = pages[index]
	if (!source) {
		return pages.slice()
	}
	const copy = JSON.parse(JSON.stringify(source))
	const ids = new Set(pages.map((page) => page && page.id))
	copy.id = nextFree(String(source.id || 'page'), (candidate) =>
		ids.has(candidate),
	)
	if (typeof source.route === 'string' && source.route !== '') {
		copy.route = copyRoute(
			source.route,
			new Set(pages.map((page) => page && page.route)),
		)
	}
	copy.title = titleOf(source.title || source.id || '')
	const next = pages.slice()
	next.splice(index + 1, 0, copy)
	return next
}
