/**
 * The list out of an OpenRegister or buildiq list response: a bare array as
 * is, the `results` of an envelope, and an empty list for anything else.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

/**
 * @param {Array|object|null} data - the raw response body.
 * @return {Array<object>} the list.
 */
export function listResults(data) {
	if (Array.isArray(data)) {
		return data
	}
	if (data && Array.isArray(data.results)) {
		return data.results
	}
	return []
}
