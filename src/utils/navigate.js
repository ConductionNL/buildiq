// SPDX-License-Identifier: EUPL-1.2
/**
 * Full-page navigation, behind a module so tests can replace it (jsdom's
 * `window.location` cannot be stubbed).
 *
 * @module utils/navigate
 */

/**
 * Leave the current page for `url`.
 *
 * @param {string} url - the URL to open.
 * @return {void}
 * @spec exclude thin wrapper over window.location so tests can replace it
 */
export function navigateTo(url) {
	window.location.assign(url)
}
