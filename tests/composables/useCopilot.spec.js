/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for src/composables/useCopilot.js (spec ai-copilot
 * REQ-OBAIC-001/002/003).
 *
 * Covers:
 *  - state machine transitions (idle -> planning -> review -> executing -> done | error)
 *  - health probe caching across composable instances
 *  - canApprove false on a v2-invalid predicted manifest, true on a valid one
 *  - discard() resets to idle without sending any request
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'

const axiosGet = vi.fn()
const axiosPost = vi.fn()
vi.mock('@nextcloud/axios', () => ({
	default: { get: (...a) => axiosGet(...a), post: (...a) => axiosPost(...a) },
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))

// Mutable validator result the test can flip per-case; the default stub in
// tests/vitest/stubs/conduction-nextcloud-vue.js always returns valid:true,
// so this per-test mock lets us exercise the invalid branch too.
let validatorResult = { valid: true, errors: [] }
vi.mock('@conduction/nextcloud-vue', () => ({
	validateManifest: (manifest) => validatorResult,
}))

import {
	useCopilot,
	clearCopilotHealthCache,
} from '../../src/composables/useCopilot.js'

describe('useCopilot — spec ai-copilot REQ-OBAIC-001/002/003', () => {
	beforeEach(() => {
		axiosGet.mockReset()
		axiosPost.mockReset()
		clearCopilotHealthCache()
		validatorResult = { valid: true, errors: [] }
	})

	it('starts in the idle state', () => {
		const copilot = useCopilot()
		expect(copilot.state.value).toBe('idle')
	})

	it('checkHealth() sets isAvailable true on a 200 response', async () => {
		axiosGet.mockResolvedValueOnce({ data: { status: 'ok' } })
		const copilot = useCopilot()
		await copilot.checkHealth()
		expect(copilot.isAvailable.value).toBe(true)
	})

	it('checkHealth() sets isAvailable false with a reason on a 503 response', async () => {
		axiosGet.mockRejectedValueOnce({
			response: {
				status: 503,
				data: { status: 'unavailable', reason: 'no_provider' },
			},
		})
		const copilot = useCopilot()
		await copilot.checkHealth()
		expect(copilot.isAvailable.value).toBe(false)
		expect(copilot.health.value.reason).toBe('no_provider')
	})

	it('caches the health probe across composable instances (one network call)', async () => {
		axiosGet.mockResolvedValueOnce({ data: { status: 'ok' } })
		const first = useCopilot()
		await first.checkHealth()

		const second = useCopilot()
		await second.checkHealth()

		expect(axiosGet).toHaveBeenCalledTimes(1)
		expect(second.isAvailable.value).toBe(true)
	})

	it('generatePlan() moves idle -> planning -> review on a successful plan response', async () => {
		let resolvePost
		axiosPost.mockReturnValueOnce(
			new Promise((resolve) => {
				resolvePost = resolve
			}),
		)

		const copilot = useCopilot()
		const promise = copilot.generatePlan('A tool library')
		expect(copilot.state.value).toBe('planning')

		resolvePost({
			data: { summary: 'A tool library', steps: [], manifests: {} },
		})
		await promise

		expect(copilot.state.value).toBe('review')
		expect(copilot.plan.value.summary).toBe('A tool library')
	})

	it('generatePlan() moves to the error state on a failed plan request', async () => {
		axiosPost.mockRejectedValueOnce({
			response: {
				status: 422,
				data: { error: 'plan_invalid', message: 'nope' },
			},
		})

		const copilot = useCopilot()
		await copilot.generatePlan('A tool library')

		expect(copilot.state.value).toBe('error')
		expect(copilot.errorMessage.value).toBe('nope')
	})

	it('canApprove is true when every predicted manifest passes the canonical validator', async () => {
		validatorResult = { valid: true, errors: [] }
		axiosPost.mockResolvedValueOnce({
			data: {
				summary: 'x',
				steps: [],
				manifests: {
					'app@development': { current: {}, predicted: { pages: [] } },
				},
			},
		})

		const copilot = useCopilot()
		await copilot.generatePlan('x')
		expect(copilot.canApprove.value).toBe(true)
	})

	it('canApprove is false when a predicted manifest fails the canonical validator', async () => {
		validatorResult = { valid: false, errors: ['/pages/0 is invalid'] }
		axiosPost.mockResolvedValueOnce({
			data: {
				summary: 'x',
				steps: [],
				manifests: {
					'app@development': { current: {}, predicted: { pages: [] } },
				},
			},
		})

		const copilot = useCopilot()
		await copilot.generatePlan('x')
		expect(copilot.canApprove.value).toBe(false)
	})

	it('approve() is a no-op while canApprove is false (no execute request sent)', async () => {
		validatorResult = { valid: false, errors: ['bad'] }
		axiosPost.mockResolvedValueOnce({
			data: {
				summary: 'x',
				steps: [],
				manifests: { 'app@development': { current: {}, predicted: {} } },
			},
		})

		const copilot = useCopilot()
		await copilot.generatePlan('x')
		axiosPost.mockClear()

		await copilot.approve()
		expect(axiosPost).not.toHaveBeenCalled()
		expect(copilot.state.value).toBe('review')
	})

	it('approve() executes the plan and moves to done on success', async () => {
		axiosPost
			.mockResolvedValueOnce({
				data: {
					summary: 'x',
					steps: [{ tool: 'openbuild.createApp', arguments: {} }],
					manifests: {},
				},
			})
			.mockResolvedValueOnce({ data: { results: [{ success: true }] } })

		const copilot = useCopilot()
		await copilot.generatePlan('x')
		await copilot.approve()

		expect(copilot.state.value).toBe('done')
		expect(copilot.executeResult.value.results).toHaveLength(1)
	})

	it('discard() resets to idle without sending any request', async () => {
		axiosPost.mockResolvedValueOnce({
			data: { summary: 'x', steps: [], manifests: {} },
		})
		const copilot = useCopilot()
		await copilot.generatePlan('x')
		axiosPost.mockClear()

		copilot.discard()

		expect(copilot.state.value).toBe('idle')
		expect(copilot.plan.value).toBeNull()
		expect(axiosPost).not.toHaveBeenCalled()
	})

	// -------------------------------------------------------------------
	// Agent-scoping (spec agent-workspace)
	// -------------------------------------------------------------------

	it('generatePlan() sends agentId when given', async () => {
		axiosPost.mockResolvedValueOnce({
			data: { summary: 'x', steps: [], manifests: {} },
		})
		const copilot = useCopilot()
		await copilot.generatePlan('add a page', 'tool-library', 'agent-1')

		expect(axiosPost).toHaveBeenCalledWith(
			'/apps/openbuild/api/copilot/plan',
			expect.objectContaining({
				brief: 'add a page',
				appSlug: 'tool-library',
				agentId: 'agent-1',
			}),
		)
	})

	it('approve() forwards agentId and the original prompt to the execute request', async () => {
		axiosPost
			.mockResolvedValueOnce({
				data: {
					summary: 'x',
					steps: [{ tool: 'openbuild.createApp', arguments: {} }],
					manifests: {},
				},
			})
			.mockResolvedValueOnce({ data: { results: [{ success: true }] } })

		const copilot = useCopilot()
		await copilot.generatePlan('add a page', undefined, 'agent-1')
		await copilot.approve('agent-1')

		expect(axiosPost).toHaveBeenNthCalledWith(
			2,
			'/apps/openbuild/api/copilot/execute',
			expect.objectContaining({ agentId: 'agent-1', prompt: 'add a page' }),
		)
		expect(copilot.state.value).toBe('done')
	})

	it('discard(agentId) posts to the discard endpoint for an agent-scoped plan', async () => {
		axiosPost.mockResolvedValueOnce({
			data: { summary: 'x', steps: [], manifests: {} },
		})
		const copilot = useCopilot()
		await copilot.generatePlan('add a page', undefined, 'agent-1')
		axiosPost.mockClear()
		axiosPost.mockResolvedValueOnce({ data: { status: 'logged' } })

		copilot.discard('agent-1')
		await Promise.resolve()

		expect(axiosPost).toHaveBeenCalledWith(
			'/apps/openbuild/api/copilot/discard',
			expect.objectContaining({ agentId: 'agent-1', prompt: 'add a page' }),
		)
		expect(copilot.state.value).toBe('idle')
	})

	it('discard() without agentId still sends no request (bare copilot, unchanged)', async () => {
		axiosPost.mockResolvedValueOnce({
			data: { summary: 'x', steps: [], manifests: {} },
		})
		const copilot = useCopilot()
		await copilot.generatePlan('x')
		axiosPost.mockClear()

		copilot.discard()

		expect(axiosPost).not.toHaveBeenCalled()
	})
})
