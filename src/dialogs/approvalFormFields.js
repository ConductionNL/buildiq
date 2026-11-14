// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * The approval form's field list (logic-approval-task-form, design D1/D2):
 * which trigger-schema properties an approver can fill in, and the stored
 * `actions[].form` shape. Mirrors `ApprovalFormDeclaration` on the server, so
 * the picker offers only fields the compiler accepts.
 */

/**
 * Whether a schema property can be asked of an approver: present, not
 * read-only, not hidden, not a list and not a relation.
 *
 * @param {object|null|undefined} property - the schema property definition.
 * @return {boolean}
 * @spec openspec/changes/logic-approval-task-form/specs/automation-approval-action/spec.md#requirement-a-maker-names-the-fields-an-approver-fills-in-req-bqtf-001
 */
export function isAskableProperty(property) {
	if (!property || typeof property !== 'object' || Array.isArray(property)) {
		return false
	}
	if (property.readOnly === true || property.visible === false) {
		return false
	}
	const types = Array.isArray(property.type) ? property.type : [property.type]
	if (types.includes('array') || types.includes('object')) {
		return false
	}
	return !('$ref' in property || 'inversedBy' in property || 'objectConfiguration' in property)
}

/**
 * Picker options for the fields an approver can fill in, in schema order.
 *
 * @param {object} properties - the trigger schema's `properties`.
 * @return {Array<{value: string, label: string}>}
 * @spec openspec/changes/logic-approval-task-form/specs/automation-approval-action/spec.md#requirement-a-maker-names-the-fields-an-approver-fills-in-req-bqtf-001
 */
export function askableFieldOptions(properties) {
	if (!properties || typeof properties !== 'object') {
		return []
	}
	return Object.entries(properties)
		.filter(([, property]) => isAskableProperty(property))
		.map(([name, property]) => ({
			value: name,
			label: (property && property.title) || name,
		}))
}

/**
 * The editor's field rows from a stored approval action; `[]` without a form.
 *
 * @param {object} action - the stored approval action.
 * @return {Array<{field: string, required: boolean}>}
 * @spec openspec/changes/logic-approval-task-form/specs/automation-approval-action/spec.md#requirement-a-maker-names-the-fields-an-approver-fills-in-req-bqtf-001
 */
export function formFieldsOf(action) {
	const fields = action && action.form && action.form.fields
	if (!Array.isArray(fields)) {
		return []
	}
	return fields
		.filter((entry) => entry && typeof entry === 'object' && typeof entry.field === 'string' && entry.field !== '')
		.map((entry) => ({ field: entry.field, required: entry.required === true }))
}

/**
 * The stored `form` for an approval action's field rows, or null for none:
 * an empty list stores no `form`, so the approval completes as before.
 *
 * @param {Array<{field: string, required: boolean}>} fields - the field rows.
 * @return {?{fields: Array<{field: string, required: boolean}>}}
 * @spec openspec/changes/logic-approval-task-form/specs/automation-approval-action/spec.md#requirement-a-maker-names-the-fields-an-approver-fills-in-req-bqtf-001
 */
export function storedForm(fields) {
	if (!Array.isArray(fields) || fields.length === 0) {
		return null
	}
	return {
		fields: fields.map((entry) => ({ field: entry.field, required: entry.required === true })),
	}
}

/**
 * The rows with one moved by `offset` places; unchanged at either end.
 *
 * @param {Array} rows - the rows.
 * @param {number} index - the row to move.
 * @param {number} offset - -1 for up, 1 for down.
 * @return {Array}
 * @spec openspec/changes/logic-approval-task-form/specs/automation-approval-action/spec.md#requirement-a-maker-names-the-fields-an-approver-fills-in-req-bqtf-001
 */
export function moved(rows, index, offset) {
	const target = index + offset
	if (index < 0 || index >= rows.length || target < 0 || target >= rows.length) {
		return rows.slice()
	}
	const next = rows.slice()
	const [row] = next.splice(index, 1)
	next.splice(target, 0, row)
	return next
}
