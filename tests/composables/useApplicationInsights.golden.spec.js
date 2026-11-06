/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden output of `useApplicationInsights().refresh()` over a pool of
 * responses and failures. The request made and the full exposed state after
 * each case are pinned.
 *
 * Spec: buildiq-app-detail-overview / application-insights
 * (REQ-OBAI-001, REQ-OBAI-002, REQ-OBAI-006).
 */
import { describe, expect, it, vi } from 'vitest'
import { nextTick, ref } from 'vue'

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(),
	},
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path) => path,
}))

import axios from '@nextcloud/axios'
import { useApplicationInsights } from '../../src/composables/useApplicationInsights.js'

const BODIES = [
	{
		kpis: {
			activeUsers: 5,
			objectCount: 10,
			filesCount: 3,
			auditEventCount: 50,
		},
		activity: [{ eventCount: 1 }],
	},
	{ kpis: { activeUsers: 2, extra: 'x' } },
	{ kpis: null, activity: 'none' },
	{ kpis: 'many', activity: [] },
	{ activity: [{ eventCount: 2 }] },
	{},
	null,
	'text',
	7,
]

const FAILURES = [
	{ response: { status: 404 } },
	{ response: { status: 500 } },
	{ response: {} },
	null,
	'boom',
]

const INPUTS = [
	['app', 'version', '30d'],
	['app', 'version', ''],
	['a/b', 'v 1', undefined],
	['', 'version', '7d'],
	['app', '', '7d'],
]

/**
 * The exposed state of the composable, as text.
 *
 * @param {object} insights The composable's return value.
 *
 * @return {string} The state.
 */
function stateOf(insights) {
	const error = insights.error.value
	return JSON.stringify({
		kpis: insights.kpis.value,
		activity: insights.activity.value,
		loading: insights.loading.value,
		error: error === null ? null : `${error.constructor.name}: ${error.message}`,
		gone: insights.versionNoLongerAccessible.value,
	})
}

/**
 * Run one case: create the composable on a first outcome, then refresh on a
 * second one, and describe the calls and the state after each step.
 *
 * @param {Array} input The app uuid, version uuid and window.
 * @param {Array<object>} outcomes Two `{ body }` or `{ failure }` outcomes.
 *
 * @return {Promise<string>} The description.
 */
async function runCase(input, outcomes) {
	axios.get.mockReset()
	for (const outcome of outcomes) {
		if ('failure' in outcome) {
			axios.get.mockRejectedValueOnce(
				outcome.failure instanceof Object
					? Object.assign(new Error('request failed'), outcome.failure)
					: outcome.failure,
			)
		} else {
			axios.get.mockResolvedValueOnce({ data: outcome.body })
		}
	}
	const insights = useApplicationInsights(...input)
	await new Promise((resolve) => setTimeout(resolve, 0))
	const first = stateOf(insights)
	await insights.refresh()
	const second = stateOf(insights)
	return `${JSON.stringify(input)} ${JSON.stringify(outcomes)} calls=${JSON.stringify(axios.get.mock.calls)} first=${first} second=${second}`
}

describe('useApplicationInsights golden', () => {
	it('pins the state after every response and failure', async () => {
		const outcomes = [
			...BODIES.map((body) => ({ body })),
			...FAILURES.map((failure) => ({ failure })),
		]
		const lines = []
		for (const input of INPUTS) {
			for (let i = 0; i < outcomes.length; i++) {
				lines.push(
					await runCase(input, [
						outcomes[i],
						outcomes[(i * 5 + 3) % outcomes.length],
					]),
				)
			}
		}
		expect(lines.join('\n')).toMatchSnapshot()
	})
	it('pins which inputs a change re-fetches on', async () => {
		const shapes = [
			[ref('app'), ref('version'), ref('7d')],
			['app', ref('version'), '7d'],
			['app', 'version', ref('30d')],
			[ref('app'), 'version', '7d'],
			['app', 'version', '7d'],
			[{ value: 'app' }, { value: 'version' }, { value: '7d' }],
		]
		const lines = []
		for (const input of shapes) {
			axios.get.mockReset()
			axios.get.mockResolvedValue({ data: {} })
			useApplicationInsights(...input)
			await nextTick()
			for (const holder of input) {
				if (holder && typeof holder === 'object') {
					holder.value = `${holder.value}-next`
				}
			}
			await nextTick()
			await new Promise((resolve) => setTimeout(resolve, 260))
			lines.push(
				`${input.map((h) => (h && typeof h === 'object' ? `holder:${h.value}` : h)).join(',')} calls=${JSON.stringify(axios.get.mock.calls)}`,
			)
		}
		expect(lines.join('\n')).toMatchSnapshot()
	})
})
