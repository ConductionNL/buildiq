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
 * A failed request of the previewed app as a log entry, or null when it
 * succeeded or the designer made it.
 *
 * @param {object} report A diagnostics report of kind `request`.
 * @param {string} pageId The page the report is about.
 * @return {object|null} The entry.
 */
function requestEntry(report, pageId) {
	const status = Number(report.status) || 0
	const path = String(report.path || '')
	if (isQuietRequest(status, path)) {
		return null
	}
	return failedRequestEntry(report, {
		pageId,
		request: `${requestMethod(report)} ${path}`,
		status,
	})
}

/**
 * The log entry of a failed request.
 *
 * @param {object} report A diagnostics report of kind `request`.
 * @param {{pageId: string, request: string, status: number}} failure The page, the `METHOD path` line and the status.
 * @return {object} The entry.
 */
function failedRequestEntry(report, failure) {
	return {
		key: `request|${failure.pageId}|${failure.request}|${failure.status}`,
		kind: 'request',
		pageId: failure.pageId,
		request: failure.request,
		status: failure.status,
		durationMs: Number(report.durationMs) || 0,
	}
}

/**
 * Whether a request is no problem of the previewed app: it succeeded, or the
 * designer made it.
 *
 * @param {number} status The response status (0 when there was none).
 * @param {string} path The request path.
 * @return {boolean}
 */
function isQuietRequest(status, path) {
	return (status > 0 && status < 400) || DESIGNER_PATH.test(path)
}

/**
 * The request method, upper-cased; GET when the report names none.
 *
 * @param {object} report A diagnostics report of kind `request`.
 * @return {string}
 */
function requestMethod(report) {
	return String(report.method || 'GET').toUpperCase()
}

/**
 * A render error as a log entry.
 *
 * @param {object} report A diagnostics report of kind `render-error`.
 * @param {string} pageId The page the report is about.
 * @return {object} The entry.
 */
function renderErrorEntry(report, pageId) {
	const what = String(report.message || '')
	return {
		key: `render-error|${pageId}|${report.component}|${what}`,
		kind: 'render-error',
		pageId,
		component: String(report.component || ''),
		what,
	}
}

/**
 * An unknown component as a log entry.
 *
 * @param {object} report A diagnostics report of kind `unknown-component`.
 * @param {string} pageId The page the report is about.
 * @return {object} The entry.
 */
function unknownComponentEntry(report, pageId) {
	const name = String(report.name || report.component || '')
	return {
		key: `unknown-component|${pageId}|${name}`,
		kind: 'unknown-component',
		pageId,
		component: name,
		what: name,
	}
}

/**
 * A binding problem as a log entry.
 *
 * @param {object} report A diagnostics report of kind `binding`.
 * @param {string} pageId The page the report is about.
 * @return {object} The entry.
 */
function bindingEntry(report, pageId) {
	const parts = [report.register, report.schema].filter(Boolean).join('/')
	const what = String(report.problem || '')
	return {
		key: `binding|${pageId}|${parts}|${report.property}|${what}`,
		kind: 'binding',
		pageId,
		problem: what,
		register: String(report.register || ''),
		schema: String(report.schema || ''),
		property: optionalText(report.property),
		where: optionalText(report.where),
		what,
	}
}

/**
 * A report value as text, or '' when it is not set.
 *
 * @param {unknown} value A report value.
 * @return {string}
 */
function optionalText(value) {
	return value ? String(value) : ''
}

/** The entry builder of each report kind the log keeps. */
const ENTRY_BUILDERS = Object.freeze({
	request: requestEntry,
	'render-error': renderErrorEntry,
	'unknown-component': unknownComponentEntry,
	binding: bindingEntry,
})

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
	if (!isLoggedKind(report.kind)) {
		return null
	}
	return ENTRY_BUILDERS[report.kind](report, pageId)
}

/**
 * Whether the log keeps reports of a kind.
 *
 * @param {unknown} kind The report kind.
 * @return {boolean}
 */
function isLoggedKind(kind) {
	return typeof kind === 'string' && Object.hasOwn(ENTRY_BUILDERS, kind)
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
