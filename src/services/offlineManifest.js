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
 * One take-along entry with its caps applied, or null when it names no schema.
 *
 * @param {object} entry - the entry as entered.
 * @return {{schema: string, filter: object, limit: number, ttlHours: number}|null}
 *
 * @spec openspec/changes/pages-installable-offline-and-mobile/specs/installable-offline-apps/spec.md#requirement-a-maker-declares-what-goes-offline-req-bqom-003
 */
export function normaliseTakeAlong(entry) {
	const schema = String((entry && entry.schema) || '').trim()
	if (schema === '') {
		return null
	}
	const filter = entry.filter && typeof entry.filter === 'object' && !Array.isArray(entry.filter)
		? { ...entry.filter }
		: {}
	return {
		schema,
		filter,
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
	const takeAlong = (Array.isArray(block.takeAlong) ? block.takeAlong : [])
		.map(normaliseTakeAlong)
		.filter(Boolean)
	const forms = [...new Set(
		(Array.isArray(block.forms) ? block.forms : [])
			.map((id) => String(id || '').trim())
			.filter(Boolean),
	)]
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
	const id = String(pageId || '').trim()
	if (id === '') {
		return { ...(manifest || {}) }
	}
	const current = (manifest && manifest.offline) || {}
	const forms = offlineForms(manifest).filter((form) => form !== id)
	if (on) {
		forms.push(id)
	}
	return withOffline(manifest, { takeAlong: current.takeAlong || [], forms })
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
	const pages = (Array.isArray(next.pages) ? next.pages : []).filter((page) => !page || page.id !== SYNC_QUEUE_PAGE_ID)
	const menu = (Array.isArray(next.menu) ? next.menu : []).filter((item) => !item || item.id !== SYNC_QUEUE_PAGE_ID)
	if (wanted) {
		pages.push({
			id: SYNC_QUEUE_PAGE_ID,
			route: '/' + SYNC_QUEUE_PAGE_ID,
			type: 'custom',
			title: 'Waiting to sync',
			component: SYNC_QUEUE_COMPONENT,
			config: {},
		})
		const order = menu.reduce((max, item) => Math.max(max, Number(item && item.order) || 0), 0) + 10
		menu.push({ id: SYNC_QUEUE_PAGE_ID, label: 'Waiting to sync', icon: 'CloudSyncOutline', route: SYNC_QUEUE_PAGE_ID, order })
	}
	if (Array.isArray(next.pages) || wanted) {
		next.pages = pages
	}
	if (Array.isArray(next.menu) || wanted) {
		next.menu = menu
	}
	return next
}
