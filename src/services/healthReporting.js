// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { getRequestToken } from '@nextcloud/auth'
import { generateUrl } from '@nextcloud/router'
import { createHealthReporter } from './healthReporter.js'

/** How often a running app sends its counts. */
const FLUSH_MS = 5 * 60 * 1000

/**
 * Send health samples for a running built app: every five minutes and when
 * the page is hidden. A `keepalive` fetch, so the last batch still leaves
 * while the tab closes.
 *
 * @param {object} options Options.
 * @param {object} options.router The app's router; each navigation starts a page view.
 * @param {string} options.slug The app slug.
 * @param {string} [options.versionSlug] The version, empty for production.
 * @return {(report: object) => void} The diagnostics listener to hand to CnAppRoot.
 * @spec openspec/changes/operate-debug-log-and-monitoring/specs/built-app-health-monitoring/spec.md#requirement-a-running-app-reports-counts-not-content-req-bqdm-004
 */
export function startHealthReporting({ router, slug, versionSlug = '' }) {
	let url = generateUrl(`/apps/buildiq/api/applications/${encodeURIComponent(slug)}/health-samples`)
	if (versionSlug) {
		url += `?_version=${encodeURIComponent(versionSlug)}`
	}

	const reporter = createHealthReporter({
		post: (pages) => fetch(url, {
			method: 'POST',
			keepalive: true,
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', requesttoken: getRequestToken() || '' },
			body: JSON.stringify({ pages }),
		}).catch(() => {}),
	})

	router.afterEach((to) => reporter.navigate(to.name))
	setInterval(() => reporter.flush(), FLUSH_MS)
	document.addEventListener('visibilitychange', () => {
		if (document.visibilityState === 'hidden') {
			reporter.flush()
		}
	})

	return reporter.listener
}
