/**
 * Shared fetch for the Application record behind the app-detail page.
 *
 * WHY THIS EXISTS (#49). `ApplicationDetailHeader` and
 * `ApplicationDetailDashboard` each resolve the same Application themselves —
 * CnDetailPage's `#header` / `#before-body` slots forward only presentational
 * props, not the resolved record — and each is driven by three independent
 * triggers (`mounted()`, the `objectId` watcher, the `object` watcher). None of
 * them knew about the others, so one load of `/applications/hydra-console`
 * issued TEN identical `GET .../objects/openbuild/application/hydra-console`
 * requests, all 200.
 *
 * That is not just waste: it is most of the reason the page takes so long to
 * settle, and a slow-settling page is what made the detail view *look* broken
 * under load (the placeholder header and empty register render until the real
 * record lands).
 *
 * The fix is deliberately small — a module-scoped in-flight map keyed by uuid,
 * so concurrent callers share one request and one promise. It is NOT a cache
 * with a TTL: the entry is dropped as soon as the request settles, so every
 * fresh call still goes to the server and no component can observe a stale
 * record. Only the concurrent stampede is collapsed.
 *
 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-1
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * In-flight requests keyed by application uuid/slug.
 *
 * @type {Map<string, Promise<object|null>>}
 */
const inFlight = new Map()

/**
 * Fetch an Application record, coalescing concurrent callers onto one request.
 *
 * @param {string} uuid Application uuid or slug.
 * @return {Promise<object|null>} The record, or null when the payload is empty.
 *
 * @spec openspec/specs/application-detail-ui/spec.md
 */
export function fetchApplicationRecord(uuid) {
	if (!uuid) return Promise.resolve(null)

	const pending = inFlight.get(uuid)
	if (pending) return pending

	const request = (async () => {
		const url = generateUrl(
			`/apps/openregister/api/objects/openbuild/application/${encodeURIComponent(uuid)}`,
		)
		const { data } = await axios.get(url)
		// Keep user-visible fields from `data` and stash OR's internal metadata
		// block separately (see issue #73).
		return data ? { ...data, '@self': data['@self'] || {} } : null
	})()

	inFlight.set(uuid, request)

	// Drop the entry whether it resolved or rejected — a failed request must not
	// pin a rejected promise that every later caller would then re-await.
	//
	// `.then(cleanup, cleanup)` rather than `.finally(cleanup)`: `.finally()`
	// returns a NEW promise that re-throws the original rejection, and nothing
	// handles that derived promise — so every failed fetch logged an
	// "Unhandled promise rejection" in the browser console. Passing an
	// onRejected handler marks the rejection handled here, while callers still
	// receive the original rejected `request` and can handle it themselves.
	const cleanup = () => {
		if (inFlight.get(uuid) === request) inFlight.delete(uuid)
	}
	request.then(cleanup, cleanup)

	return request
}

/**
 * Test seam — drop any in-flight entries.
 *
 * @return {void}
 */
export function __resetApplicationRecordCache() {
	inFlight.clear()
}
