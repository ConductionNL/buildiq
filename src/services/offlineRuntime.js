// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * offlineRuntime — what a running app does about offline when it boots.
 *
 * Nothing at all unless the instance allows offline AND the app declares an
 * `offline` block (design D3). Then: with a session, it registers the app's
 * worker under its own run scope (design D1) and downloads each take-along
 * into the offline cache with its expiry (design D4); without one, it clears
 * this app's records from the device, so a signed-out device keeps none
 * (REQ-BQOM-004).
 *
 * The library calls are injected so the boot path is tested with a fake
 * worker container and a fake cache.
 *
 * @spec openspec/changes/pages-installable-offline-and-mobile/specs/installable-offline-apps/spec.md#requirement-a-maker-declares-what-goes-offline-req-bqom-003
 */

import { normaliseOffline } from './offlineManifest.js'

/** The offline cache collection prefix; one collection per app. */
export const COLLECTION_PREFIX = 'buildiq:'

/**
 * The offline cache collection of one app's taken-along records.
 *
 * @param {string} slug - the app slug.
 * @return {string}
 */
export function collectionFor(slug) {
	return COLLECTION_PREFIX + slug
}

/**
 * The worker script URL and the scope it controls, both under the run path.
 *
 * @param {string} slug - the app slug.
 * @param {(path: string) => string} generateUrl - Nextcloud's URL builder.
 * @return {{scriptUrl: string, scope: string}}
 */
export function workerUrls(slug, generateUrl) {
	const base = generateUrl(`/apps/buildiq/run/${encodeURIComponent(slug)}/`)
	return { scriptUrl: base + 'offline-worker.js', scope: base }
}

/**
 * The OpenRegister query of one take-along: its filter and its record limit.
 *
 * @param {{schema: string, filter: object, limit: number}} entry - the take-along.
 * @return {object}
 */
export function takeAlongQuery(entry) {
	return { ...entry.filter, _limit: entry.limit }
}

/**
 * The app's normalised offline block, or null when offline does not apply:
 * the instance does not allow it, the app declares nothing, or it has no slug.
 *
 * @param {string} slug - the app slug.
 * @param {object} manifest - the app's manifest.
 * @param {boolean} allowed - whether the instance lets apps work offline.
 * @return {{takeAlong: Array<object>, forms: Array<string>}|null}
 */
function activeOffline(slug, manifest, allowed) {
	const offline = normaliseOffline(manifest && manifest.offline)
	if (allowed !== true || offline === null || !slug) {
		return null
	}
	return offline
}

/**
 * Start offline for a booting app.
 *
 * @param {object} args - the boot facts and the library calls.
 * @param {string} args.slug - the app slug.
 * @param {object} args.manifest - the app's manifest.
 * @param {boolean} args.allowed - whether the instance lets apps work offline.
 * @param {boolean} args.signedIn - whether the app loaded with a session.
 * @param {string} args.register - the app's register.
 * @param {(path: string) => string} args.generateUrl - Nextcloud's URL builder.
 * @param {Function} args.registerOfflineWorker - the library's worker registration.
 * @param {Function} args.storePlanning - the library's cache write.
 * @param {Function} args.fetchObjects - reads `{register, schema, query}` and resolves the records.
 * @param {Function} args.clearCollection - removes one collection from the cache.
 * @return {Promise<{registered: boolean, stored: number, cleared: boolean}>}
 *
 * @spec openspec/changes/pages-installable-offline-and-mobile/specs/installable-offline-apps/spec.md#requirement-app-users-work-offline-and-sync-later-req-bqom-004
 */
export async function startOffline({
	slug,
	manifest,
	allowed,
	signedIn,
	register,
	generateUrl,
	registerOfflineWorker,
	storePlanning,
	fetchObjects,
	clearCollection,
}) {
	const result = { registered: false, stored: 0, cleared: false }
	const offline = activeOffline(slug, manifest, allowed)
	if (offline === null) {
		return result
	}

	if (signedIn !== true) {
		await clearCollection(collectionFor(slug))
		result.cleared = true
		return result
	}

	result.registered = (await registerOfflineWorker(workerUrls(slug, generateUrl))) !== null

	for (const entry of offline.takeAlong) {
		result.stored += await takeAlongOne({ slug, register, entry, storePlanning, fetchObjects })
	}
	return result
}

/**
 * Download one take-along into the offline cache.
 *
 * A schema that cannot be read is logged and counts zero: it leaves the
 * others on the device.
 *
 * @param {object} args - the take-along and the library calls.
 * @param {string} args.slug - the app slug.
 * @param {string} args.register - the app's register.
 * @param {{schema: string, filter: object, limit: number, ttlHours: number}} args.entry - the take-along.
 * @param {(args: object) => Promise<void>} args.storePlanning - the library's cache write.
 * @param {(args: object) => Promise<Array<object>>} args.fetchObjects - reads `{register, schema, query}` and resolves the records.
 * @return {Promise<number>} The number of records stored.
 */
async function takeAlongOne({ slug, register, entry, storePlanning, fetchObjects }) {
	try {
		const items = await fetchObjects({ register, schema: entry.schema, query: takeAlongQuery(entry) })
		const list = (Array.isArray(items) ? items : []).slice(0, entry.limit)
		await storePlanning({
			register,
			schema: entry.schema,
			items: list,
			collection: collectionFor(slug),
			ttlMs: entry.ttlHours * 60 * 60 * 1000,
		})
		return list.length
	} catch (error) {
		// One schema that cannot be read leaves the others on the device.
		// eslint-disable-next-line no-console
		console.warn('[buildiq:offline] a take-along was not downloaded', entry.schema, error)
		return 0
	}
}
