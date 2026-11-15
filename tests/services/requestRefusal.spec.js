/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for reading a refused request into status, error code and
 * message.
 */
import { describe, expect, it } from 'vitest'
import { refusalData, refusalStatus, requestRefusal } from '../../src/services/requestRefusal.js'

describe('requestRefusal', () => {
	it('reads the status, error code and message the server answered', () => {
		const err = { message: 'Request failed with status code 409', response: { status: 409, data: { error: 'slug_taken', message: 'That slug is taken.' } } }

		expect(requestRefusal(err)).toEqual({ status: 409, error: 'slug_taken', message: 'That slug is taken.' })
	})

	it('falls back to the error\'s own message and a network error without a body', () => {
		expect(requestRefusal({ message: 'timeout of 0ms', response: { status: 504 } })).toEqual({
			status: 504,
			error: 'network_error',
			message: 'timeout of 0ms',
		})
	})

	it('a request with no response is status 0', () => {
		expect(requestRefusal(new Error('Network Error'))).toEqual({ status: 0, error: 'network_error', message: 'Network Error' })
	})

	it.each([[null], [undefined], [{}]])('says the request failed for %j', (err) => {
		expect(requestRefusal(err)).toEqual({ status: 0, error: 'network_error', message: 'Request failed.' })
	})
})

describe('refusalData and refusalStatus', () => {
	it('answer the body and status, or an empty body and 0', () => {
		const err = { response: { status: 403, data: { error: 'forbidden' } } }

		expect(refusalData(err)).toEqual({ error: 'forbidden' })
		expect(refusalStatus(err)).toBe(403)
		expect(refusalData({ response: { status: 500, data: null } })).toEqual({})
		expect(refusalData(null)).toEqual({})
		expect(refusalStatus(null)).toBe(0)
	})
})
