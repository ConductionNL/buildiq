/**
 * SPDX-FileCopyrightText: 2026 ConductionNL / Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The automatic-number setting of a text field (data-generated-number-field,
 * REQ-OBSD-009): OpenRegister's `x-openregister-generated` keyword
 * `{ sequence, format, resetOn }`, its live example and the format checks
 * OpenRegister applies when it imports the schema. Numbering itself is
 * OpenRegister's; this module only reads, writes and previews the keyword.
 */

/** The keyword OpenRegister reads. */
export const GENERATED_KEY = 'x-openregister-generated'

/** The format a new setting starts with. */
export const DEFAULT_FORMAT = '{seq:5}'

/** A placeholder the format renders: `{seq}`, `{seq:n}`, `{year}` or `{month}`. */
const PLACEHOLDER = /\{(seq(?::(\d+))?|year|month)\}/g

/** Anything in braces, to find a placeholder OpenRegister does not know. */
const ANY_BRACES = /\{[^}]*\}/g

/**
 * The setting of a loaded property, or null when it has none.
 *
 * @param {object} prop The loaded property.
 * @return {{enabled: boolean, sequence: string, format: string, resetOn: string}|null} The setting.
 * @spec openspec/changes/data-generated-number-field/tasks.md#t02
 */
export function generatedFromProperty(prop) {
	const block = prop?.[GENERATED_KEY]
	if (!block || typeof block !== 'object' || Array.isArray(block)) {
		return null
	}
	return {
		enabled: true,
		sequence: typeof block.sequence === 'string' ? block.sequence : '',
		format: typeof block.format === 'string' ? block.format : '',
		resetOn: block.resetOn === 'year' ? 'year' : 'never',
	}
}

/**
 * A new, switched-on setting for a schema.
 *
 * @param {string} schemaSlug The schema slug, the default counter name.
 * @return {{enabled: boolean, sequence: string, format: string, resetOn: string}} The setting.
 * @spec openspec/changes/data-generated-number-field/tasks.md#t01
 */
export function newGeneratedSetting(schemaSlug) {
	return { enabled: true, sequence: schemaSlug || '', format: DEFAULT_FORMAT, resetOn: 'never' }
}

/**
 * Why OpenRegister would refuse a format, as a code, or null when it accepts it.
 *
 * @param {string} format The format.
 * @param {string} resetOn `year` or `never`.
 * @return {{code: string, placeholder?: string}|null} The refusal.
 * @spec openspec/changes/data-generated-number-field/tasks.md#t03
 */
export function formatProblem(format, resetOn) {
	const text = typeof format === 'string' ? format : ''
	for (const match of text.match(ANY_BRACES) || []) {
		if (!/^\{(seq(?::\d+)?|year|month)\}$/.test(match)) {
			return { code: 'unknown-placeholder', placeholder: match }
		}
	}
	if (!/\{seq(?::\d+)?\}/.test(text)) {
		return { code: 'no-counter' }
	}
	if (resetOn === 'year' && !text.includes('{year}')) {
		return { code: 'year-reset-without-year' }
	}
	return null
}

/**
 * The value a format renders for a counter value at a moment, the way
 * OpenRegister renders it: `{year}` four digits, `{month}` two, `{seq:n}`
 * the counter padded to n digits.
 *
 * @param {string} format The format.
 * @param {Date} moment The moment.
 * @param {number} counter The counter value.
 * @return {string} The rendered value.
 * @spec openspec/changes/data-generated-number-field/tasks.md#t01
 */
export function exampleNumber(format, moment = new Date(), counter = 1) {
	return String(format || '').replace(PLACEHOLDER, (whole, kind, width) => {
		if (kind === 'year') {
			return String(moment.getFullYear())
		}
		if (kind === 'month') {
			return String(moment.getMonth() + 1).padStart(2, '0')
		}
		return String(counter).padStart(Number(width || 1), '0')
	})
}

/**
 * The keyword a setting writes, or null when the setting is off.
 *
 * @param {object|null} setting The setting.
 * @param {string} schemaSlug The schema slug, used when no counter name is set.
 * @return {{sequence: string, format: string, resetOn: string}|null} The keyword.
 * @spec openspec/changes/data-generated-number-field/tasks.md#t02
 */
export function generatedKeyword(setting, schemaSlug = '') {
	if (!setting || !setting.enabled) {
		return null
	}
	return {
		sequence: (setting.sequence || '').trim() || schemaSlug,
		format: setting.format,
		resetOn: setting.resetOn === 'year' ? 'year' : 'never',
	}
}
