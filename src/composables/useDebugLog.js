// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { reactive } from 'vue'

/** The most entries the log keeps; the oldest goes first. */
const LIMIT = 200

/**
 * Requests to buildiq's own API are the designer's, not the previewed app's:
 * nextcloud-vue's diagnostics channel hears every request in the window.
 */
const DESIGNER_PATH = /\/apps\/buildiq\//

/**
 * What a diagnostics report is about, as one log entry, or null when it is no
 * problem: a request that succeeded, or a request the designer made.
 *
 * @param {object} report A nextcloud-vue diagnostics report.
 * @return {object|null} `{key, kind, pageId, what, request, status, ...}` or null.
 * @spec openspec/changes/operate-debug-log-and-monitoring/specs/builder-debug-log/spec.md#requirement-the-designer-logs-the-preview-s-requests-and-errors-req-bqdm-001
 */
export function entryOf(report) {
	if (!report || typeof report !== 'object') {
		return null
	}
	const pageId = report.pageId ? String(report.pageId) : ''
	if (report.kind === 'request') {
		const status = Number(report.status) || 0
		const path = String(report.path || '')
		if ((status > 0 && status < 400) || DESIGNER_PATH.test(path)) {
			return null
		}
		const request = `${String(report.method || 'GET').toUpperCase()} ${path}`
		return { key: `request|${pageId}|${request}|${status}`, kind: 'request', pageId, request, status, durationMs: Number(report.durationMs) || 0 }
	}
	if (report.kind === 'render-error') {
		const what = String(report.message || '')
		return { key: `render-error|${pageId}|${report.component}|${what}`, kind: 'render-error', pageId, component: String(report.component || ''), what }
	}
	if (report.kind === 'unknown-component') {
		const name = String(report.name || report.component || '')
		return { key: `unknown-component|${pageId}|${name}`, kind: 'unknown-component', pageId, component: name, what: name }
	}
	if (report.kind === 'binding') {
		const parts = [report.register, report.schema].filter(Boolean).join('/')
		const what = String(report.problem || '')
		return { key: `binding|${pageId}|${parts}|${report.property}|${what}`, kind: 'binding', pageId, problem: what, register: String(report.register || ''), schema: String(report.schema || ''), property: report.property ? String(report.property) : '', where: report.where ? String(report.where) : '', what }
	}
	return null
}

/**
 * The designer's debug log: the preview's failed requests, render errors,
 * unknown components and binding problems, the same problem counted once with
 * how often and when it last happened. It stays in this browser tab.
 *
 * @param {object} [options] Options.
 * @param {() => number} [options.now] The clock.
 * @return {{entries: Array<object>, record: (report: object) => void, clear: () => void}} The log.
 * @spec openspec/changes/operate-debug-log-and-monitoring/specs/builder-debug-log/spec.md#requirement-the-log-stays-in-the-maker-s-browser-req-bqdm-003
 */
export function useDebugLog({ now = () => Date.now() } = {}) {
	const entries = reactive([])

	/**
	 * Add a diagnostics report to the log.
	 *
	 * @param {object} report A nextcloud-vue diagnostics report.
	 * @return {void}
	 * @spec openspec/changes/operate-debug-log-and-monitoring/specs/builder-debug-log/spec.md#requirement-the-designer-logs-the-preview-s-requests-and-errors-req-bqdm-001
	 */
	function record(report) {
		const entry = entryOf(report)
		if (!entry) {
			return
		}
		const at = Number(report.at) || now()
		const index = entries.findIndex((e) => e.key === entry.key)
		if (index !== -1) {
			const [existing] = entries.splice(index, 1)
			entries.unshift({ ...existing, count: existing.count + 1, lastAt: at })
			return
		}
		entries.unshift({ ...entry, count: 1, lastAt: at })
		if (entries.length > LIMIT) {
			entries.splice(LIMIT)
		}
	}

	/**
	 * Empty the log.
	 *
	 * @return {void}
	 * @spec openspec/changes/operate-debug-log-and-monitoring/specs/builder-debug-log/spec.md#requirement-the-log-stays-in-the-maker-s-browser-req-bqdm-003
	 */
	function clear() {
		entries.splice(0)
	}

	return { entries, record, clear }
}
