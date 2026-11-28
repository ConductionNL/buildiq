/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * Which OpenRegister flows start when a record is created in a target
 * (forms-multi-product-request D5): the writes editor lists them per product and
 * warns when a product starts none. A warning, never a refusal: a target may
 * start its process another way.
 *
 * @spec openspec/changes/archive/2026-11-24-forms-multi-product-request/specs/journey-designer/spec.md#requirement-the-designer-says-whether-a-part-starts-a-process-req-bqmp-004
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** The trigger names of "a record was created". */
const CREATED = Object.freeze(['object.created', 'object-created'])

/**
 * A flow's object trigger: its own `trigger`, or the trigger node of its nodes.
 *
 * @param {object} flow - the flow.
 * @return {object|null} The trigger, or null when the flow has none.
 */
function triggerOf(flow) {
	if (!flow || typeof flow !== 'object') {
		return null
	}
	if (flow.trigger && typeof flow.trigger === 'object') {
		return flow.trigger
	}
	const nodes = Array.isArray(flow.nodes) ? flow.nodes : []
	const node = nodes.find((n) => n && (n.type === 'trigger' || CREATED.includes(n.event) || CREATED.includes(n.type)))
	return node ? { ...(node.config || {}), ...node } : null
}

/**
 * Whether a value names the target's register or schema: the same slug or id,
 * or nothing named (a trigger without a register filters on schema only).
 *
 * @param {string|number|null|undefined} value - the trigger's register or schema.
 * @param {string} wanted - the target's.
 * @return {boolean}
 */
function names(value, wanted) {
	return value === undefined || value === null || value === '' || String(value) === String(wanted)
}

/**
 * The flows that start on creation of a record in the target's register and schema.
 *
 * @param {Array<object>} flows - the flows from OpenRegister.
 * @param {{register?: string, schema?: string}} target - the product's target.
 * @return {Array<object>} The matching flows.
 * @spec openspec/changes/archive/2026-11-24-forms-multi-product-request/specs/journey-designer/spec.md#requirement-the-designer-says-whether-a-part-starts-a-process-req-bqmp-004
 */
export function creationFlowsFor(flows, target) {
	const schema = String((target && target.schema) || '')
	if (schema === '') {
		return []
	}
	return (Array.isArray(flows) ? flows : []).filter((flow) => {
		const trigger = triggerOf(flow)
		if (!trigger || !CREATED.includes(String(trigger.event || trigger.type || ''))) {
			return false
		}
		return trigger.schema !== undefined && names(trigger.schema, schema) && names(trigger.register, String(target.register || ''))
	})
}

/**
 * Read OpenRegister's flows.
 *
 * @param {object} [client] - axios-compatible client (tests inject one).
 * @return {Promise<Array<object>>} The flows.
 * @spec openspec/changes/archive/2026-11-24-forms-multi-product-request/specs/journey-designer/spec.md#requirement-the-designer-says-whether-a-part-starts-a-process-req-bqmp-004
 */
export async function loadFlows(client = axios) {
	const { data } = await client.get(generateUrl('/apps/openregister/api/flows'))
	if (Array.isArray(data)) {
		return data
	}
	return data && Array.isArray(data.results) ? data.results : []
}
