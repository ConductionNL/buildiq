/**
 * The RuleSet record the rule editors write through OpenRegister's object
 * REST API before they store the rule or the decision table it owns.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * @param {{slug: string, name: string, ruleType: string, status: string}} record - the rule set.
 * @return {Promise<object>} the axios response.
 */
export function saveRuleSetRecord(record) {
	return axios.post(
		generateUrl('/apps/openregister/api/objects/buildiq/rule-set'),
		record,
	)
}

/**
 * The OpenRegister object URL of a stored rule set.
 *
 * @param {object} ruleSet - the rule set as the list returned it.
 * @return {string|null} the URL, or null when the rule set carries no id.
 * @spec openspec/changes/rules-evaluation-for-other-apps/specs/rules-evaluation-for-other-apps/spec.md#requirement-a-rule-set-names-the-apps-that-may-call-it-req-bqrx-001
 */
export function ruleSetRecordUrl(ruleSet) {
	const id = ruleSet?.['@self']?.id || ruleSet?.id || ruleSet?.uuid
	if (!id) {
		return null
	}
	return generateUrl('/apps/openregister/api/objects/buildiq/rule-set/{id}', { id })
}

/**
 * Store which apps may call a rule set (REQ-BQRX-001): a de-duplicated list of
 * app ids, trimmed, empty ones left out.
 *
 * @param {object} ruleSet - the stored rule set.
 * @param {Array<string>} appIds - the app ids.
 * @return {Promise<object>} the axios response.
 * @spec openspec/changes/rules-evaluation-for-other-apps/specs/rules-evaluation-for-other-apps/spec.md#requirement-a-rule-set-names-the-apps-that-may-call-it-req-bqrx-001
 */
export function saveRuleSetCallers(ruleSet, appIds) {
	const url = ruleSetRecordUrl(ruleSet)
	if (url === null) {
		return Promise.reject(new Error('The rule set has no id.'))
	}
	const callableBy = [...new Set(appIds.map((id) => String(id).trim()).filter((id) => id !== ''))]
	return axios.patch(url, { callableBy })
}
