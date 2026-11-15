/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for the edges of src/composables/useCopilot.js (spec
 * ai-copilot): plan responses without predicted manifests, a manifest
 * validator that throws, and a failed execute request.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const axiosGet = vi.fn()
const axiosPost = vi.fn()
vi.mock('@nextcloud/axios', () => ({
	default: { get: (...a) => axiosGet(...a), post: (...a) => axiosPost(...a) },
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))

let validator = () => ({ valid: true, errors: [] })
vi.mock('@conduction/nextcloud-vue', () => ({
	validateManifest: (manifest) => validator(manifest),
}))

import {
	clearCopilotHealthCache,
	useCopilot,
} from '../../src/composables/useCopilot.js'

/**
 * A plan response carrying the given manifests block.
 *
 * @param {*} manifests The manifests block.
 * @return {{data: object}}
 */
function planWith(manifests) {
	return { data: { summary: 'Add a page', steps: [{ tool: 'buildiq.addPage', arguments: {} }], manifests } }
}

describe('useCopilot — edges', () => {
	beforeEach(() => {
		axiosGet.mockReset()
		axiosPost.mockReset()
		clearCopilotHealthCache()
		validator = () => ({ valid: true, errors: [] })
	})

	it('validates nothing and allows approval when the plan has no manifests block', async () => {
		const seen = []
		validator = (m) => {
			seen.push(m)
			return { valid: false, errors: ['never'] }
		}
		axiosPost.mockResolvedValueOnce(planWith(null))

		const copilot = useCopilot()
		await copilot.generatePlan('x')

		expect(seen).toEqual([])
		expect(copilot.manifestErrors.value.size).toBe(0)
		expect(copilot.canApprove.value).toBe(true)
	})

	it('skips a version without a predicted manifest and validates the rest', async () => {
		const seen = []
		validator = (m) => {
			seen.push(m)
			return { valid: true, errors: [] }
		}
		axiosPost.mockResolvedValueOnce(planWith({
			'app@main': { current: {} },
			'app@development': null,
			'app@feature': { current: {}, predicted: { pages: [{ id: 'home' }] } },
		}))

		const copilot = useCopilot()
		await copilot.generatePlan('x')

		expect(seen).toEqual([{ pages: [{ id: 'home' }] }])
		expect([...copilot.manifestErrors.value.entries()]).toEqual([['app@feature', []]])
		expect(copilot.canApprove.value).toBe(true)
	})

	it('records a validator that throws as an error on that version and blocks approval', async () => {
		validator = (m) => {
			if (m.broken) {
				throw new Error('schema not loaded')
			}
			return { valid: true, errors: [] }
		}
		axiosPost.mockResolvedValueOnce(planWith({
			'app@development': { predicted: { broken: true } },
			'app@main': { predicted: { pages: [] } },
		}))

		const copilot = useCopilot()
		await copilot.generatePlan('x')

		expect(copilot.manifestErrors.value.get('app@development')).toEqual(['validator threw: schema not loaded'])
		expect(copilot.manifestErrors.value.get('app@main')).toEqual([])
		expect(copilot.state.value).toBe('review')
		expect(copilot.canApprove.value).toBe(false)
	})

	it('names a thrown non-Error value as it is', async () => {
		validator = () => {
			throw 'out of memory'
		}
		axiosPost.mockResolvedValueOnce(planWith({ 'app@development': { predicted: {} } }))

		const copilot = useCopilot()
		await copilot.generatePlan('x')

		expect(copilot.manifestErrors.value.get('app@development')).toEqual(['validator threw: out of memory'])
	})

	it('moves to error with the server and provider messages when execute fails', async () => {
		axiosPost
			.mockResolvedValueOnce(planWith({}))
			.mockRejectedValueOnce({
				response: {
					status: 502,
					data: { error: 'provider_failed', message: 'The plan could not be applied.', providerMessage: 'Chat provider is not configured' },
				},
			})

		const copilot = useCopilot()
		await copilot.generatePlan('x')
		await copilot.approve()

		expect(copilot.state.value).toBe('error')
		expect(copilot.errorMessage.value).toBe('The plan could not be applied.')
		expect(copilot.errorDetail.value).toBe('Chat provider is not configured')
		expect(copilot.executeResult.value).toBeNull()
	})

	it('leaves the provider detail empty when execute fails without one', async () => {
		axiosPost
			.mockResolvedValueOnce(planWith({}))
			.mockRejectedValueOnce(new Error('Network Error'))

		const copilot = useCopilot()
		await copilot.generatePlan('x')
		await copilot.approve()

		expect(copilot.state.value).toBe('error')
		expect(copilot.errorMessage.value).toBe('Network Error')
		expect(copilot.errorDetail.value).toBe('')
	})
})
