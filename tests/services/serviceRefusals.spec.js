/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest: the refusal shape the copilot, registration-form and page-layout
 * clients throw when a request fails — with the server's own envelope, with a
 * response that has no body, and without any response.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const getMock = vi.fn()
const postMock = vi.fn()
const putMock = vi.fn()
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: (...a) => getMock(...a),
		post: (...a) => postMock(...a),
		put: (...a) => putMock(...a),
	},
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))

const { requestPlan, executePlan } = await import('../../src/services/copilot.js')
const { fetchRegistrationForms, saveRegistrationForm } = await import(
	'../../src/services/registrationForms.js'
)
const { fetchPageLayouts } = await import('../../src/services/pageLayouts.js')

const ENVELOPE = Object.assign(new Error('Request failed with status code 422'), {
	response: {
		status: 422,
		data: {
			error: 'invalid',
			message: 'Not valid',
			providerMessage: 'Provider says no',
			findings: [{ path: 'a' }],
		},
	},
})
const NO_BODY = Object.assign(new Error('Request failed with status code 500'), {
	response: { status: 500, data: '' },
})
const NETWORK = new Error('Network Error')
const BARE = Object.assign(new Error(''), { response: { status: 503 } })

/**
 * The rejection of a call.
 *
 * @param {Promise} promise - the call.
 * @return {Promise<unknown>}
 */
async function rejection(promise) {
	try {
		await promise
	} catch (e) {
		return e
	}
	return 'resolved'
}

describe('service refusals', () => {
	beforeEach(() => {
		getMock.mockReset()
		postMock.mockReset()
		putMock.mockReset()
	})

	it('copilot carries the provider message', async () => {
		const shapes = []
		for (const err of [ENVELOPE, NO_BODY, NETWORK, BARE]) {
			postMock.mockRejectedValueOnce(err)
			shapes.push(await rejection(requestPlan({ brief: 'x' })))
		}
		postMock.mockRejectedValueOnce(NETWORK)
		shapes.push(await rejection(executePlan({ steps: [] })))
		expect(shapes).toEqual([
			{ status: 422, error: 'invalid', message: 'Not valid', providerMessage: 'Provider says no' },
			{ status: 500, error: 'network_error', message: 'Request failed with status code 500', providerMessage: '' },
			{ status: 0, error: 'network_error', message: 'Network Error', providerMessage: '' },
			{ status: 503, error: 'network_error', message: 'Request failed.', providerMessage: '' },
			{ status: 0, error: 'network_error', message: 'Network Error', providerMessage: '' },
		])
		expect(Object.keys(shapes[0])).toEqual(['status', 'error', 'message', 'providerMessage'])
	})

	it('copilot throws on a rejection without an error', async () => {
		postMock.mockRejectedValueOnce(null)
		expect(await rejection(requestPlan({ brief: 'x' }))).toBeInstanceOf(TypeError)
	})

	it('registration forms carry the findings', async () => {
		const shapes = []
		for (const err of [ENVELOPE, NO_BODY, NETWORK, null]) {
			getMock.mockRejectedValueOnce(err)
			shapes.push(await rejection(fetchRegistrationForms({ register: 'r', schema: 's' })))
		}
		putMock.mockRejectedValueOnce(
			Object.assign(new Error('x'), { response: { status: 409, data: { findings: 'no' } } }),
		)
		shapes.push(await rejection(saveRegistrationForm({})))
		expect(shapes).toEqual([
			{ status: 422, error: 'invalid', message: 'Not valid', findings: [{ path: 'a' }] },
			{ status: 500, error: 'network_error', message: 'Request failed with status code 500', findings: [] },
			{ status: 0, error: 'network_error', message: 'Network Error', findings: [] },
			{ status: 0, error: 'network_error', message: 'Request failed.', findings: [] },
			{ status: 409, error: 'network_error', message: 'x', findings: [] },
		])
		expect(Object.keys(shapes[0])).toEqual(['status', 'error', 'message', 'findings'])
	})

	it('page layouts carry the plain refusal', async () => {
		const shapes = []
		for (const err of [ENVELOPE, NO_BODY, NETWORK, null, BARE]) {
			getMock.mockRejectedValueOnce(err)
			shapes.push(await rejection(fetchPageLayouts({ register: 'r', schema: 's' })))
		}
		expect(shapes).toEqual([
			{ status: 422, error: 'invalid', message: 'Not valid' },
			{ status: 500, error: 'network_error', message: 'Request failed with status code 500' },
			{ status: 0, error: 'network_error', message: 'Network Error' },
			{ status: 0, error: 'network_error', message: 'Request failed.' },
			{ status: 503, error: 'network_error', message: 'Request failed.' },
		])
		expect(Object.keys(shapes[0])).toEqual(['status', 'error', 'message'])
	})
})
