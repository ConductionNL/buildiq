// SPDX-License-Identifier: EUPL-1.2
/**
 * selectors — shared dot-path selector grammar used by both the runtime
 * connector resolver (`useConnectorDataSource`) and the builder mapping
 * editor (`ConnectorFieldMapper`).
 *
 * The grammar matches the existing `dataSource.graphql.selectors` shape:
 * a dot-separated path of object keys and numeric array indices, e.g.
 * `resultaten.0.adres.straat`. There is intentionally NO bracket / wildcard
 * syntax — keep it the minimal subset every binding shares so the same
 * resolver works for the runtime read path and the designer preview.
 *
 * @spec openspec/changes/openconnector-api-sources/tasks.md#task-4.1
 */

/**
 * Resolve a dot-path selector against a value.
 *
 * Numeric segments index into arrays; everything else is an object key.
 * A segment that does not resolve (missing key / out-of-range index /
 * traversal through a non-object) yields `undefined` so the caller can
 * distinguish "absent" from a real `null` in the payload.
 *
 * @param {*} value - the root value to traverse (object/array/scalar).
 * @param {string} selector - dot-path like `resultaten.0.naam`. Empty /
 *   nullish selector returns the root value unchanged.
 * @return {*} - the resolved value, or `undefined` when the path breaks.
 * @spec openspec/changes/openconnector-api-sources/specs/openconnector-api-sources/spec.md#req-ocas-006
 */
export function resolveSelector(value, selector) {
	if (isBlankPath(selector)) {
		return value
	}
	const segments = String(selector).split('.')
	let cursor = value
	for (const segment of segments) {
		if (!isTraversable(cursor)) {
			return undefined
		}
		cursor = cursor[segment]
	}
	return cursor
}

/**
 * Whether a selector or items path is absent: undefined, null or ''.
 *
 * @param {unknown} path - the selector.
 * @return {boolean}
 */
function isBlankPath(path) {
	return path === undefined || path === null || path === ''
}

/**
 * Whether a path can step into a value: a non-null object or array.
 *
 * @param {unknown} cursor - the value reached so far.
 * @return {boolean}
 */
function isTraversable(cursor) {
	return cursor !== null && typeof cursor === 'object'
}

/**
 * Apply a `{ fieldName -> selector }` map to a single item, producing a
 * `{ fieldName -> resolvedValue }` row. Unresolved selectors yield `null`
 * (a renderable empty cell) and are reported via the `onMissing` callback
 * so the caller can warn exactly once per field per mount.
 *
 * @param {object} item - one item from the response (after itemsPath).
 * @param {object} fields - map of display field name -> dot-path selector.
 * @param {Function} [onMissing] - called `(fieldName, selector)` for each
 *   selector that resolved to `undefined`.
 * @return {object} - the projected row.
 * @spec openspec/changes/openconnector-api-sources/specs/openconnector-api-sources/spec.md#req-ocas-006
 */
export function projectFields(item, fields, onMissing) {
	const row = {}
	const map = fields || {}
	for (const fieldName of Object.keys(map)) {
		const selector = map[fieldName]
		const resolved = resolveSelector(item, selector)
		if (resolved === undefined) {
			if (typeof onMissing === 'function') {
				onMissing(fieldName, selector)
			}
			row[fieldName] = null
		} else {
			row[fieldName] = resolved
		}
	}
	return row
}

/**
 * Apply `itemsPath` to a response, returning the list of items. When
 * `itemsPath` is absent the response root is treated as a single item and
 * wrapped in a one-element array. A non-array resolution (or a broken path)
 * yields an empty list.
 *
 * @param {*} response - the raw response payload.
 * @param {string} [itemsPath] - dot-path to the list root.
 * @return {Array} - the items list (possibly empty).
 * @spec openspec/changes/openconnector-api-sources/specs/openconnector-api-sources/spec.md#req-ocas-006
 */
export function extractItems(response, itemsPath) {
	if (isBlankPath(itemsPath)) {
		return isAbsent(response) ? [] : [response]
	}
	const resolved = resolveSelector(response, itemsPath)
	return Array.isArray(resolved) ? resolved : []
}

/**
 * Whether a response is undefined or null.
 *
 * @param {unknown} response - the raw response payload.
 * @return {boolean}
 */
function isAbsent(response) {
	return response === undefined || response === null
}

/**
 * Build a flat list of `{ path, value, isLeaf, isArray }` descriptors for a
 * sample payload, used by the mapping editor's JSON tree. Walks objects and
 * arrays to a bounded depth so a pathological payload cannot lock the UI.
 *
 * @param {*} value - the sample payload (or a sub-tree).
 * @param {string} [basePath] - accumulated dot-path prefix.
 * @param {number} [maxDepth] - recursion guard (default 8).
 * @return {Array<{path: string, value: *, isLeaf: boolean, isArray: boolean}>}
 * @spec openspec/changes/openconnector-api-sources/specs/openconnector-api-sources/spec.md#req-ocas-003
 */
export function flattenSample(value, basePath = '', maxDepth = 8) {
	const out = []
	const walk = (node, path, depth) => {
		const isArray = Array.isArray(node)
		const isObject = node !== null && typeof node === 'object'
		const isLeaf = !isObject
		out.push({ path, value: node, isLeaf, isArray })
		if (isLeaf || depth >= maxDepth) {
			return
		}
		const keys = isArray ? node.map((_, i) => String(i)) : Object.keys(node)
		for (const key of keys) {
			const childPath = path ? `${path}.${key}` : key
			walk(node[key], childPath, depth + 1)
		}
	}
	walk(value, basePath, 0)
	return out
}
