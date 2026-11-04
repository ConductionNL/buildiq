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
	return Array.isArray(property.type)
		? property.type.join('|')
		: String(property.type || '')
}

/**
 * One line per saved field that the next body no longer has.
 *
 * @param {object} savedProps The persisted properties.
 * @param {object} nextProps The properties about to be saved.
 * @return {string[]} The lines.
 */
function removedFieldLines(savedProps, nextProps) {
	return Object.keys(savedProps)
		.filter((name) => !(name in nextProps))
		.map((name) =>
			t(
				'buildiq',
				'The field "{name}" is removed. Existing records lose its values.',
				{ name },
			),
		)
}

/**
 * One line per kept field whose declared type changes.
 *
 * @param {object} savedProps The persisted properties.
 * @param {object} nextProps The properties about to be saved.
 * @return {string[]} The lines.
 */
function retypedFieldLines(savedProps, nextProps) {
	const lines = []
	for (const name of Object.keys(nextProps)) {
		const change = typeChange(name, { savedProps, nextProps })
		if (change !== null) {
			lines.push(
				t(
					'buildiq',
					'The field "{name}" changes from {before} to {after}. Existing values may no longer fit.',
					{ name, ...change },
				),
			)
		}
	}
	return lines
}

/**
 * The type change of a field both bodies type, or null when it keeps its type.
 *
 * @param {string} name The field name.
 * @param {{savedProps: object, nextProps: object}} props The saved and next properties.
 * @return {{before: string, after: string}|null} The two types.
 */
function typeChange(name, props) {
	if (!(name in props.savedProps)) {
		return null
	}
	const before = typeOf(props.savedProps[name])
	const after = typeOf(props.nextProps[name])
	return before !== '' && after !== '' && before !== after
		? { before, after }
		: null
}

/**
 * One line per field the next body requires that the saved one did not.
 *
 * @param {Set<string>} savedRequired The fields the persisted body requires.
 * @param {object} next The body about to be saved.
 * @return {string[]} The lines.
 */
function newlyRequiredLines(savedRequired, next) {
	const lines = []
	for (const name of (next && next.required) || []) {
		if (!savedRequired.has(name)) {
			lines.push(
				t(
					'buildiq',
					'The field "{name}" becomes required. Records without a value fail validation until someone fills it in.',
					{ name },
				),
			)
		}
	}
	return lines
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
	const lines = [
		...removedFieldLines(savedProps, nextProps),
		...retypedFieldLines(savedProps, nextProps),
		...newlyRequiredLines(savedRequired, next),
	]

	if (lines.length === 0) {
		lines.push(t('buildiq', 'This change can make existing records invalid.'))
	}

	return lines
}
