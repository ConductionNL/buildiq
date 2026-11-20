// SPDX-License-Identifier: EUPL-1.2
/**
 * automationStepSources — the picker options of the automation composer's
 * flow steps (logic-automation-actions-that-run D5, D7).
 *
 *  - A webhook step names an integriq source and a path instead of a URL.
 *    The sources live as OpenRegister objects in the connector app's own
 *    register, resolved by app id as `ConnectorSourcePicker` resolves it. Only
 *    a source's reference and name leave this module, never its credentials.
 *  - A "Decide with a rule set" step names a decision table rule set; a
 *    condition-action rule set cannot decide, so it is not offered.
 *
 * @spec openspec/changes/logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-a-webhook-step-calls-a-configured-source-req-bqar-003
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { resolveFleetAppId } from './fleetAppId.js'

/**
 * The rows of a list response: its `results`, or the body when that is a list.
 *
 * @param {object} data The response body.
 * @return {Array<object>} The rows.
 */
function rowsOf(data) {
	if (Array.isArray(data && data.results)) {
		return data.results
	}
	return Array.isArray(data) ? data : []
}

/**
 * The reference a step stores for an object: its slug, else its uuid or id.
 * Integriq's source-call node resolves a source by uuid, slug or reference.
 *
 * @param {object} row An OpenRegister object.
 * @return {string} The reference, or ''.
 */
function referenceOf(row) {
	const self = (row && row['@self']) || {}
	return String((row && (row.slug || row.uuid || row.id)) || self.id || '')
}

/**
 * The integriq sources a webhook step can call, as picker options.
 *
 * @return {Promise<Array<{id: string, label: string}>>} The options.
 *
 * @spec openspec/changes/logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-a-webhook-step-calls-a-configured-source-req-bqar-003
 */
export async function fetchIntegriqSources() {
	const register = resolveFleetAppId('integriq')
	const { data } = await axios.get(
		generateUrl(`/apps/openregister/api/objects/${register}/source`),
		{ params: { _limit: 500 } },
	)

	return rowsOf(data)
		.map((row) => ({ id: referenceOf(row), label: String(row.name || row.title || referenceOf(row)) }))
		.filter((option) => option.id !== '')
}

/**
 * The decision table rule sets a "Decide with a rule set" step can use.
 *
 * @return {Promise<Array<{id: string, label: string}>>} The options.
 *
 * @spec openspec/changes/logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-a-step-decides-with-a-rule-set-req-bqar-005
 */
export async function fetchDecisionRuleSets() {
	const { data } = await axios.get(
		generateUrl('/apps/openregister/api/objects/buildiq/rule-set'),
		{ params: { _limit: 500 } },
	)

	return rowsOf(data)
		.filter((row) => row && row.ruleType === 'decision-table' && typeof row.slug === 'string' && row.slug !== '')
		.map((row) => ({ id: row.slug, label: String(row.name || row.slug) }))
}
