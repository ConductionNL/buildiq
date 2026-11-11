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
