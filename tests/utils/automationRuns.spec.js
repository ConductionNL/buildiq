/**
 * Which actions OpenRegister runs without buildiq, and the last-run line of
 * an automation row (logic-automation-run-log).
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md
 */
import { describe, expect, it } from 'vitest'
import { lastRunText, openRegisterSteps, outcomeLabel, runsEntirelyInOpenRegister, runsInOpenRegister, runTime } from '../../src/utils/automationRuns.js'

const onCreate = (actions) => ({ trigger: { type: 'object-created', schema: 'ticket' }, actions })

describe('automationRuns', () => {
	it('says a notification-only automation runs entirely in OpenRegister', () => {
		expect(runsEntirelyInOpenRegister(onCreate([{ type: 'send-notification' }]))).toBe(true)
		expect(runsEntirelyInOpenRegister(onCreate([{ type: 'send-notification' }, { type: 'approval' }]))).toBe(false)
		expect(runsEntirelyInOpenRegister(onCreate([]))).toBe(false)
		expect(runsEntirelyInOpenRegister({ trigger: { type: 'manual' }, actions: [{ type: 'send-notification' }] })).toBe(false)
	})

	it('counts an approve or reject follow-up as buildiq\'s own', () => {
		const automation = onCreate([{ type: 'approval', onApprove: [{ type: 'send-notification' }] }])
		expect(runsInOpenRegister(automation, { type: 'send-notification' }, true)).toBe(false)
		expect(runsInOpenRegister(automation, { type: 'send-notification' })).toBe(true)
		expect(runsInOpenRegister(automation, { type: 'generateDocument' })).toBe(false)
	})

	it('labels the OpenRegister steps of a mixed automation, never with a success', () => {
		const steps = openRegisterSteps(onCreate([{ type: 'approval' }, { type: 'webhook' }, null]))
		expect(steps).toEqual([{ action: 'webhook', outcome: 'in-openregister', message: '' }])
		expect(outcomeLabel('in-openregister')).toBe('Runs in OpenRegister. No per-run record yet.')
	})

	it('words the last run: none, a failure with its reason, or the time and outcome', () => {
		expect(lastRunText(null)).toBe('No run in the last 90 days')
		expect(lastRunText({ lastRunAt: null, lastOutcome: null, lastError: null })).toBe('No run in the last 90 days')
		expect(lastRunText({ lastRunAt: '2026-10-07T14:02:00Z', lastOutcome: 'failed', lastError: 'Webhook answered 500' })).toBe('Last run failed: Webhook answered 500')
		const text = lastRunText({ lastRunAt: '2026-10-07T14:02:00Z', lastOutcome: 'succeeded', lastError: null }, 'en-GB')
		expect(text).toMatch(/^Last run 7 Oct \d\d:02, succeeded$/)
	})

	it('shows no time for a missing or broken date, and passes an unknown outcome through', () => {
		expect(runTime('')).toBe('')
		expect(runTime('not a date')).toBe('')
		expect(outcomeLabel('exotic')).toBe('exotic')
		expect(outcomeLabel(undefined)).toBe('')
	})
})
