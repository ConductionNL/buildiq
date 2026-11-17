// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * offlineManifest — the manifest's `offline` block, as a maker declares it.
 *
 * `offline: { takeAlong: [{schema, filter, limit, ttlHours}], forms: [pageId] }`
 * says which records an app takes along to a device and which form pages keep
 * working without a connection (design D4). Every write here goes through
 * `normaliseOffline()`, so what is stored never exceeds the caps: 500 records
 * per schema and 72 hours.
 *
 * The "Waiting to sync" page (design D5) is kept in step with the offline
 * forms by `withSyncQueuePage()`: added when a form works offline, removed
 * when none does.
 *
 * @spec openspec/changes/pages-installable-offline-and-mobile/specs/installable-offline-apps/spec.md#requirement-a-maker-declares-what-goes-offline-req-bqom-003
 */

/** The most records one take-along may download. */
export const OFFLINE_LIMIT_MAX = 500

/** The longest a taken-along copy may be used, in hours. */
export const OFFLINE_TTL_MAX = 72

/** A new take-along's record limit. */
export const OFFLINE_LIMIT_DEFAULT = 50

/** A new take-along's lifetime, in hours. */
export const OFFLINE_TTL_DEFAULT = 24

/** The id, route and menu id of the generated "Waiting to sync" page. */
export const SYNC_QUEUE_PAGE_ID = 'waiting-to-sync'

/** The runtime registry key of the "Waiting to sync" page (CnOfflineQueue). */
export const SYNC_QUEUE_COMPONENT = 'offline-queue'

/**
 * A whole number clamped to [1, max], or the fallback when it is not a number.
 *
 * @param {unknown} value - the entered value.
 * @param {number} max - the cap.
 * @param {number} fallback - used when the value is not a number.
 * @return {number}
 */
function clampCount(value, max, fallback) {
	const number = Math.floor(Number(value))
	if (!Number.isFinite(number)) {
		return fallback
	}
	return Math.min(max, Math.max(1, number))
}

/**
 * The value when it is an array, else an empty array.
 *
 * @param {unknown} value - the value.
 * @return {Array}
 */
function arrayOr(value) {
	return Array.isArray(value) ? value : []
}

/**
 * A trimmed string of the value; '' for a missing or empty one.
 *
 * @param {unknown} value - the value.
 * @return {string}
 */
function trimmed(value) {
	return String(value || '').trim()
}

/**
 * A shallow copy of the value when it is a plain object, else an empty object.
 *
 * @param {unknown} value - the value.
 * @return {object}
 */
function plainCopy(value) {
	return value && typeof value === 'object' && !Array.isArray(value) ? { ...value } : {}
}

/**
 * One take-along entry with its caps applied, or null when it names no schema.
 *
 * @param {object} entry - the entry as entered.
 * @return {{schema: string, filter: object, limit: number, ttlHours: number}|null}
 *
 * @spec openspec/changes/pages-installable-offline-and-mobile/specs/installable-offline-apps/spec.md#requirement-a-maker-declares-what-goes-offline-req-bqom-003
 */
export function normaliseTakeAlong(entry) {
	const schema = trimmed(entry && entry.schema)
	if (schema === '') {
		return null
	}
	return {
		schema,
		filter: plainCopy(entry.filter),
		limit: clampCount(entry.limit, OFFLINE_LIMIT_MAX, OFFLINE_LIMIT_DEFAULT),
		ttlHours: clampCount(entry.ttlHours, OFFLINE_TTL_MAX, OFFLINE_TTL_DEFAULT),
	}
}

/**
 * The offline block with its caps applied, or null when it declares nothing.
 *
 * Duplicate form page ids are dropped; empty take-along rows are dropped.
 *
 * @param {object|null} block - the block as entered.
 * @return {{takeAlong: Array<object>, forms: Array<string>}|null}
 *
 * @spec openspec/changes/pages-installable-offline-and-mobile/specs/installable-offline-apps/spec.md#requirement-a-maker-declares-what-goes-offline-req-bqom-003
 */
export function normaliseOffline(block) {
	if (!block || typeof block !== 'object') {
		return null
	}
	const takeAlong = arrayOr(block.takeAlong)
		.map(normaliseTakeAlong)
		.filter(Boolean)
	const forms = [...new Set(arrayOr(block.forms).map(trimmed).filter(Boolean))]
	if (takeAlong.length === 0 && forms.length === 0) {
		return null
	}
	return { takeAlong, forms }
}

/**
 * The manifest's offline form page ids.
 *
 * @param {object} manifest - the manifest.
 * @return {Array<string>}
 *
 * @spec openspec/changes/pages-installable-offline-and-mobile/specs/installable-offline-apps/spec.md#requirement-a-maker-declares-what-goes-offline-req-bqom-003
 */
export function offlineForms(manifest) {
	const forms = manifest && manifest.offline && manifest.offline.forms
	return Array.isArray(forms) ? forms.slice() : []
}

/**
 * A copy of the manifest with this offline block, the sync page kept in step.
 *
 * @param {object} manifest - the manifest.
 * @param {object|null} block - the new offline block; null removes it.
 * @return {object} The new manifest.
 *
 * @spec openspec/changes/pages-installable-offline-and-mobile/specs/installable-offline-apps/spec.md#requirement-a-maker-declares-what-goes-offline-req-bqom-003
 */
export function withOffline(manifest, block) {
	const next = { ...(manifest || {}) }
	const offline = normaliseOffline(block)
	if (offline === null) {
		delete next.offline
	} else {
		next.offline = offline
	}
	return withSyncQueuePage(next)
}

/**
 * The manifest's take-along entries as stored; [] when it has none.
 *
 * @param {object} manifest - the manifest.
 * @return {Array<object>}
 */
function currentTakeAlong(manifest) {
	const current = (manifest && manifest.offline) || {}
	return current.takeAlong || []
}

/**
 * A copy of the manifest with one form page switched to work offline, or not.
 *
 * @param {object} manifest - the manifest.
 * @param {string} pageId - the form page's id.
 * @param {boolean} on - whether the page works offline.
 * @return {object} The new manifest.
 *
 * @spec openspec/changes/pages-installable-offline-and-mobile/specs/installable-offline-apps/spec.md#requirement-a-maker-declares-what-goes-offline-req-bqom-003
 */
export function withFormOffline(manifest, pageId, on) {
	const id = trimmed(pageId)
	if (id === '') {
		return { ...(manifest || {}) }
	}
	const forms = offlineForms(manifest).filter((form) => form !== id)
	if (on) {
		forms.push(id)
	}
	return withOffline(manifest, { takeAlong: currentTakeAlong(manifest), forms })
}

/**
 * A copy of the manifest with the "Waiting to sync" page and menu entry
 * present exactly when a form works offline.
 *
 * Only the generated page (id `waiting-to-sync`) is touched: a maker's own
 * page or menu entry is never removed.
 *
 * @param {object} manifest - the manifest.
 * @return {object} The new manifest.
 *
 * @spec openspec/changes/pages-installable-offline-and-mobile/specs/installable-offline-apps/spec.md#requirement-app-users-see-what-is-still-on-the-device-req-bqom-005
 */
export function withSyncQueuePage(manifest) {
	const next = { ...(manifest || {}) }
	const wanted = offlineForms(next).length > 0
	const pages = withoutSyncQueue(next.pages)
	const menu = withoutSyncQueue(next.menu)
	if (wanted) {
		pages.push(syncQueuePage())
		menu.push(syncQueueMenuItem(menu))
	}
	keepList(next, 'pages', pages, wanted)
	keepList(next, 'menu', menu, wanted)
	return next
}

/**
 * Store a rebuilt list on the manifest when it had one or the sync page is wanted.
 *
 * @param {object} next - the manifest copy, changed in place.
 * @param {string} key - `pages` or `menu`.
 * @param {Array<object>} list - the rebuilt list.
 * @param {boolean} wanted - whether the sync page is wanted.
 * @return {void}
 */
function keepList(next, key, list, wanted) {
	if (Array.isArray(next[key]) || wanted) {
		next[key] = list
	}
}

/**
 * A copy of a page or menu list without the generated sync entry.
 *
 * @param {unknown} list - the pages or menu entries.
 * @return {Array<object>}
 */
function withoutSyncQueue(list) {
	return arrayOr(list).filter((item) => !item || item.id !== SYNC_QUEUE_PAGE_ID)
}

/**
 * The generated "Waiting to sync" page.
 *
 * @return {object}
 */
function syncQueuePage() {
	return {
		id: SYNC_QUEUE_PAGE_ID,
		route: '/' + SYNC_QUEUE_PAGE_ID,
		type: 'custom',
		title: 'Waiting to sync',
		component: SYNC_QUEUE_COMPONENT,
		config: {},
	}
}

/**
 * The generated menu entry of the sync page, ordered after every other entry.
 *
 * @param {Array<object>} menu - the other menu entries.
 * @return {object}
 */
function syncQueueMenuItem(menu) {
	const order = menu.reduce((max, item) => Math.max(max, Number(item && item.order) || 0), 0) + 10
	return { id: SYNC_QUEUE_PAGE_ID, label: 'Waiting to sync', icon: 'CloudSyncOutline', route: SYNC_QUEUE_PAGE_ID, order }
}
