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
 * @spec openspec/changes/automation-approval-steps/tasks.md#3.1
 */

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
	approval: (action) => ({
		type: 'approval',
		assigneeGroup: action.assigneeGroup,
		onApprove: listOrEmpty(action.onApprove),
		onReject: listOrEmpty(action.onReject),
	}),
	generateDocument: (action) => ({
		type: 'generateDocument',
		templateId: action.templateId,
		output: listOrEmpty(action.output),
	}),
})

/**
 * A webhook action; also the shape of any action type the editor does not name.
 *
 * @param {object} action The working action.
 * @return {object} The persisted webhook action.
 */
function webhookAction(action) {
	return withOptionalObject(
		{ type: 'webhook', url: action.url },
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
