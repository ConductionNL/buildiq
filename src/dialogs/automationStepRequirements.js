// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * The fields a flow step cannot be saved without, and the refusal the
 * composer shows for each. The compiler refuses the same steps on the server.
 *
 * - A webhook needs a source (logic-automation-actions-that-run D5).
 * - A rule decision needs a rule set and the field its decision is written to (D7).
 * - "Run another automation" needs the automation it runs (logic-shared-flows-and-automations D4).
 *
 * @spec openspec/changes/logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-a-webhook-step-calls-a-configured-source-req-bqar-003
 * @spec openspec/changes/logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-an-automation-can-call-another-req-bqsf-004
 */

const REQUIREMENTS = Object.freeze({
	webhook: {
		fields: ['source'],
		message: () => t('buildiq', 'Pick a source for this webhook.'),
	},
	'rule-decision': {
		fields: ['ruleSetSlug', 'outputField'],
		message: () => t('buildiq', 'Pick a rule set and the field its decision is written to.'),
	},
	'run-automation': {
		fields: ['automation'],
		message: () => t('buildiq', 'Pick the automation this step runs.'),
	},
})

/**
 * The refusal of a step that lacks a field it needs, or '' when it has them all.
 *
 * @param {object} action The working action.
 * @return {string} The refusal.
 */
export function missingStepField(action) {
	const requirement = action && Object.hasOwn(REQUIREMENTS, action.type) ? REQUIREMENTS[action.type] : null
	if (requirement === null) {
		return ''
	}
	const missing = requirement.fields.some((field) => String(action[field] || '').trim() === '')
	return missing ? requirement.message() : ''
}
