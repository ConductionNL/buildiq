/**
 * The Automations page reads the last run of every listed automation in one
 * call and shows it under the row: the time and outcome, the failure with
 * its reason, or that there was no run (logic-automation-run-log REQ-BQRL-003).
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-the-automations-page-shows-the-last-run-and-opens-the-run-log-req-bqrl-003
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))

const axios = (await import('@nextcloud/axios')).default
const AutomationsPage = (await import('../../src/views/AutomationsPage.vue')).default
const { methods } = AutomationsPage

/**
 * A page context with these automations and summaries.
 *
 * @param {Array<object>} automations The listed automations.
 * @param {object} runSummaries The summaries by uuid.
 * @return {object}
 */
function page(automations = [], runSummaries = {}) {
	const ctx = { automations, runSummaries, runsAutomation: null }
	for (const name of ['refreshRunSummaries', 'lastRunLine', 'lastRunFailed', 'openRuns']) {
		ctx[name] = methods[name].bind(ctx)
	}
	return ctx
}

describe('AutomationsPage last run', () => {
	beforeEach(() => {
		axios.get.mockReset()
	})

	it('reads every visible row\'s summary in one call', async () => {
		axios.get.mockResolvedValue({ data: { summaries: { a1: { lastRunAt: '2026-10-07T14:02:00Z', lastOutcome: 'failed', lastError: 'Webhook answered 500' } } } })
		const ctx = page([{ id: 'a1' }, { id: 'a2' }, {}])

		await ctx.refreshRunSummaries()

		expect(axios.get).toHaveBeenCalledTimes(1)
		expect(axios.get).toHaveBeenCalledWith('/apps/buildiq/api/automations/runs/summary', { params: { uuids: 'a1,a2' } })
		expect(ctx.lastRunLine('a1')).toBe('Last run failed: Webhook answered 500')
		expect(ctx.lastRunFailed('a1')).toBe(true)
		expect(ctx.lastRunLine('a2')).toBe('No run in the last 90 days')
		expect(ctx.lastRunFailed('a2')).toBe(false)
	})

	it('shows the time and outcome of a run that did not fail', () => {
		const ctx = page([], { a1: { lastRunAt: '2026-10-07T14:02:00Z', lastOutcome: 'succeeded', lastError: null } })

		expect(ctx.lastRunLine('a1')).toMatch(/^Last run .+, succeeded$/)
	})

	it('asks nothing for an empty list, and keeps no summaries when the read fails', async () => {
		const ctx = page([], { stale: {} })
		await ctx.refreshRunSummaries()
		expect(axios.get).not.toHaveBeenCalled()
		expect(ctx.runSummaries).toEqual({})

		axios.get.mockRejectedValue(new Error('offline'))
		const other = page([{ id: 'a1' }], { a1: { lastRunAt: 'x' } })
		await other.refreshRunSummaries()
		expect(other.runSummaries).toEqual({})
	})

	it('opens the runs dialog for the chosen automation', () => {
		const ctx = page()
		ctx.openRuns({ id: 'a1' })
		expect(ctx.runsAutomation).toEqual({ id: 'a1' })
	})
})
