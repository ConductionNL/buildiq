// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Health reporting for a running built app.
 *
 * Folds the nextcloud-vue diagnostics reports into per-page counters and
 * hands them to `post` on each flush. It keeps counts only: no path, no body,
 * no message, no user. A page load is the time from navigating to a page until
 * the last request it made within its first ten seconds came back.
 *
 * @spec openspec/changes/operate-debug-log-and-monitoring/specs/built-app-health-monitoring/spec.md#requirement-a-running-app-reports-counts-not-content-req-bqdm-004
 */

/** Requests later than this after navigating do not count towards the page load. */
const LOAD_WINDOW_MS = 10000

/** A page load of this length or more counts as slow. */
const SLOW_LOAD_MS = 3000

const COUNTS = [
	'requests',
	'failures4xx',
	'failures5xx',
	'durationUnder250',
	'durationUnder1000',
	'durationUnder3000',
	'durationOver3000',
	'loads',
	'loadMsSum',
	'slowLoads',
	'clientErrors',
]

/**
 * The bucket a request duration falls in.
 *
 * @param {number} ms The duration.
 * @return {string} The count it adds to.
 */
function durationBucket(ms) {
	if (ms < 250) {
		return 'durationUnder250'
	}
	if (ms < 1000) {
		return 'durationUnder1000'
	}
	if (ms < 3000) {
		return 'durationUnder3000'
	}
	return 'durationOver3000'
}

/**
 * Create a reporter.
 *
 * @param {object} options Options.
 * @param {(pages: Array<object>) => Promise<unknown>|unknown} options.post Sends one batch of page samples.
 * @param {() => number} [options.now] The clock.
 * @return {{listener: (report: object) => void, navigate: (pageId: string) => void, flush: () => (Promise<unknown>|undefined), pending: () => Array<object>}} The reporter.
 * @spec openspec/changes/operate-debug-log-and-monitoring/specs/built-app-health-monitoring/spec.md#requirement-a-running-app-reports-counts-not-content-req-bqdm-004
 */
export function createHealthReporter({ post, now = () => Date.now() }) {
	let pages = new Map()
	let view = null

	const countsOf = (pageId) => {
		const key = String(pageId || 'unknown')
		if (!pages.has(key)) {
			pages.set(key, Object.fromEntries(COUNTS.map((count) => [count, 0])))
		}
		return pages.get(key)
	}

	const closeLoad = () => {
		if (!view || view.counted) {
			return
		}
		const counts = countsOf(view.pageId)
		const ms = Math.max(0, Math.round(view.loadEnd - view.start))
		counts.loads += 1
		counts.loadMsSum += ms
		if (ms >= SLOW_LOAD_MS) {
			counts.slowLoads += 1
		}
		view.counted = true
	}

	return {
		/**
		 * The diagnostics listener for CnAppRoot.
		 *
		 * @spec openspec/changes/operate-debug-log-and-monitoring/specs/built-app-health-monitoring/spec.md#requirement-a-running-app-reports-counts-not-content-req-bqdm-004
		 * @param {object} report A diagnostics report.
		 * @return {void}
		 */
		listener(report) {
			if (!report || typeof report !== 'object') {
				return
			}
			const pageId = report.pageId || view?.pageId
			if (report.kind === 'request') {
				const counts = countsOf(pageId)
				const status = Number(report.status) || 0
				counts.requests += 1
				if (status >= 400 && status < 500) {
					counts.failures4xx += 1
				} else if (status >= 500 || status === 0) {
					counts.failures5xx += 1
				}
				counts[durationBucket(Number(report.durationMs) || 0)] += 1
				const at = now()
				if (view && !view.counted && view.pageId === pageId && at - view.start <= LOAD_WINDOW_MS) {
					view.loadEnd = at
				}
				return
			}
			if (report.kind === 'render-error' || report.kind === 'unknown-component') {
				countsOf(pageId).clientErrors += 1
			}
		},

		/**
		 * Start a page view: the previous one's load is counted.
		 *
		 * @spec openspec/changes/operate-debug-log-and-monitoring/specs/built-app-health-monitoring/spec.md#requirement-a-running-app-reports-counts-not-content-req-bqdm-004
		 * @param {string} pageId The page navigated to.
		 * @return {void}
		 */
		navigate(pageId) {
			closeLoad()
			const at = now()
			view = { pageId: String(pageId || 'unknown'), start: at, loadEnd: at, counted: false }
		},

		/**
		 * Send what was counted since the last flush, if anything, and start over.
		 *
		 * @spec openspec/changes/operate-debug-log-and-monitoring/specs/built-app-health-monitoring/spec.md#requirement-a-running-app-reports-counts-not-content-req-bqdm-004
		 * @return {Promise<unknown>|undefined} The post, or undefined when there was nothing.
		 */
		flush() {
			if (view && now() - view.start > LOAD_WINDOW_MS) {
				closeLoad()
			}
			const batch = [...pages.entries()]
				.map(([pageId, counts]) => ({ pageId, ...counts }))
				.filter((sample) => COUNTS.some((count) => sample[count] > 0))
			pages = new Map()
			if (batch.length === 0) {
				return undefined
			}
			return post(batch)
		},

		/**
		 * What would be sent now, for tests and debugging.
		 *
		 * @spec openspec/changes/operate-debug-log-and-monitoring/specs/built-app-health-monitoring/spec.md#requirement-a-running-app-reports-counts-not-content-req-bqdm-004
		 * @return {Array<object>} The samples.
		 */
		pending() {
			return [...pages.entries()].map(([pageId, counts]) => ({ pageId, ...counts }))
		},
	}
}
