/**
 * The message to show for a failed request: the server's `detail`, then its
 * `error`, then the thrown error's own message, then the caller's fallback.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

/**
 * @param {Error|object} error - the rejection of an axios call.
 * @param {string} fallback - the translated message used when nothing else is known.
 * @return {string} the message.
 */
export function requestErrorMessage(error, fallback) {
	const data = error?.response?.data
	return data?.detail || data?.error || error?.message || fallback
}
