// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// useApplicationInsights — wraps GET /apps/openbuilt/api/applications/{appUuid}
//   /versions/{versionUuid}/insights?window=… and exposes a reactive
//   `{ kpis, activity, loading, error, versionNoLongerAccessible, refresh }`
//   surface for the maintainer-dashboard header.
//
// Behaviour:
//   - Fetches on mount.
//   - Watches `appUuid`, `versionUuid`, `window` refs and re-fetches when
//     any of them change. The watcher is debounced 200ms to absorb toggle
//     bounce (Decision-11 risk mitigation in design.md).
//   - On 404 sets `versionNoLongerAccessible: true` so the header can
//     render a banner without crashing.
//   - On other errors sets `error` and clears the data.
//
// Spec: openbuilt-app-detail-overview / capability application-insights
// (REQ-OBAI-001, REQ-OBAI-002, REQ-OBAI-006).

import { ref, watch } from 'vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Composable that exposes the live insights payload for an Application + Version.
 *
 * `appUuidRef`, `versionUuidRef`, and `windowRef` may be plain strings OR
 * Vue refs. Refs are watched; plain strings are fetched once on mount.
 *
 * @param {import('vue').Ref<string>|string} appUuidRef     Application UUID (reactive or plain).
 * @param {import('vue').Ref<string>|string} versionUuidRef ApplicationVersion UUID (reactive or plain).
 * @param {import('vue').Ref<string>|string} windowRef      Window value (`7d`/`30d`/`90d`).
 * @return {{
 *   kpis: import('vue').Ref<object>,
 *   activity: import('vue').Ref<Array<object>>,
 *   loading: import('vue').Ref<boolean>,
 *   error: import('vue').Ref<Error|null>,
 *   versionNoLongerAccessible: import('vue').Ref<boolean>,
 *   refresh: () => Promise<void>,
 * }}
 */
export function useApplicationInsights(appUuidRef, versionUuidRef, windowRef) {
	const kpis = ref({ activeUsers: 0, objectCount: 0, filesCount: 0, auditEventCount: 0 })
	const activity = ref([])
	const loading = ref(false)
	const error = ref(null)
	const versionNoLongerAccessible = ref(false)

	let debounceTimer = null

	/**
	 * Read the current value out of a ref-or-plain holder.
	 *
	 * @param {*} maybeRef A Vue ref or a plain value.
	 * @return {*} The unwrapped value.
	 */
	function unwrap(maybeRef) {
		return maybeRef && typeof maybeRef === 'object' && 'value' in maybeRef ? maybeRef.value : maybeRef
	}

	/**
	 * Fetch the insights payload for the current refs.
	 *
	 * @return {Promise<void>}
	 */
	async function refresh() {
		const appUuid = unwrap(appUuidRef)
		const versionUuid = unwrap(versionUuidRef)
		const win = unwrap(windowRef) || '7d'

		if (!appUuid || !versionUuid) {
			return
		}

		loading.value = true
		error.value = null
		versionNoLongerAccessible.value = false

		try {
			const url = generateUrl(
				`/apps/openbuilt/api/applications/${encodeURIComponent(appUuid)}/versions/${encodeURIComponent(versionUuid)}/insights`,
			)
			const { data } = await axios.get(url, { params: { window: win } })
			if (data && typeof data === 'object') {
				kpis.value = (data.kpis && typeof data.kpis === 'object')
					? { activeUsers: 0, objectCount: 0, filesCount: 0, auditEventCount: 0, ...data.kpis }
					: { activeUsers: 0, objectCount: 0, filesCount: 0, auditEventCount: 0 }
				activity.value = Array.isArray(data.activity) ? data.activity : []
			} else {
				kpis.value = { activeUsers: 0, objectCount: 0, filesCount: 0, auditEventCount: 0 }
				activity.value = []
			}
		} catch (e) {
			const status = (e && e.response && e.response.status) || 0
			if (status === 404) {
				versionNoLongerAccessible.value = true
				kpis.value = { activeUsers: 0, objectCount: 0, filesCount: 0, auditEventCount: 0 }
				activity.value = []
			} else {
				error.value = e instanceof Error ? e : new Error(String(e))
				kpis.value = { activeUsers: 0, objectCount: 0, filesCount: 0, auditEventCount: 0 }
				activity.value = []
			}
		} finally {
			loading.value = false
		}
	}

	/**
	 * Debounced wrapper around `refresh()` — collapses rapid back-to-back
	 * watcher fires (e.g. simultaneous version + window toggle) into one
	 * HTTP request.
	 *
	 * @return {void}
	 */
	function debouncedRefresh() {
		if (debounceTimer) {
			clearTimeout(debounceTimer)
		}
		debounceTimer = setTimeout(() => {
			refresh()
		}, 200)
	}

	// Only attach watchers when the inputs are reactive refs.
	const sources = []
	if (appUuidRef && typeof appUuidRef === 'object' && 'value' in appUuidRef) {
		sources.push(appUuidRef)
	}
	if (versionUuidRef && typeof versionUuidRef === 'object' && 'value' in versionUuidRef) {
		sources.push(versionUuidRef)
	}
	if (windowRef && typeof windowRef === 'object' && 'value' in windowRef) {
		sources.push(windowRef)
	}
	if (sources.length > 0) {
		watch(sources, () => {
			debouncedRefresh()
		})
	}

	// Kick off the first fetch on creation.
	refresh()

	return { kpis, activity, loading, error, versionNoLongerAccessible, refresh }
}
