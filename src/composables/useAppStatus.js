// SPDX-License-Identifier: EUPL-1.2
/**
 * useAppStatus — soft capability check: is a sibling Nextcloud app
 * (e.g. `procest`) installed and enabled on this instance?
 *
 * The builder uses this to degrade gracefully (REQ-PWA-006): when the app is
 * absent the attach action / case-type list is disabled with a hint. The
 * runtime gate itself is handled by CnAppRoot via the manifest
 * `dependencies[]`; this is only the design-time soft check.
 *
 * Strategy: not every app advertises a server capability key, so we use a
 * cheap authenticated probe of a known app route and interpret a non-404/501
 * response as "present" (the app answered, even if with 400/401/403). The
 * server-injected `OC.appswebroots` map, when available, is consulted first as
 * a synchronous positive signal. The result is cached per app id for the
 * session.
 *
 * @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-006
 */
import { ref } from 'vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** @type {Map<string, boolean>} */
const statusCache = new Map()

/**
 * Probe whether an app is available. Returns reactive `{ available, checked }`
 * refs that flip once the async probe resolves.
 *
 * @param {string} appId - the app id, e.g. `procest`.
 * @param {object} [opts] - options.
 * @param {string} [opts.probePath] - app route to probe when the webroots map
 *   is silent (default `/apps/{appId}/api`).
 * @param {Function} [opts.client] - axios-like client injection for tests.
 * @return {{ available: import('vue').Ref<boolean>, checked: import('vue').Ref<boolean>, check: Function }}
 * @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-006
 */
export function useAppStatus(appId, opts = {}) {
	const client = opts.client || axios
	const available = ref(false)
	const checked = ref(false)

	/**
	 * Run the webroots + probe check.
	 *
	 * @return {Promise<boolean>}
	 */
	async function check() {
		if (statusCache.has(appId)) {
			available.value = statusCache.get(appId)
			checked.value = true
			return available.value
		}
		// 1. Synchronous positive signal from the server-injected app webroots
		// map (present when the app is installed + enabled).
		try {
			const roots = (typeof OC !== 'undefined' && OC.appswebroots) || {}
			if (roots[appId] !== undefined) {
				available.value = true
				checked.value = true
				statusCache.set(appId, true)
				return true
			}
		} catch {
			// fall through to probe
		}
		// 2. Cheap authenticated probe.
		const path = opts.probePath || `/apps/${appId}/api`
		try {
			await client.get(generateUrl(path))
			available.value = true
		} catch (e) {
			const status = e && e.response && e.response.status
			available.value = !(status === 404 || status === 501 || status === undefined)
		}
		checked.value = true
		statusCache.set(appId, available.value)
		return available.value
	}

	return { available, checked, check }
}

/**
 * Test helper — clear the session status cache.
 *
 * @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-006
 */
export function clearAppStatusCache() {
	statusCache.clear()
}
