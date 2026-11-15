/**
 * FEEL decision-table cell-condition validation (client mirror).
 *
 * Mirrors the cell-condition grammar that the PHP DecisionTableEvaluator
 * accepts (see lib/Service/DecisionTableEvaluator::cellMatches): a don't-care
 * token, a comparison operator + literal, an inclusive range, a list
 * membership, or a bare literal. This is a lightweight syntactic check for the
 * editor's inline red-badge — full evaluation happens server-side.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

const DONT_CARE = ['', '-', '*', 'any']

/**
 * The condition shapes accepted before the lone-`=` check, in order:
 * a comparison operator + non-empty operand, an inclusive range low..high
 * (numeric or simple token bounds), and list membership `in (a, b, c)`.
 */
const OPERATOR_SHAPES = [
	/^(==|!=|<=|>=|<|>)\s*\S+/,
	/^\S+\.\.\S+$/,
	/^in\s*\(.*\)$/i,
]

/** A lone `=` is the classic mistake — rejected explicitly. */
const LONE_EQUALS = /^=[^=]/

/** Bare literal equality (number or quoted/plain string). */
const BARE_LITERAL = /^('.*'|[^()]+)$/

/**
 * Whether a decision-table cell condition is syntactically valid.
 *
 * @param {string|undefined|null} raw The cell condition source.
 * @return {boolean} True when the cell is a recognised condition shape.
 */
export function isCellConditionValid(raw) {
	const value = cellSource(raw)
	if (DONT_CARE.includes(value.toLowerCase()) || hasOperatorShape(value)) {
		return true
	}
	if (isLoneEquals(value)) {
		return false
	}
	return BARE_LITERAL.test(value)
}

/**
 * The trimmed cell source, '' for none.
 *
 * @param {string|undefined|null} raw The cell condition source.
 * @return {string}
 */
function cellSource(raw) {
	return raw === undefined || raw === null ? '' : String(raw).trim()
}

/**
 * Whether the source is a comparison, a range or a list membership.
 *
 * @param {string} value The trimmed cell source.
 * @return {boolean}
 */
function hasOperatorShape(value) {
	return OPERATOR_SHAPES.some((shape) => shape.test(value))
}

/**
 * Whether the source is a lone `=` (with or without an operand).
 *
 * @param {string} value The trimmed cell source.
 * @return {boolean}
 */
function isLoneEquals(value) {
	return LONE_EQUALS.test(value) || value === '='
}
