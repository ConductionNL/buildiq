/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for src/services/formLiveValues.js (change
 * forms-live-values-and-checks, REQ-BQLV-001 to REQ-BQLV-004).
 *
 * The recorded responses mirror buildiq's own RulesController: `schema()`
 * answers `{slug, name, version, status, ruleType, inputs, outputs}` and
 * `evaluate()` answers `{result, triggeredRules, executionTime, errors}`.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const getMock = vi.fn()
const postMock = vi.fn()
vi.mock('@nextcloud/axios', () => ({
	default: { get: (...a) => getMock(...a), post: (...a) => postMock(...a) },
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))

const {
	DEFAULT_TOKENS,
	createLiveValuesBridge,
	evaluatePreview,
	listRuleSets,
	resolveDefault,
	ruleSetColumns,
	unmetExplanation,
} = await import('../../src/services/formLiveValues.js')

beforeEach(() => {
	getMock.mockReset()
	postMock.mockReset()
})

describe('rule set client', () => {
	it('lists the rule sets a form can bind to', async () => {
		getMock.mockResolvedValue({
			data: {
				results: [
					{ slug: 'event-fee', name: 'Event fee', status: 'active' },
					{ slug: 'old-fee', name: 'Old fee', status: 'archived' },
				],
			},
		})

		const sets = await listRuleSets()

		expect(getMock).toHaveBeenCalledWith(
			'/apps/openregister/api/objects/buildiq/rule-set',
		)
		expect(sets).toEqual([
			{ slug: 'event-fee', name: 'Event fee', status: 'active' },
			{ slug: 'old-fee', name: 'Old fee', status: 'archived' },
		])
	})

	it('reads the inputs and outputs of one rule set', async () => {
		getMock.mockResolvedValue({
			data: {
				slug: 'event-fee',
				inputs: [{ name: 'Attendees', path: 'attendees', type: 'integer' }],
				outputs: [{ name: 'fee', type: 'number' }],
			},
		})

		const columns = await ruleSetColumns('event-fee')

		expect(getMock).toHaveBeenCalledWith(
			'/apps/buildiq/api/rules/event-fee/schema',
		)
		expect(columns).toEqual({
			inputs: [{ name: 'Attendees', path: 'attendees', type: 'integer' }],
			outputs: [{ name: 'fee', type: 'number' }],
		})
	})

	it('evaluates in preview mode so typing writes no execution log (REQ-BQLV-004)', async () => {
		postMock.mockResolvedValue({
			data: {
				result: { fee: 120 },
				triggeredRules: ['r2'],
				executionTime: 2,
				errors: [],
			},
		})

		const result = await evaluatePreview('event-fee', { attendees: 250 })

		expect(postMock).toHaveBeenCalledWith(
			'/apps/buildiq/api/rules/event-fee/evaluate',
			{
				payload: { attendees: 250 },
				mode: 'preview',
			},
		)
		expect(result).toEqual({ fee: 120 })
	})
})

describe('resolveDefault (REQ-BQLV-001)', () => {
	const context = {
		user: {
			uid: 'anna',
			displayName: 'Anna de Vries',
			email: 'anna@example.nl',
		},
		object: { title: 'Zomerfeest', organiser: { name: 'Gemeente' } },
		now: new Date('2026-10-02T09:30:00Z'),
	}

	it('offers the five tokens the picker shows', () => {
		expect(DEFAULT_TOKENS).toEqual([
			'@me',
			'@me.displayName',
			'@me.email',
			'@today',
			'@object.',
		])
	})

	it('fills the signed-in user, their name and their e-mail', () => {
		expect(resolveDefault('@me', context)).toBe('anna')
		expect(resolveDefault('@me.displayName', context)).toBe('Anna de Vries')
		expect(resolveDefault('@me.email', context)).toBe('anna@example.nl')
	})

	it('fills today as a date', () => {
		expect(resolveDefault('@today', context)).toBe('2026-10-02')
	})

	it('reads a field of the record the form was opened from', () => {
		expect(resolveDefault('@object.title', context)).toBe('Zomerfeest')
		expect(resolveDefault('@object.organiser.name', context)).toBe('Gemeente')
	})

	it('leaves a literal alone and fills nothing it cannot know', () => {
		expect(resolveDefault('Amsterdam', context)).toBe('Amsterdam')
		expect(resolveDefault('@object.title', { ...context, object: null })).toBe(
			undefined,
		)
		expect(resolveDefault('@me.email', { ...context, user: null })).toBe(
			undefined,
		)
	})
})

describe('unmetExplanation (REQ-BQLV-003)', () => {
	const eligibility = {
		ruleSet: 'loan-eligibility',
		passWhen: { output: 'decision', equals: 'approved' },
		explainWith: 'reason',
		blockSubmit: true,
	}

	it('explains with the rule set output when the condition is not met', () => {
		expect(
			unmetExplanation(eligibility, {
				decision: 'deny',
				reason: 'Eligibility criteria not met',
			}),
		).toBe('Eligibility criteria not met')
	})

	it('is empty when the condition holds', () => {
		expect(
			unmetExplanation(eligibility, { decision: 'approved', reason: '' }),
		).toBe('')
	})
})

describe('createLiveValuesBridge (REQ-BQLV-002, REQ-BQLV-003)', () => {
	beforeEach(() => {
		vi.useFakeTimers()
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	const fields = [
		{ key: 'attendees', label: 'Attendees', type: 'number' },
		{
			key: 'fee',
			label: 'Fee',
			type: 'number',
			calculate: {
				ruleSet: 'event-fee',
				output: 'fee',
				inputs: ['attendees'],
			},
		},
	]

	it('recomputes a calculated field once the answers it reads settle', async () => {
		const evaluate = vi.fn().mockResolvedValue({ fee: 120 })
		const onChange = vi.fn()
		const bridge = createLiveValuesBridge({ fields, evaluate, onChange })

		bridge.answersChanged({ attendees: 25 })
		bridge.answersChanged({ attendees: 250 })
		expect(evaluate).not.toHaveBeenCalled()

		await vi.advanceTimersByTimeAsync(400)

		expect(evaluate).toHaveBeenCalledTimes(1)
		expect(evaluate).toHaveBeenCalledWith('event-fee', { attendees: 250 })
		expect(onChange).toHaveBeenLastCalledWith({
			values: { fee: 120 },
			unmet: '',
			submitBlocked: false,
		})
	})

	it('does not recompute when an answer it does not read changes', async () => {
		const evaluate = vi.fn().mockResolvedValue({ fee: 120 })
		const bridge = createLiveValuesBridge({
			fields,
			evaluate,
			onChange: vi.fn(),
		})

		bridge.answersChanged({ attendees: 250, remarks: 'a' })
		await vi.advanceTimersByTimeAsync(400)
		bridge.answersChanged({ attendees: 250, remarks: 'ab' })
		await vi.advanceTimersByTimeAsync(400)

		expect(evaluate).toHaveBeenCalledTimes(1)
	})

	it('blocks submit with the explanation while the check fails', async () => {
		const evaluate = vi.fn().mockResolvedValue({
			decision: 'deny',
			reason: 'Eligibility criteria not met',
		})
		const onChange = vi.fn()
		const bridge = createLiveValuesBridge({
			fields: [{ key: 'monthlyIncome', label: 'Income', type: 'number' }],
			eligibility: {
				ruleSet: 'loan-eligibility',
				passWhen: { output: 'decision', equals: 'approved' },
				explainWith: 'reason',
				blockSubmit: true,
			},
			evaluate,
			onChange,
		})

		bridge.answersChanged({ monthlyIncome: 900 })
		await vi.advanceTimersByTimeAsync(400)

		expect(evaluate).toHaveBeenCalledWith('loan-eligibility', {
			monthlyIncome: 900,
		})
		expect(onChange).toHaveBeenLastCalledWith({
			values: {},
			unmet: 'Eligibility criteria not met',
			submitBlocked: true,
		})
	})

	it('keeps submit blocked when the check cannot be evaluated', async () => {
		const evaluate = vi.fn().mockRejectedValue(new Error('429'))
		const onChange = vi.fn()
		const bridge = createLiveValuesBridge({
			fields: [],
			eligibility: {
				ruleSet: 'loan-eligibility',
				passWhen: { output: 'decision', equals: 'approved' },
				explainWith: 'reason',
				blockSubmit: true,
			},
			evaluate,
			onChange,
		})

		bridge.answersChanged({ monthlyIncome: 900 })
		await vi.advanceTimersByTimeAsync(400)

		expect(onChange.mock.calls.at(-1)[0].submitBlocked).toBe(true)
	})
})
