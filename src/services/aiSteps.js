// SPDX-License-Identifier: EUPL-1.2
/**
 * aiSteps — the "Ask AI" step and the AI field (ai-llm-steps-and-computed-fields).
 *
 *  - The step and the field run Hermiq's `hermiq.agent-step` in an
 *    OpenRegister flow, so both are offered only when OpenRegister's node
 *    catalogue lists that node (D6).
 *  - A step names a Hermiq agent. Only agents without tools are offered: this
 *    step answers, it does not act (design Risks).
 *  - Only the source fields the maker named are sent to the model (D3); the
 *    composer shows them before saving.
 *
 * @spec openspec/changes/ai-llm-steps-and-computed-fields/specs/automation-ai-steps/spec.md#requirement-the-composer-offers-an-ask-ai-step-req-bqls-001
 */

import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { resolveFleetAppId } from './fleetAppId.js'

/** The node Hermiq contributes to OpenRegister's flow engine. */
export const AI_AGENT_NODE = 'hermiq.agent-step'

/** The presets a composer step runs (D1). */
export const AI_PRESETS = Object.freeze(['classify', 'summarise', 'extract', 'translate', 'prompt'])

/** The labels an AI sentiment field picks from (D5). */
export const SENTIMENT_LABELS = Object.freeze(['positive', 'neutral', 'negative'])

/** The fixed instruction of an AI clean field (D5). */
export const CLEAN_INSTRUCTION = 'Correct the spelling and layout of the text below and keep its meaning. Answer with the cleaned text only.'

/**
 * The reason the AI step and the AI field are disabled without the node.
 *
 * @return {string} The reason.
 * @spec openspec/changes/ai-llm-steps-and-computed-fields/specs/automation-ai-steps/spec.md#requirement-ai-steps-need-the-hermiq-node-req-bqls-005
 */
export function missingNodeReason() {
	return t('buildiq', 'Install Hermiq to use AI steps.')
}

/**
 * The preset options of the composer step.
 *
 * @return {Array<{value: string, label: string}>} The options.
 * @spec openspec/changes/ai-llm-steps-and-computed-fields/specs/automation-ai-steps/spec.md#requirement-the-composer-offers-an-ask-ai-step-req-bqls-001
 */
export function aiPresetOptions() {
	return [
		{ value: 'classify', label: t('buildiq', 'Classify') },
		{ value: 'summarise', label: t('buildiq', 'Summarise') },
		{ value: 'extract', label: t('buildiq', 'Extract fields') },
		{ value: 'translate', label: t('buildiq', 'Translate') },
		{ value: 'prompt', label: t('buildiq', 'Own instruction') },
	]
}

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
 * Whether OpenRegister's node catalogue lists Hermiq's agent node. A failed
 * read answers false: the step stays disabled rather than failing at apply.
 *
 * @return {Promise<boolean>} Whether the node is there.
 * @spec openspec/changes/ai-llm-steps-and-computed-fields/specs/automation-ai-steps/spec.md#requirement-ai-steps-need-the-hermiq-node-req-bqls-005
 */
export async function fetchAgentNodeAvailable() {
	try {
		const { data } = await axios.get(generateUrl('/apps/openregister/api/flow/node-catalog'))
		return rowsOf(data).some((node) => node && (node.id === AI_AGENT_NODE || node.type === AI_AGENT_NODE))
	} catch {
		return false
	}
}

/**
 * The Hermiq agents an AI step can name: active agents without tools.
 *
 * @return {Promise<Array<{id: string, label: string}>>} The options.
 * @spec openspec/changes/ai-llm-steps-and-computed-fields/specs/automation-ai-steps/spec.md#requirement-the-composer-offers-an-ask-ai-step-req-bqls-001
 */
export async function fetchToolFreeAgents() {
	const register = resolveFleetAppId('hermiq')
	const { data } = await axios.get(
		generateUrl(`/apps/openregister/api/objects/${register}/agent`),
		{ params: { _limit: 500 } },
	)

	return rowsOf(data)
		.filter((row) => row && row.active !== false && !(Array.isArray(row.tools) && row.tools.length > 0))
		.map((row) => {
			const self = row['@self'] || {}
			const id = String(row.uuid || row.id || self.id || '')
			return { id, label: String(row.name || id) }
		})
		.filter((option) => option.id !== '')
}

/**
 * The trimmed, non-empty, distinct strings of a list.
 *
 * @param {Array} values The values.
 * @return {Array<string>} The names.
 */
export function namesOf(values) {
	if (!Array.isArray(values)) {
		return []
	}
	const names = []
	for (const value of values) {
		const name = String(value ?? '').trim()
		if (name !== '' && !names.includes(name)) {
			names.push(name)
		}
	}
	return names
}

/**
 * The summary the composer shows for a step: which fields it sends (D3).
 *
 * @param {object} action The editor or stored AI step.
 * @return {string} The summary, '' without source fields.
 * @spec openspec/changes/ai-llm-steps-and-computed-fields/specs/automation-ai-steps/spec.md#requirement-the-composer-offers-an-ask-ai-step-req-bqls-001
 */
export function aiStepSummary(action) {
	const fields = namesOf(action && action.sourceFields)
	if (fields.length === 0) {
		return ''
	}
	return t('buildiq', 'Sends {fields} to the AI', { fields: fields.join(', ') })
}

/**
 * The first thing an AI step still needs, as the message the maker sees, or ''.
 *
 * @param {object} action The editor AI step.
 * @return {string} The message.
 * @spec openspec/changes/ai-llm-steps-and-computed-fields/specs/automation-ai-steps/spec.md#requirement-the-composer-offers-an-ask-ai-step-req-bqls-001
 */
export function aiStepIncomplete(action) {
	const preset = action && action.preset
	if (!AI_PRESETS.includes(preset)) {
		return t('buildiq', 'Pick what the AI step does.')
	}
	if (String(action.agent || '').trim() === '') {
		return t('buildiq', 'Pick the Hermiq agent that runs the AI step.')
	}
	const sources = namesOf(action.sourceFields)
	if (sources.length === 0) {
		return t('buildiq', 'Pick at least one field the AI step reads.')
	}
	if (preset === 'extract') {
		return namesOf(action.fields).length === 0 ? t('buildiq', 'Pick the fields the AI step extracts.') : ''
	}
	const target = String(action.targetField || '').trim()
	if (target === '') {
		return t('buildiq', 'Pick the field the AI answer is written to.')
	}
	if (sources.includes(target)) {
		return t('buildiq', 'The AI step reads {field}, so it cannot also write its answer there.', { field: target })
	}
	return presetIncomplete(action, preset)
}

/**
 * What the chosen preset itself still needs, or ''.
 *
 * @param {object} action The editor AI step.
 * @param {string} preset The preset.
 * @return {string} The message.
 */
function presetIncomplete(action, preset) {
	if (preset === 'classify' && namesOf(action.labels).length === 0) {
		return t('buildiq', 'Add the labels the AI step picks from.')
	}
	if (preset === 'translate' && String(action.language || '').trim() === '') {
		return t('buildiq', 'Pick the language the AI step translates into.')
	}
	if (preset === 'prompt' && String(action.prompt || '').trim() === '') {
		return t('buildiq', 'Write the instruction the AI step follows.')
	}
	return ''
}

/**
 * The editor shape of a stored AI step.
 *
 * @param {object} action The stored action.
 * @return {object} The AI keys of the editor row.
 * @spec openspec/changes/ai-llm-steps-and-computed-fields/specs/automation-ai-steps/spec.md#requirement-the-composer-offers-an-ask-ai-step-req-bqls-001
 */
export function aiStepToEditor(action) {
	return {
		preset: AI_PRESETS.includes(action.preset) ? action.preset : 'classify',
		agent: action.agent || '',
		sourceFields: namesOf(action.sourceFields),
		targetField: action.targetField || '',
		labels: namesOf(action.labels),
		fields: namesOf(action.fields),
		language: action.language || '',
		prompt: action.prompt || '',
	}
}

/**
 * The stored AI step of an editor row: only the keys its preset reads.
 *
 * @param {object} action The editor row.
 * @return {object} The stored action.
 * @spec openspec/changes/ai-llm-steps-and-computed-fields/specs/automation-ai-steps/spec.md#requirement-the-composer-offers-an-ask-ai-step-req-bqls-001
 */
export function toPersistedAiStep(action) {
	const stored = {
		type: 'ai-step',
		preset: action.preset,
		agent: String(action.agent || '').trim(),
		sourceFields: namesOf(action.sourceFields),
	}
	if (action.preset === 'extract') {
		stored.fields = namesOf(action.fields)
		return stored
	}
	stored.targetField = String(action.targetField || '').trim()
	if (action.preset === 'classify') {
		stored.labels = namesOf(action.labels)
	} else if (action.preset === 'translate') {
		stored.language = String(action.language || '').trim()
	} else if (action.preset === 'prompt') {
		stored.prompt = String(action.prompt || '').trim()
	}
	return stored
}
