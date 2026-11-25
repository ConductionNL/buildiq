// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// The router base of a built app's runtime (/apps/buildiq/builder/{slug}).
// Nextcloud serves the same page at /index.php/apps/buildiq/builder/{slug}/...
// and, with pretty URLs on, at /apps/buildiq/builder/{slug}/... . A base taken
// from generateUrl() names only one of the two, so a deep link in the other
// form opened the app's first page instead of the page it names. The base is
// taken from the address the page was served at, as src/main.js does for
// Buildiq's own router.

import { generateUrl } from '@nextcloud/router'

/**
 * Escape a string for use inside a regular expression.
 *
 * @param {string} value The text.
 * @return {string} The text with its special characters escaped.
 */
function escapeForPattern(value) {
	return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

/**
 * The router base for a built app: the part of the served path up to and
 * including /apps/buildiq/builder/{slug}, or generateUrl()'s form when the
 * path does not carry it.
 *
 * @param {string} pathname The served path, window.location.pathname.
 * @param {string} slug The app's slug.
 * @return {string} The base, without a trailing slash.
 *
 * @spec openspec/changes/openbuild-connector-widget-runtime/tasks.md#5.3
 */
export function builderRouterBase(pathname, slug) {
	const fallback = generateUrl(`/apps/buildiq/builder/${slug}`)
	if (!slug) {
		return fallback
	}
	const segment = escapeForPattern(`/apps/buildiq/builder/${slug}`)
	const encoded = escapeForPattern(`/apps/buildiq/builder/${encodeURIComponent(slug)}`)
	const match = String(pathname ?? '').match(new RegExp(`^(.*?(?:${segment}|${encoded}))(?:/|$)`))
	return match ? match[1] : fallback
}
