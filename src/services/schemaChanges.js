/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Plain-language description of the schema changes OpenRegister treats as
 * breaking. OpenRegister refuses such a save with a 409 until the author
 * confirms, and the designer uses these lines to say what the author is
 * confirming.
 */
import { translate as t } from '@nextcloud/l10n'

/**
 * The declared type of a property, for comparison.
 *
 * @param {object|undefined} property A JSON Schema property.
 * @return {string} The type, or '' when unknown.
 */
function typeOf(property) {
	if (!property || typeof property !== 'object') {
		return ''
	}
	return Array.isArray(property.type) ? property.type.join('|') : String(property.type || '')
}

/**
 * Describe the breaking changes between the saved schema and the one about to be saved.
 *
 * @spec openspec/specs/schema-designer-ui/spec.md
 * @param {object} saved The persisted schema body (properties, required).
 * @param {object} next The body about to be saved.
 * @return {string[]} One sentence per change, never empty.
 */
export function describeBreakingChanges(saved, next) {
	const savedProps = (saved && saved.properties) || {}
	const nextProps = (next && next.properties) || {}
	const savedRequired = new Set((saved && saved.required) || [])
	const lines = []

	for (const name of Object.keys(savedProps)) {
		if (!(name in nextProps)) {
			lines.push(t('buildiq', 'The field "{name}" is removed. Existing records lose its values.', { name }))
		}
	}

	for (const name of Object.keys(nextProps)) {
		if (!(name in savedProps)) {
			continue
		}
		const before = typeOf(savedProps[name])
		const after = typeOf(nextProps[name])
		if (before !== '' && after !== '' && before !== after) {
			lines.push(t('buildiq', 'The field "{name}" changes from {before} to {after}. Existing values may no longer fit.', { name, before, after }))
		}
	}

	for (const name of (next && next.required) || []) {
		if (!savedRequired.has(name)) {
			lines.push(t('buildiq', 'The field "{name}" becomes required. Records without a value fail validation until someone fills it in.', { name }))
		}
	}

	if (lines.length === 0) {
		lines.push(t('buildiq', 'This change can make existing records invalid.'))
	}

	return lines
}
