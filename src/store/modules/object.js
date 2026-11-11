import { getRequestToken } from '@nextcloud/auth'
// SPDX-License-Identifier: EUPL-1.2
import { defineStore } from 'pinia'

/**
 * The objects request URL for a registered type: its register and schema, then
 * the extra query parameters.
 *
 * @param {object} store The object store.
 * @param {{type: string, params: object}} request The type key and the extra query parameters.
 * @return {string}
 */
function objectsUrl(store, request) {
	const { schema, register } = store.objectTypes[request.type]
	const url = new URL(store.baseUrl, window.location.origin)
	url.searchParams.set('register', register)
	url.searchParams.set('schema', schema)
	Object.entries(request.params).forEach(([k, v]) => url.searchParams.set(k, v))
	return url.toString()
}

/**
 * Fetch the objects of a type and cache them; [] on a non-ok answer or a
 * failure (logged), leaving the cache as it was. Clears the loading flag.
 *
 * @param {object} store The object store.
 * @param {{type: string, params: object}} request The type key and the extra query parameters.
 * @return {Promise<object[]>}
 */
async function loadObjects(store, request) {
	try {
		const results = await fetchResults(objectsUrl(store, request))
		if (results !== null) {
			store.objects[request.type] = results
			return store.objects[request.type]
		}
		return []
	} catch (error) {
		console.error(`Failed to fetch ${request.type} objects:`, error)
		return []
	} finally {
		store.loading[request.type] = false
	}
}

/**
 * GET an objects URL and unwrap the OpenRegister envelope.
 *
 * @param {string} url The objects request URL.
 * @return {Promise<?(object[])>} The results, or null on a non-ok answer.
 */
async function fetchResults(url) {
	const response = await fetch(url, {
		headers: { requesttoken: getRequestToken() },
	})
	if (!response.ok) {
		return null
	}
	const data = await response.json()
	return data.results || data
}

/**
 * Generic OpenRegister object store.
 * Configure it with baseUrl and schemaBaseUrl, then register object types.
 */
export const useObjectStore = defineStore('object', {
	state: () => ({
		baseUrl: '',
		schemaBaseUrl: '',
		objectTypes: {},
		objects: {},
		loading: {},
	}),

	actions: {
		/**
		 * Observed behaviour of `configure` (retrofit annotation).
		 *
		 * Called once from `initializeStores()` (src/store/store.js) with the two
		 * OpenRegister REST roots this store issues its `fetch` calls against.
		 *
		 * @param {{baseUrl: string, schemaBaseUrl: string}} endpoints - The OpenRegister
		 *   API roots, already run through `generateUrl()` so they carry the instance's
		 *   index.php prefix.
		 * @param {string} endpoints.baseUrl - Root of OR's objects API
		 *   (`/apps/openregister/api/objects`); `fetchObjects` appends `register` and
		 *   `schema` query parameters to it.
		 * @param {string} endpoints.schemaBaseUrl - Root of OR's schemas API
		 *   (`/apps/openregister/api/schemas`); stored for consumers, not used by this
		 *   store's own actions.
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-frontend-foundation/tasks.md#task-3
		 */
		configure({ baseUrl, schemaBaseUrl }) {
			this.baseUrl = baseUrl
			this.schemaBaseUrl = schemaBaseUrl
		},

		/**
		 * Observed behaviour of `registerObjectType` (retrofit annotation).
		 *
		 * Binds a local type key to the OpenRegister register/schema pair that backs
		 * it, and seeds an empty cache bucket for it. Re-registering a type overwrites
		 * its binding but keeps any already-cached objects.
		 *
		 * @param {string} type - Local key the app addresses this collection by
		 *   (e.g. `application`); used for `objects[type]` and `loading[type]`.
		 * @param {string} schema - OpenRegister schema slug, sent verbatim as the
		 *   `schema` query parameter of the objects request.
		 * @param {string} register - OpenRegister register slug, sent verbatim as the
		 *   `register` query parameter of the objects request.
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-frontend-foundation/tasks.md#task-3
		 */
		registerObjectType(type, schema, register) {
			this.objectTypes[type] = { schema, register }
			if (!this.objects[type]) {
				this.objects[type] = []
			}
		},

		/**
		 * Observed behaviour of `fetchObjects` (retrofit annotation).
		 *
		 * OpenRegister answers the objects endpoint with an envelope
		 * (`{ results, total, page, pages }`), never a bare array — the `data.results
		 * || data` below is what unwraps it. The value this action returns and caches
		 * is therefore the `results` array, not the envelope.
		 *
		 * Never throws: a network failure or a non-2xx response is logged and yields
		 * an empty array, leaving any previously cached objects for `type` untouched.
		 *
		 * @param {string} type - A type key previously passed to `registerObjectType`;
		 *   an unregistered key logs a warning and short-circuits to `[]`.
		 * @param {{[key: string]: string|number|boolean}} [params] - Extra query
		 *   parameters merged into the OpenRegister request (paging/filtering, e.g.
		 *   `{ _limit: 8 }`); each value is stringified by `URLSearchParams`.
		 * @return {Promise<object[]>} The register objects for `type`, or `[]` when the
		 *   type is unregistered or the request failed.
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-frontend-foundation/tasks.md#task-3
		 */
		async fetchObjects(type, params = {}) {
			if (!this.objectTypes[type]) {
				console.warn(`Object type "${type}" is not registered`)
				return []
			}

			this.loading[type] = true
			return loadObjects(this, { type, params })
		},
	},
})
