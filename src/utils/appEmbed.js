// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// Embedding a built app in another site its staff use
// (apps-embed-in-other-sites): the origin check the settings modal runs
// before a save, the iframe snippet for the whole app or one page, and the
// runtime's embed mode (no app navigation, a sign-in link instead of a login
// form). The server checks origins again (EmbedOrigins) and the register
// declares the same pattern, so this check is for the message, not the gate.

import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/**
 * An https origin: scheme, host labels and an optional port. The same
 * pattern as `EmbedOrigins::PATTERN` and the register's
 * `Application.embed.origins` items.
 */
export const ORIGIN_PATTERN = /^https:\/\/[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*(?::[0-9]{1,5})?$/

/**
 * Why an origin cannot be saved, or '' when it can.
 *
 * @param {string} origin The site as the maker typed it.
 * @return {string} The reason, translated.
 *
 * @spec openspec/changes/archive/2026-11-24-apps-embed-in-other-sites/specs/app-embedding/spec.md#requirement-a-maker-switches-embedding-on-and-lists-the-allowed-sites-req-bqem-001
 */
export function originProblem(origin) {
	const value = String(origin ?? '').trim()
	if (value.includes('*')) {
		return t('buildiq', 'A wildcard is not allowed: list each site separately.')
	}
	if (!value.startsWith('https://')) {
		return t('buildiq', 'A site must start with https://.')
	}
	if (/[/?#]/.test(value.slice(8))) {
		return t('buildiq', 'A site is a scheme and a host only, without a path.')
	}
	if (!ORIGIN_PATTERN.test(value)) {
		return t('buildiq', 'This is not a valid site address.')
	}
	return ''
}

/**
 * The embed setting as the register stores it: off with no sites unless set.
 *
 * @param {object|null|undefined} embed The stored setting.
 * @return {{enabled: boolean, origins: string[]}}
 *
 * @spec openspec/changes/archive/2026-11-24-apps-embed-in-other-sites/specs/app-embedding/spec.md#requirement-a-maker-switches-embedding-on-and-lists-the-allowed-sites-req-bqem-001
 */
export function normaliseEmbed(embed) {
	const origins = Array.isArray(embed?.origins) ? embed.origins.filter((origin) => typeof origin === 'string') : []
	return { enabled: embed?.enabled === true, origins }
}

/**
 * Escape a value for an HTML attribute.
 *
 * @param {string} value The value.
 * @return {string}
 */
function attribute(value) {
	return String(value)
		.replace(/&/g, '&amp;')
		.replace(/"/g, '&quot;')
		.replace(/</g, '&lt;')
		.replace(/>/g, '&gt;')
}

/**
 * The embed address of the app, or of one of its pages.
 *
 * @param {object} options The app and the page.
 * @param {string} options.origin The Nextcloud origin, e.g. `https://cloud.example.nl`.
 * @param {string} options.slug The app slug.
 * @param {string} [options.pageRoute] The page's route inside the app, e.g. `/book`.
 * @return {string}
 *
 * @spec openspec/changes/archive/2026-11-24-apps-embed-in-other-sites/specs/app-embedding/spec.md#requirement-a-maker-embeds-one-page-req-bqem-004
 */
export function embedUrl({ origin, slug, pageRoute = '' }) {
	const page = String(pageRoute || '').replace(/^\/+/, '')
	const base = `${String(origin || '').replace(/\/+$/, '')}${generateUrl(`/apps/buildiq/builder/${encodeURIComponent(slug)}`)}`
	return `${page ? `${base}/${page}` : base}?embed=1`
}

/**
 * The iframe snippet a maker copies onto the other site.
 *
 * @param {object} options The app and the page.
 * @param {string} options.origin The Nextcloud origin.
 * @param {string} options.slug The app slug.
 * @param {string} options.title The frame's title, for screen readers.
 * @param {string} [options.pageRoute] The page's route inside the app.
 * @return {string}
 *
 * @spec openspec/changes/archive/2026-11-24-apps-embed-in-other-sites/specs/app-embedding/spec.md#requirement-a-maker-embeds-one-page-req-bqem-004
 */
export function embedSnippet({ origin, slug, title, pageRoute = '' }) {
	const src = embedUrl({ origin, slug, pageRoute })
	return `<iframe src="${attribute(src)}" title="${attribute(title || slug)}" width="100%" height="600" style="border: 0"></iframe>`
}

/**
 * The shell's slots in embed mode: the app navigation is left out, so an
 * embedded page shows only that page. Outside embed mode the slots are
 * handed back as they are.
 *
 * The `menu` slot is dropped rather than replaced: CnAppRoot falls back to
 * its own navigation for an empty slot, and a slot answering null broke the
 * whole embedded page. The shell hides the navigation with `hideMenu`
 * (see embedShellProps()).
 *
 * @param {boolean} embedded Whether the runtime is embedded.
 * @param {object} slots The shell's slots outside embed mode.
 * @return {object}
 *
 * @spec openspec/changes/archive/2026-11-24-apps-embed-in-other-sites/specs/app-embedding/spec.md#requirement-a-maker-embeds-one-page-req-bqem-004
 */
export function embedSlots(embedded, slots) {
	if (!embedded) {
		return slots
	}
	const { menu, ...rest } = slots // eslint-disable-line no-unused-vars
	return rest
}

/**
 * The shell props embed mode adds: no app navigation at all, so the page
 * starts at the frame's left edge.
 *
 * @param {boolean} embedded Whether the runtime is embedded.
 * @return {object} `{ hideMenu: true }` when embedded, else nothing.
 *
 * @spec openspec/changes/archive/2026-11-24-apps-embed-in-other-sites/specs/app-embedding/spec.md#requirement-a-maker-embeds-one-page-req-bqem-004
 */
export function embedShellProps(embedded) {
	return embedded ? { hideMenu: true } : {}
}

/**
 * Give an embedded page the skip-link target Nextcloud's header carries.
 *
 * An embedded app is served in the bare layout, without the header, so
 * `#skip-actions` is missing; the shell's NcContent teleports its skip links
 * there, and without a target the whole page failed to mount. It is added to
 * the body, outside `#content`, because the app mounts on `#content` and
 * would replace anything inside it.
 *
 * @param {boolean} embedded Whether the runtime is embedded.
 * @param {Document} doc The document.
 * @return {boolean} Whether a target was added.
 *
 * @spec openspec/changes/archive/2026-11-24-apps-embed-in-other-sites/specs/app-embedding/spec.md#requirement-a-maker-embeds-one-page-req-bqem-004
 */
export function ensureEmbedSkipTarget(embedded, doc) {
	if (!embedded || !doc?.body || doc.getElementById('skip-actions')) {
		return false
	}
	const target = doc.createElement('div')
	target.id = 'skip-actions'
	doc.body.prepend(target)
	return true
}

/**
 * Whether an embedded runtime has lost its session: the manifest answered
 * 401 or 403 because the frame carried no Nextcloud session.
 *
 * @param {boolean} embedded Whether the runtime is embedded.
 * @param {unknown} error What the manifest request threw.
 * @return {boolean}
 *
 * @spec openspec/changes/archive/2026-11-24-apps-embed-in-other-sites/specs/app-embedding/spec.md#requirement-the-frame-never-asks-for-a-password-req-bqem-003
 */
export function needsSignIn(embedded, error) {
	const status = error?.response?.status
	return embedded === true && (status === 401 || status === 403)
}

/**
 * The notice an embedded app shows without a session: a sign-in link that
 * opens Nextcloud in a new tab, never a login form inside the frame.
 *
 * @param {string} appUrl The app's own address, to come back to after signing in.
 * @return {{text: string, link: string, href: string, target: string, rel: string}}
 *
 * @spec openspec/changes/archive/2026-11-24-apps-embed-in-other-sites/specs/app-embedding/spec.md#requirement-the-frame-never-asks-for-a-password-req-bqem-003
 */
export function signInNotice(appUrl) {
	return {
		text: t('buildiq', 'Sign in to use this app'),
		link: t('buildiq', 'Sign in to Nextcloud (opens in a new tab)'),
		href: `${generateUrl('/login')}?redirect_url=${encodeURIComponent(appUrl)}`,
		target: '_blank',
		rel: 'noopener noreferrer',
	}
}
