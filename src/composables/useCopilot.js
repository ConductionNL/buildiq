// SPDX-License-Identifier: EUPL-1.2
/**
 * useCopilot — Vue 2.7 composable owning the copilot state machine
 * (`idle -> planning -> review -> executing -> done | error`), a
 * session-cached health probe, and the client-side canonical manifest v2
 * validator gate (design.md Decision 3, layer 4): `canApprove` stays false
 * while any predicted manifest fails `validateManifest`
 * (`@conduction/nextcloud-vue`, ADR-024) — a failed layer means nothing can
 * be applied, matching the server-side layers enforced in CopilotService.
 *
 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
 */
import { ref, computed } from 'vue'
import { validateManifest } from '@conduction/nextcloud-vue'
import { fetchCopilotHealth, requestPlan, executePlan, discardRun } from '../services/copilot.js'

/** @type {{available: boolean, reason?: string}|null} */
let healthCache = null

/**
 * Test helper — clear the module-level health cache.
 *
 * @return {void}
 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
 */
export function clearCopilotHealthCache() {
	healthCache = null
}

/**
 * Validate every predicted manifest in a plan response with the canonical
 * manifest v2 validator.
 *
 * @param {object} manifests - `{ versionKey: { current, predicted } }` from the plan response.
 * @return {Map<string, Array<string>>} versionKey -> validation error list (empty when valid).
 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
 */
function validatePredictedManifests(manifests) {
	const result = new Map()
	if (!manifests || typeof manifests !== 'object') {
		return result
	}
	for (const [versionKey, pair] of Object.entries(manifests)) {
		const predicted = pair && pair.predicted
		if (!predicted) {
			continue
		}
		try {
			const outcome = validateManifest ? validateManifest(predicted) : { valid: true, errors: [] }
			result.set(versionKey, outcome.valid ? [] : (outcome.errors || []))
		} catch (e) {
			result.set(versionKey, [`validator threw: ${e && e.message ? e.message : e}`])
		}
	}
	return result
}

/**
 * Own the copilot plan/review/approve/execute state machine.
 *
 * @return {object} Reactive copilot state + actions.
 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
 */
export function useCopilot() {
	const state = ref('idle')
	const health = ref(healthCache)
	const plan = ref(null)
	const manifestErrors = ref(new Map())
	const errorMessage = ref('')
	const executeResult = ref(null)
	// The brief that produced the current/last plan — carried through to
	// execute()/discard() so an agent-scoped AgentRun record captures the
	// original prompt (spec `agent-workspace`).
	const lastPrompt = ref('')

	const isAvailable = computed(() => !!(health.value && health.value.available))

	/**
	 * `canApprove` is false while the plan is missing, while any predicted
	 * manifest fails the canonical validator, or while not in the review state.
	 *
	 * @return {boolean}
	 */
	const canApprove = computed(() => {
		if (state.value !== 'review' || !plan.value) {
			return false
		}
		for (const errors of manifestErrors.value.values()) {
			if (errors.length > 0) {
				return false
			}
		}
		return true
	})

	/**
	 * Probe (and cache for the session) copilot availability.
	 *
	 * @return {Promise<{available: boolean, reason?: string}>}
	 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
	 */
	async function checkHealth() {
		if (healthCache) {
			health.value = healthCache
			return healthCache
		}
		const result = await fetchCopilotHealth()
		healthCache = result
		health.value = result
		return result
	}

	/**
	 * Request a plan for a brief, moving to the `review` state on success.
	 *
	 * @param {string} brief - natural-language brief.
	 * @param {string} [appSlug] - optional existing target app slug. Ignored
	 *   server-side when `agentId` is given.
	 * @param {string} [agentId] - optional Agent id narrowing the effective tool
	 *   allow-list and prefixing its instructions onto the system prompt
	 *   (spec `agent-workspace`).
	 * @return {Promise<void>}
	 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
	 * @spec openspec/changes/agent-workspace/specs/ai-copilot/spec.md
	 */
	async function generatePlan(brief, appSlug, agentId) {
		state.value = 'planning'
		errorMessage.value = ''
		plan.value = null
		manifestErrors.value = new Map()
		lastPrompt.value = brief
		try {
			const result = await requestPlan({ brief, appSlug, agentId })
			plan.value = result
			manifestErrors.value = validatePredictedManifests(result && result.manifests)
			state.value = 'review'
		} catch (err) {
			errorMessage.value = (err && err.message) || 'Failed to generate a plan.'
			state.value = 'error'
		}
	}

	/**
	 * Approve the reviewed plan — sends the execute request. No-op unless
	 * `canApprove` is true (guards against a stale/invalid plan being applied).
	 *
	 * @param {string} [agentId] - optional Agent id this plan was planned with
	 *   (spec `agent-workspace`) — threaded through so the resulting AgentRun
	 *   record captures the full turn.
	 * @return {Promise<void>}
	 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
	 * @spec openspec/changes/agent-workspace/specs/ai-copilot/spec.md
	 */
	async function approve(agentId) {
		if (!canApprove.value || !plan.value) {
			return
		}
		state.value = 'executing'
		errorMessage.value = ''
		try {
			const result = await executePlan(
				{ summary: plan.value.summary, steps: plan.value.steps },
				{ agentId, prompt: lastPrompt.value },
			)
			executeResult.value = result
			state.value = 'done'
		} catch (err) {
			errorMessage.value = (err && err.message) || 'Failed to execute the plan.'
			state.value = 'error'
		}
	}

	/**
	 * Discard the current proposal — resets to `idle`. Sends no request for
	 * the bare copilot path (`agentId` omitted, unchanged from before this
	 * change); for an agent-scoped chat, best-effort logs the discarded turn
	 * as an AgentRun (spec `agent-workspace` "A discarded proposal is still
	 * logged").
	 *
	 * @param {string} [agentId] - optional Agent id this plan was planned with.
	 * @return {void}
	 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
	 * @spec openspec/changes/agent-workspace/specs/agent-workspace/spec.md
	 */
	function discard(agentId) {
		if (agentId && plan.value) {
			discardRun({ agentId, prompt: lastPrompt.value, plan: { summary: plan.value.summary, steps: plan.value.steps } })
		}
		state.value = 'idle'
		plan.value = null
		manifestErrors.value = new Map()
		errorMessage.value = ''
		executeResult.value = null
	}

	return {
		state,
		health,
		isAvailable,
		plan,
		manifestErrors,
		errorMessage,
		executeResult,
		canApprove,
		checkHealth,
		generatePlan,
		approve,
		discard,
	}
}
