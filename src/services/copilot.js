// SPDX-License-Identifier: EUPL-1.2
/**
 * copilot — thin fetch wrapper around the three AI-copilot endpoints
 * (spec `ai-copilot`, REQ-OBAIC-001/002/004). No state lives here — the
 * `useCopilot` composable owns the state machine; this module only talks
 * to the network and normalises every failure into `{error, message}`.
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Normalise an axios rejection into `{status, error, message}`.
 *
 * @param {Error} err - the axios error.
 * @return {{status: number, error: string, message: string}}
 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
 */
function normaliseError(err) {
	const response = err && err.response
	const data = (response && response.data) || {}
	return {
		status: response ? response.status : 0,
		error: data.error || 'network_error',
		message: data.message || err.message || 'Request failed.',
	}
}

/**
 * GET /api/copilot/health — probe AI copilot availability.
 *
 * @return {Promise<{available: boolean, reason?: string}>} Never rejects —
 *   a network/503 failure resolves to `{available: false, reason}`.
 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
 */
export async function fetchCopilotHealth() {
	try {
		await axios.get(generateUrl('/apps/openbuild/api/copilot/health'))
		return { available: true }
	} catch (err) {
		// The health envelope carries its machine-readable code under `reason`
		// (`unsupported_server` | `no_provider`), not `error` like the plan/
		// execute envelopes — read it directly rather than via normaliseError().
		const data = (err && err.response && err.response.data) || {}
		return { available: false, reason: data.reason || 'unsupported_server' }
	}
}

/**
 * POST /api/copilot/plan — turn a brief into a validated, reviewable plan.
 *
 * @param {object} params - request params.
 * @param {string} params.brief - natural-language brief (1-2000 chars).
 * @param {string} [params.appSlug] - optional existing target app slug.
 * @return {Promise<{summary: string, steps: Array, manifests: object}>}
 * @throws {{status: number, error: string, message: string}} Normalised error envelope.
 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
 */
export async function requestPlan({ brief, appSlug } = {}) {
	try {
		const url = generateUrl('/apps/openbuild/api/copilot/plan')
		const { data } = await axios.post(url, { brief, appSlug })
		return data
	} catch (err) {
		throw normaliseError(err)
	}
}

/**
 * POST /api/copilot/execute — execute a reviewed plan atomically.
 *
 * @param {{summary: string, steps: Array}} plan - the reviewed plan, echoed back verbatim.
 * @return {Promise<{results: Array}>}
 * @throws {{status: number, error: string, message: string}} Normalised error envelope.
 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
 */
export async function executePlan(plan) {
	try {
		const url = generateUrl('/apps/openbuild/api/copilot/execute')
		const { data } = await axios.post(url, plan)
		return data
	} catch (err) {
		throw normaliseError(err)
	}
}
