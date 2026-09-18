// SPDX-License-Identifier: EUPL-1.2
/**
 * connectorSynchronizations — load the connector app's synchronizations for a
 * picker.
 *
 * The synchronizations live as OpenRegister objects in the connector app's own
 * register, and that register is named after the app: `integriq` since the
 * rename, `openconnector` on an instance still on the older release. So the
 * register segment is resolved the way `ConnectorSourcePicker` resolves it,
 * never written as a literal.
 *
 * Two things silently returned nothing before this module existed, and neither
 * raised an error anywhere:
 *
 *  - the URL named the `openconnector` register, which answers
 *    `404 Register not found` on a renamed instance, and every caller reads a
 *    failed request as "no synchronizations configured";
 *  - the page size was sent as `limit`, which OpenRegister's objects endpoint
 *    reads as a FILTER on a property called `limit`, matching no object at
 *    all. The paging key is `_limit`.
 *
 * @spec exclude Shared loader behind the run-synchronization pickers; the
 *  requirements belong to its callers (AutomationEditDialog,
 *  ScheduleEditDialog), which are covered by their own specs.
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { resolveFleetAppId } from './fleetAppId.js'

/**
 * The objects URL for the connector app's synchronizations on this instance.
 *
 * @return {string} the generated URL.
 */
export function connectorSynchronizationsUrl() {
	const register = resolveFleetAppId('integriq')
	return generateUrl(`/apps/openregister/api/objects/${register}/synchronization`)
}

/**
 * Every synchronization the connector app has, as picker options.
 *
 * @return {Promise<Array<{id: string, label: string}>>} the options, newest
 *  page first; an empty array when the connector app answers nothing.
 */
export async function fetchConnectorSynchronizations() {
	const { data } = await axios.get(connectorSynchronizationsUrl(), {
		params: { _limit: 500 },
	})
	const list = Array.isArray(data && data.results)
		? data.results
		: Array.isArray(data)
			? data
			: []

	return list
		.map((sync) => ({
			id: String(sync.id || sync.uuid || ''),
			label: sync.name || sync.title || sync.id,
		}))
		.filter((option) => option.id && option.id !== 'undefined')
}
