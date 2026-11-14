/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * startHealthReporting wires the health reporter of a built app: it follows
 * router navigation, posts the samples of the current version every five
 * minutes and when the tab is hidden, and never lets a failed post throw.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/auth', () => ({ getRequestToken: vi.fn(() => 'tok') }))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))
const reporter = { navigate: vi.fn(), flush: vi.fn(), listener: vi.fn() }
const createHealthReporter = vi.fn(() => reporter)
vi.mock('../../src/services/healthReporter.js', () => ({
	createHealthReporter: (...a) => createHealthReporter(...a),
}))

const { getRequestToken } = await import('@nextcloud/auth')
const { startHealthReporting } = await import('../../src/services/healthReporting.js')

function start(opts = {}) {
	const router = { afterEach: vi.fn() }
	const listener = startHealthReporting({ router, slug: 'my app', ...opts })
	return { router, listener, post: createHealthReporter.mock.calls.at(-1)[0].post }
}

function setVisibility(state) {
	Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => state })
}

describe('startHealthReporting', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		createHealthReporter.mockClear()
		for (const fn of Object.values(reporter)) fn.mockClear()
		globalThis.fetch = vi.fn().mockResolvedValue({ ok: true })
	})

	afterEach(() => {
		vi.useRealTimers()
		setVisibility('visible')
	})

	it('returns the reporter listener', () => {
		expect(start().listener).toBe(reporter.listener)
	})

	it('posts the pages as JSON with keepalive and the request token to the app endpoint', async () => {
		const { post } = start()
		await post({ home: { loads: 1 } })

		expect(fetch).toHaveBeenCalledWith('/apps/buildiq/api/applications/my%20app/health-samples', {
			method: 'POST',
			keepalive: true,
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', requesttoken: 'tok' },
			body: JSON.stringify({ pages: { home: { loads: 1 } } }),
		})
	})

	it('addresses the version being viewed', async () => {
		const { post } = start({ versionSlug: 'staging 2' })
		await post({})
		expect(fetch.mock.calls[0][0]).toBe('/apps/buildiq/api/applications/my%20app/health-samples?_version=staging%202')
	})

	it('sends an empty token when there is none', async () => {
		getRequestToken.mockReturnValueOnce(null)
		const { post } = start()
		await post({})
		expect(fetch.mock.calls[0][1].headers.requesttoken).toBe('')
	})

	it('swallows a failed post', async () => {
		fetch.mockRejectedValueOnce(new Error('offline'))
		const { post } = start()
		await expect(post({})).resolves.toBeUndefined()
	})

	it('reports every navigation by route name', () => {
		const { router } = start()
		const hook = router.afterEach.mock.calls[0][0]
		hook({ name: 'Dashboard' })
		expect(reporter.navigate).toHaveBeenCalledWith('Dashboard')
	})

	it('flushes every five minutes', () => {
		start()
		vi.advanceTimersByTime(5 * 60 * 1000 - 1)
		expect(reporter.flush).not.toHaveBeenCalled()
		vi.advanceTimersByTime(1)
		expect(reporter.flush).toHaveBeenCalledTimes(1)
	})

	it('flushes when the tab is hidden, not when it becomes visible', () => {
		const add = vi.spyOn(document, 'addEventListener')
		start()
		const [event, handler] = add.mock.calls.at(-1)
		add.mockRestore()
		expect(event).toBe('visibilitychange')

		setVisibility('visible')
		handler()
		expect(reporter.flush).not.toHaveBeenCalled()

		setVisibility('hidden')
		reporter.flush.mockClear()
		handler()
		expect(reporter.flush).toHaveBeenCalledTimes(1)
	})
})
