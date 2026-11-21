// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * sharedAutomations — the automations another automation or another app may
 * reuse (logic-shared-flows-and-automations D3, D4).
 *
 * - The composer's "Run another automation" step names one of the same app,
 *   or a shared one of another app, never itself.
 * - The app's flow picker lists the compiled flow of each shared automation of
 *   another app under "Shared automations", named with its source app.
 *
 * The server refuses the same cases at compile; these lists only keep the
 * pickers from offering them.
 *
 * @spec openspec/changes/logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-an-automation-can-be-shared-with-other-apps-req-bqsf-003
 * @spec openspec/changes/logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-an-automation-can-call-another-req-bqsf-004
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { listResults } from '../utils/listResults.js'

/** The action kind that runs another automation. */
export const RUN_AUTOMATION = 'run-automation'

/** The most automations read for the pickers. */
const LIMIT = 500

/**
 * The id an automation record is known by.
 *
 * @param {object} automation The automation record.
 * @return {string} Its uuid, or ''.
 * @spec openspec/changes/logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-an-automation-can-call-another-req-bqsf-004
 */
export function automationId(automation) {
	if (!automation || typeof automation !== 'object') {
		return ''
	}
	const self = automation['@self'] && typeof automation['@self'] === 'object' ? automation['@self'] : {}
	return String(automation.id || automation.uuid || self.id || self.uuid || '')
}

/**
 * The compiled flow of an automation, or ''.
 *
 * @param {object} automation The automation record.
 * @return {string} The flow uuid.
 * @spec openspec/changes/logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-an-automation-can-be-shared-with-other-apps-req-bqsf-003
 */
export function compiledFlowOf(automation) {
	const provenance = automation && automation.provenance
	return provenance && typeof provenance === 'object' ? String(provenance.flowUuid || '') : ''
}

/**
 * The automations a "Run another automation" step may name, as picker options:
 * the app's own first, then the shared ones of other apps with their app name.
 *
 * @param {Array<object>} automations Every automation record read.
 * @param {{applicationSlug: string, ownId: string}} scope The app and the automation being edited.
 * @return {Array<{id: string, label: string, shared: boolean}>} Options.
 * @spec openspec/changes/logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-an-automation-can-call-another-req-bqsf-004
 */
export function runAutomationOptions(automations, { applicationSlug = '', ownId = '' } = {}) {
	const own = []
	const shared = []
	for (const automation of listOf(automations)) {
		const option = runAutomationOption(automation, applicationSlug, ownId)
		if (option !== null) {
			(option.shared ? shared : own).push(option)
		}
	}
	return [...own.sort(byLabel), ...shared.sort(byLabel)]
}

/**
 * One automation as a "Run another automation" option, or null when the step may not name it.
 *
 * @param {object} automation The automation record.
 * @param {string} applicationSlug The app of the automation being edited.
 * @param {string} ownId The automation being edited.
 * @return {{id: string, label: string, shared: boolean}|null} The option.
 */
function runAutomationOption(automation, applicationSlug, ownId) {
	const id = automationId(automation)
	if (id === '' || id === ownId) {
		return null
	}
	if (applicationSlug !== '' && automation.applicationSlug === applicationSlug) {
		return { id, label: nameOf(automation, id), shared: false }
	}
	if (automation.shared === true) {
		return { id, label: `${nameOf(automation, id)} (${automation.applicationSlug || ''})`, shared: true }
	}
	return null
}

/**
 * The name an automation is shown by.
 *
 * @param {object} automation The automation record.
 * @param {string} fallback What to show without a name or slug.
 * @return {string} The name.
 */
function nameOf(automation, fallback) {
	return String(automation.name || automation.slug || fallback)
}

/**
 * A list, or [] for anything else.
 *
 * @param {*} value The value.
 * @return {Array} The list.
 */
function listOf(value) {
	return Array.isArray(value) ? value : []
}

/**
 * Sort options by label.
 *
 * @param {{label: string}} a One option.
 * @param {{label: string}} b Another.
 * @return {number} The order.
 */
function byLabel(a, b) {
	return a.label.localeCompare(b.label)
}

/**
 * The compiled flows of the shared automations of other apps, as flow picker options.
 *
 * @param {Array<object>} automations Every automation record read.
 * @param {string} applicationSlug The app whose picker this is.
 * @return {Array<{label: string, value: string, shared: boolean}>} Options.
 * @spec openspec/changes/logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-an-automation-can-be-shared-with-other-apps-req-bqsf-003
 */
export function sharedFlowOptions(automations, applicationSlug) {
	const options = []
	for (const automation of listOf(automations)) {
		const value = compiledFlowOf(automation)
		if (automation.shared === true && value !== '' && automation.applicationSlug !== applicationSlug) {
			options.push({ label: `${nameOf(automation, value)} (${automation.applicationSlug || ''})`, value, shared: true })
		}
	}
	return options.sort(byLabel)
}

/**
 * The flow picker's options with the shared automations grouped after the
 * plain flows, under a heading that cannot be picked. A plain flow that is a
 * shared automation's own flow is listed once, in the group.
 *
 * @param {Array<{label: string, value: string}>} flowOptions The plain flows.
 * @param {Array<{label: string, value: string}>} sharedOptions The shared automations' flows.
 * @param {string} heading The group heading, translated.
 * @return {Array<object>} Options.
 * @spec openspec/changes/logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-an-automation-can-be-shared-with-other-apps-req-bqsf-003
 */
export function withSharedGroup(flowOptions, sharedOptions, heading) {
	const plain = listOf(flowOptions)
	const shared = listOf(sharedOptions)
	if (shared.length === 0) {
		return plain
	}
	const sharedValues = new Set(shared.map((option) => option.value))
	return [
		...plain.filter((option) => !sharedValues.has(option.value)),
		{ label: heading, value: '', heading: true },
		...shared,
	]
}

/**
 * Whether a picker option may be picked: a group heading may not.
 *
 * @param {object} option The option.
 * @return {boolean}
 * @spec openspec/changes/logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-an-automation-can-be-shared-with-other-apps-req-bqsf-003
 */
export function isPickable(option) {
	return !(option && option.heading === true)
}

/**
 * Read the automations the pickers choose from.
 *
 * @return {Promise<Array<object>>} The automation records.
 * @spec openspec/changes/logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-an-automation-can-be-shared-with-other-apps-req-bqsf-003
 * @spec openspec/changes/logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-an-automation-can-call-another-req-bqsf-004
 */
export async function fetchAutomations() {
	const { data } = await axios.get(
		generateUrl('/apps/openregister/api/objects/buildiq/automation'),
		{ params: { _limit: LIMIT } },
	)
	return listResults(data)
}

/**
 * The compiled flows of other apps' shared automations, for an app's flow
 * picker; none when the automations cannot be read, so the flows still load.
 *
 * @param {string} applicationSlug The app whose picker this is.
 * @return {Promise<Array<{label: string, value: string, shared: boolean}>>} Options.
 * @spec openspec/changes/logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-an-automation-can-be-shared-with-other-apps-req-bqsf-003
 */
export async function loadSharedFlowOptions(applicationSlug) {
	try {
		return sharedFlowOptions(await fetchAutomations(), applicationSlug)
	} catch {
		return []
	}
}
