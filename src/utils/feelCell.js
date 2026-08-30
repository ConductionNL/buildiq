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
 * Whether a decision-table cell condition is syntactically valid.
 *
 * @param {string|undefined|null} raw The cell condition source.
 * @return {boolean} True when the cell is a recognised condition shape.
 */
export function isCellConditionValid(raw) {
	const value = raw === undefined || raw === null ? '' : String(raw).trim()

	if (DONT_CARE.includes(value.toLowerCase())) {
		return true
	}

	// Comparison operator + non-empty operand.
	if (/^(==|!=|<=|>=|<|>)\s*\S+/.test(value)) {
		return true
	}

	// Inclusive range low..high (numeric or simple token bounds).
	if (/^\S+\.\.\S+$/.test(value)) {
		return true
	}

	// List membership: in (a, b, c).
	if (/^in\s*\(.*\)$/i.test(value)) {
		return true
	}

	// A lone `=` is the classic mistake — reject it explicitly.
	if (/^=[^=]/.test(value) || value === '=') {
		return false
	}

	// Bare literal equality (number or quoted/plain string).
	if (/^('.*'|[^()]+)$/.test(value)) {
		return true
	}

	return false
}
