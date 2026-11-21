// SPDX-License-Identifier: EUPL-1.2
/**
 * automationPersistedActions — turns one working action of the automation
 * editor into the shape persisted in the automation's `actions[]`.
 *
 * OpenRegister rejects BOTH an empty object ({}) and null for a nested
 * (array-item) object property, so an object-op without a field mapping and a
 * webhook without a payload template OMIT the key entirely rather than send
 * {}/null.
 *
 * @spec openspec/changes/archive/2026-07-23-automation-approval-steps/tasks.md#3.1
 */

import { toPersistedAiStep } from '../services/aiSteps.js'
import { toPersistedScriptStep } from '../services/scriptSteps.js'
import { storedForm } from './approvalFormFields.js'

/**
 * Parse a JSON text into an object with at least one key, or null.
 *
 * Empty, invalid or non-object JSON, and an empty object, all give null.
 *
 * @param {string} text The JSON text the editor holds.
 * @return {object|null} The parsed object, or null.
 */
function parseNonEmptyObject(text) {
	try {
		const parsed = JSON.parse(text || 'null')
		return isNonEmptyObject(parsed) ? parsed : null
	} catch {
		return null
	}
}

/**
 * Whether a parsed JSON value is an object (or array) with at least one key.
 *
 * @param {unknown} value The parsed value.
 * @return {boolean} True when it carries keys.
 */
function isNonEmptyObject(value) {
	return Boolean(value) && typeof value === 'object' && Object.keys(value).length > 0
}

/**
 * The value when it is an array, otherwise an empty list.
 *
 * @param {unknown} value The candidate list.
 * @return {Array} The list.
 */
function listOrEmpty(value) {
	return Array.isArray(value) ? value : []
}

/**
 * Assign an optional object property only when it carries keys.
 *
 * @param {object} target The persisted action.
 * @param {object} property The property name and the JSON text it is parsed from.
 * @return {object} The target.
 */
function withOptionalObject(target, property) {
	const value = parseNonEmptyObject(property.text)
	if (value !== null) {
		target[property.key] = value
	}
	return target
}

/**
 * An approval action with its `form` when it asks the approver for fields;
 * without fields no `form` is stored (logic-approval-task-form, design D1).
 *
 * @param {object} target - the persisted approval action.
 * @param {Array|undefined} fields - the editor's field rows.
 * @return {object}
 * @spec openspec/changes/logic-approval-task-form/specs/automation-approval-action/spec.md#requirement-a-maker-names-the-fields-an-approver-fills-in-req-bqtf-001
 */
function withForm(target, fields) {
	const form = storedForm(fields)
	if (form !== null) {
		target.form = form
	}
	return target
}

const BUILDERS = Object.freeze({
	'send-notification': (action) => ({
		type: 'send-notification',
		channels: action.channels,
		recipients: action.recipients,
		subject: { en: action.subjectEn, nl: action.subjectNl },
	}),
	'run-synchronization': (action) => ({
		type: 'run-synchronization',
		synchronizationId: action.synchronizationId,
	}),
	'object-op': (action) => withOptionalObject(
		{ type: 'object-op', operation: action.operation, schema: action.schema },
		{ key: 'fieldMapping', text: action.fieldMappingText },
	),
	approval: (action) => withForm({
		type: 'approval',
		assigneeGroup: action.assigneeGroup,
		onApprove: listOrEmpty(action.onApprove),
		onReject: listOrEmpty(action.onReject),
	}, action.formFields),
	generateDocument: (action) => ({
		type: 'generateDocument',
		templateId: action.templateId,
		output: listOrEmpty(action.output),
	}),
	'ai-step': toPersistedAiStep,
	// logic-script-step D1: the code, its time limit and a note.
	'script-step': toPersistedScriptStep,
	// logic-shared-flows-and-automations D4: the step names the automation it runs.
	'run-automation': (action) => ({
		type: 'run-automation',
		automation: String(action.automation || '').trim(),
	}),
	'rule-decision': (action) => withOptionalObject(
		{
			type: 'rule-decision',
			ruleSetSlug: String(action.ruleSetSlug || '').trim(),
			outputField: String(action.outputField || '').trim(),
		},
		{ key: 'inputMapping', text: action.inputMappingText },
	),
})

/**
 * A webhook action; also the shape of any action type the editor does not name.
 *
 * @param {object} action The working action.
 * @return {object} The persisted webhook action.
 */
function webhookAction(action) {
	// A webhook names an integriq source and a path; a bare URL is no longer
	// stored (logic-automation-actions-that-run D5, REQ-BQAR-003).
	return withOptionalObject(
		{
			type: 'webhook',
			source: String(action.source || '').trim(),
			path: String(action.path || '').trim() || '/',
		},
		{ key: 'payloadTemplate', text: action.payloadTemplateText },
	)
}

/**
 * The persisted shape of one working action.
 *
 * @param {object} action The working action from the editor.
 * @return {object} The action as stored in `actions[]`.
 */
export function toPersistedAction(action) {
	const build = Object.hasOwn(BUILDERS, action.type)
		? BUILDERS[action.type]
		: webhookAction
	return build(action)
}
