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
		await axios.get(generateUrl('/apps/buildiq/api/copilot/health'))
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
 * @param {string} [params.appSlug] - optional existing target app slug. Ignored
 *   server-side (overridden by the resolved agent's applicationSlug) when
 *   `agentId` is given.
 * @param {string} [params.agentId] - optional Agent id narrowing the effective
 *   tool allow-list and prefixing its instructions onto the system prompt
 *   (spec `agent-workspace`).
 * @return {Promise<{summary: string, steps: Array, manifests: object}>}
 * @throws {{status: number, error: string, message: string}} Normalised error envelope.
 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
 * @spec openspec/changes/archive/2026-07-24-agent-workspace/specs/ai-copilot/spec.md
 */
export async function requestPlan({ brief, appSlug, agentId } = {}) {
	try {
		const url = generateUrl('/apps/buildiq/api/copilot/plan')
		const { data } = await axios.post(url, { brief, appSlug, agentId })
		return data
	} catch (err) {
		throw normaliseError(err)
	}
}

/**
 * POST /api/copilot/execute — execute a reviewed plan atomically.
 *
 * @param {{summary: string, steps: Array}} plan - the reviewed plan, echoed back verbatim.
 * @param {object} [options] - agent-scoping options.
 * @param {string} [options.agentId] - optional Agent id this plan was planned with.
 * @param {string} [options.prompt] - the original brief for this turn, needed to write
 *   a complete AgentRun record. Ignored server-side when `agentId` is omitted.
 * @return {Promise<{results: Array}>}
 * @throws {{status: number, error: string, message: string}} Normalised error envelope.
 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
 * @spec openspec/changes/archive/2026-07-24-agent-workspace/specs/ai-copilot/spec.md
 */
export async function executePlan(plan, { agentId, prompt } = {}) {
	try {
		const url = generateUrl('/apps/buildiq/api/copilot/execute')
		const { data } = await axios.post(url, { ...plan, agentId, prompt })
		return data
	} catch (err) {
		throw normaliseError(err)
	}
}

/**
 * POST /api/copilot/discard — log a discarded agent-chat proposal.
 *
 * Only ever called for the agent-scoped chat surface — the bare copilot
 * panel discards without sending any request (unchanged from before this
 * change).
 *
 * @param {object} params - request params.
 * @param {string} params.agentId - the Agent id this turn belongs to.
 * @param {string} params.prompt - the user's brief for this turn.
 * @param {{summary: string, steps: Array}} params.plan - the reviewed-then-discarded plan.
 * @return {Promise<void>} Resolves even on failure — a lost audit-log write must
 *   never block the user's discard action.
 * @spec openspec/changes/archive/2026-07-24-agent-workspace/specs/agent-workspace/spec.md
 */
export async function discardRun({ agentId, prompt, plan } = {}) {
	try {
		const url = generateUrl('/apps/buildiq/api/copilot/discard')
		await axios.post(url, { agentId, prompt, ...plan })
	} catch (err) {
		// Best-effort: a failed audit-log write must not surface as a user-facing error.
	}
}
