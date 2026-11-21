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
	for (const automation of Array.isArray(automations) ? automations : []) {
		const id = automationId(automation)
		if (id === '' || id === ownId) {
			continue
		}
		const name = String(automation.name || automation.slug || id)
		if (applicationSlug !== '' && automation.applicationSlug === applicationSlug) {
			own.push({ id, label: name, shared: false })
		} else if (automation.shared === true) {
			shared.push({ id, label: `${name} (${automation.applicationSlug || ''})`, shared: true })
		}
	}
	const byLabel = (a, b) => a.label.localeCompare(b.label)
	return [...own.sort(byLabel), ...shared.sort(byLabel)]
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
	for (const automation of Array.isArray(automations) ? automations : []) {
		const value = compiledFlowOf(automation)
		if (automation.shared !== true || value === '' || automation.applicationSlug === applicationSlug) {
			continue
		}
		const name = String(automation.name || automation.slug || value)
		options.push({ label: `${name} (${automation.applicationSlug || ''})`, value, shared: true })
	}
	return options.sort((a, b) => a.label.localeCompare(b.label))
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
	const plain = Array.isArray(flowOptions) ? flowOptions : []
	const shared = Array.isArray(sharedOptions) ? sharedOptions : []
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
 */
export function isPickable(option) {
	return !(option && option.heading === true)
}

/**
 * Read the automations the pickers choose from.
 *
 * @return {Promise<Array<object>>} The automation records.
 */
export async function fetchAutomations() {
	const { data } = await axios.get(
		generateUrl('/apps/openregister/api/objects/buildiq/automation'),
		{ params: { _limit: LIMIT } },
	)
	return listResults(data)
}
