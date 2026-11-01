/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * REQ-BQDM-004: a running app folds diagnostics into per-page counts and
 * posts counts only.
 *
 * @spec openspec/changes/operate-debug-log-and-monitoring/specs/built-app-health-monitoring/spec.md#requirement-a-running-app-reports-counts-not-content-req-bqdm-004
 */
import { describe, expect, it, vi } from 'vitest'
import { createHealthReporter } from '../../src/services/healthReporter.js'

/**
 * A reporter on a hand-moved clock.
 *
 * @return {{reporter: object, post: Function, clock: {t: number}}} The parts.
 */
function setup() {
	const clock = { t: 0 }
	const post = vi.fn()
	return { reporter: createHealthReporter({ post, now: () => clock.t }), post, clock }
}

describe('healthReporter', () => {
	it('folds three visits with one failing request into one sample for the page', () => {
		const { reporter, post, clock } = setup()
		for (const status of [200, 500, 200]) {
			reporter.navigate('permits')
			clock.t += 400
			reporter.listener({ kind: 'request', pageId: 'permits', status, durationMs: 400, path: '/objects/x?_limit', method: 'GET' })
			clock.t += 5000
		}
		clock.t += 20000
		reporter.flush()

		expect(post).toHaveBeenCalledTimes(1)
		const [sample] = post.mock.calls[0][0]
		expect(sample).toMatchObject({ pageId: 'permits', requests: 3, failures5xx: 1, failures4xx: 0, durationUnder1000: 3, loads: 3, loadMsSum: 1200, slowLoads: 0 })
		expect(Object.keys(sample)).not.toContain('path')
		expect(JSON.stringify(post.mock.calls)).not.toContain('/objects/')
	})

	it('counts a slow page load and a render error', () => {
		const { reporter, post, clock } = setup()
		reporter.navigate('permit-detail')
		clock.t += 3500
		reporter.listener({ kind: 'request', pageId: 'permit-detail', status: 404, durationMs: 3400 })
		reporter.listener({ kind: 'render-error', pageId: 'permit-detail', message: 'boom' })
		reporter.navigate('permits')
		reporter.flush()

		const sample = post.mock.calls[0][0].find((s) => s.pageId === 'permit-detail')
		expect(sample).toMatchObject({ loads: 1, loadMsSum: 3500, slowLoads: 1, failures4xx: 1, durationOver3000: 1, clientErrors: 1 })
		expect(JSON.stringify(sample)).not.toContain('boom')
	})

	it('posts nothing when there is nothing to report, and starts over after a flush', () => {
		const { reporter, post } = setup()
		expect(reporter.flush()).toBeUndefined()
		reporter.listener({ kind: 'request', pageId: 'a', status: 200, durationMs: 10 })
		reporter.flush()
		reporter.flush()
		expect(post).toHaveBeenCalledTimes(1)
	})
})
