// SPDX-License-Identifier: EUPL-1.2
/**
 * userOverrides — the urls of the per-user override layer of an app
 * (`/api/app-overrides/{appId}/user` for the caller's own delta,
 * `/user-deltas` for every user's, maintainer-only) and the state the
 * detail surfaces keep of the caller's own delta.
 *
 * The server decides who may read or write; these helpers only build the
 * urls and read the answer.
 *
 * @spec openspec/specs/application-detail-ui/spec.md
 */

import { generateUrl } from '@nextcloud/router'

/**
 * @param {string} appSlug - the app slug.
 * @return {string} the url of the caller's own user delta.
 */
export function userOverrideUrl(appSlug) {
	return generateUrl('/apps/buildiq/api/app-overrides/{appId}/user', {
		appId: appSlug,
	})
}

/**
 * @param {string} appSlug - the app slug.
 * @return {string} the url of every user's delta (maintainer-only).
 */
export function userOverridesUrl(appSlug) {
	return generateUrl('/apps/buildiq/api/app-overrides/{appId}/user-deltas', {
		appId: appSlug,
	})
}

/**
 * The caller's user-delta state out of an answer of the user endpoint;
 * with no answer, the state of a caller without a personal layer.
 *
 * @param {object|null} data - the response body.
 * @return {{allowed: boolean, exists: boolean, versionUuid: (string|null)}} the state.
 */
export function userDeltaState(data) {
	return {
		allowed: !!(data && data.allowed),
		exists: !!(data && data.exists),
		versionUuid: (data && data.versionUuid) || null,
	}
}
