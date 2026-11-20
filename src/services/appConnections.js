// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
/**
 * appConnections — the connections of one app built in buildiq
 * (built-app-connections-page, REQ-BIQ-CONN-005): its integriq sources and
 * integrations with their health, read from
 * `GET /api/applications/{slug}/connections`.
 *
 * The seven statuses read as integriq's own labels (the library's
 * `connectionStatus` formatter uses the same words), and a row integriq never
 * checked reads "Not checked".
 *
 * @spec openspec/changes/archive/2026-11-20-built-app-connections-page/specs/app-connections/spec.md#requirement-req-biq-conn-005-every-app-built-in-buildiq-has-a-connections-page-with-health
 */

import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { resolveFleetAppId } from './fleetAppId.js'

/**
 * The label of a connection status; `null` or an unknown status is not checked.
 *
 * Translated on each call, so the label follows the catalogue once it loads.
 *
 * @param {?string} status The status integriq recorded.
 * @return {string} The label.
 * @spec openspec/changes/archive/2026-11-20-built-app-connections-page/specs/app-connections/spec.md#requirement-req-biq-conn-005-every-app-built-in-buildiq-has-a-connections-page-with-health
 */
export function connectionStatusLabel(status) {
	switch (status) {
	case 'configured': return t('buildiq', 'Configured')
	case 'limited': return t('buildiq', 'Limited')
	case 'unconfigured': return t('buildiq', 'Not configured')
	case 'simulated': return t('buildiq', 'Simulated')
	case 'disabled': return t('buildiq', 'Switched off')
	case 'unavailable': return t('buildiq', 'Not available')
	case 'error': return t('buildiq', 'Error')
	default: return t('buildiq', 'Not checked')
	}
}

/**
 * The integriq page a row links to: the source, or the app's connections.
 *
 * @param {{kind: string, linkId: ?string}} row The connection row.
 * @param {string} appSlug The app's slug.
 * @return {string} The URL, or '' when the row has nothing to open.
 * @spec openspec/changes/archive/2026-11-20-built-app-connections-page/specs/app-connections/spec.md#requirement-req-biq-conn-005-every-app-built-in-buildiq-has-a-connections-page-with-health
 */
export function connectionLink(row, appSlug) {
	if (!row || !row.linkId) {
		return ''
	}
	const app = resolveFleetAppId('integriq')
	if (row.kind === 'source') {
		return generateUrl(`/apps/${app}/sources/${encodeURIComponent(row.linkId)}`)
	}
	return generateUrl(`/apps/${app}/connections?app=${encodeURIComponent(appSlug)}`)
}

/**
 * The app's connections.
 *
 * @param {string} appSlug The app's slug.
 * @return {Promise<{healthNeedsIntegriq: boolean, connections: Array<object>}>} The list.
 * @spec openspec/changes/archive/2026-11-20-built-app-connections-page/specs/app-connections/spec.md#requirement-req-biq-conn-005-every-app-built-in-buildiq-has-a-connections-page-with-health
 */
export async function fetchAppConnections(appSlug) {
	const { data } = await axios.get(generateUrl(`/apps/buildiq/api/applications/${encodeURIComponent(appSlug)}/connections`))
	return {
		healthNeedsIntegriq: Boolean(data && data.healthNeedsIntegriq),
		connections: Array.isArray(data && data.connections) ? data.connections : [],
	}
}
