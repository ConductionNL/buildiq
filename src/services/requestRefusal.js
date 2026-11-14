// SPDX-License-Identifier: EUPL-1.2
/**
 * requestRefusal: the server's own refusal shape `{status, error, message}`,
 * read off an axios rejection, for the service clients that throw it.
 *
 * @module services/requestRefusal
 */

/**
 * The body of the response a rejection carries, or an empty object.
 *
 * @param {?Error} err - the axios error.
 * @return {object}
 */
export function refusalData(err) {
	const response = err && err.response
	return (response && response.data) || {}
}

/**
 * The HTTP status of the response a rejection carries, or 0 without one.
 *
 * @param {?Error} err - the axios error.
 * @return {number}
 */
export function refusalStatus(err) {
	const response = err && err.response
	return response ? response.status : 0
}

/**
 * Normalise an axios rejection into the server's own refusal shape.
 *
 * @param {?Error} err - the axios error.
 * @return {{status: number, error: string, message: string}} The refusal.
 */
export function requestRefusal(err) {
	const data = refusalData(err)
	return {
		status: refusalStatus(err),
		error: data.error || 'network_error',
		message: data.message || (err && err.message) || 'Request failed.',
	}
}
