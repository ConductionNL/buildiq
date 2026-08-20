// SPDX-License-Identifier: EUPL-1.2
/**
 * useTrackLinkAction — owner-context "mint a track-link" action
 * (REQ-EFP-006). Thin wrapper around OpenRegister's generic Tier-2
 * integration route `POST /api/objects/{register}/{schema}/{id}/
 * integrations/shares` (`SharesProvider::create()`), which itself calls
 * `CaseTokenService::mint()` — a write that structurally requires an
 * authenticated `IUserSession::getUser()` (design.md Decision 4). This
 * composable is therefore only ever invoked from an authenticated
 * OpenBuild session viewing an object the staff member already has access
 * to (a data-register object list/detail view) — never anonymously, and
 * never as an automatic side effect of an object being created (OQ-1 stays
 * deliberately unresolved; see `src/components/runtime/TrackLinkAction.vue`
 * for the registrable UI that gates this on
 * `runtime.externalForms[].trackLinkAction.enabled`).
 *
 * @spec openspec/changes/external-form-provisioning/specs/external-form-provisioning/spec.md#req-efp-006
 */
import defaultAxios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Resolve the public "track your case" URL from a shares-provider mint
 * response. Prefers an explicit `url`; falls back to composing the public
 * case-token resolve endpoint from `token` (`GET /api/public/case-tokens/
 * {token}`, `CaseTokenController::resolve()`).
 *
 * @param {object} data - the mint response `{token, url?}`.
 * @return {string}
 */
function resolvePublicUrl(data) {
	if (data && typeof data.url === 'string' && data.url) {
		return data.url
	}
	const token = data && data.token
	return token
		? generateUrl(
				`/apps/openregister/api/public/case-tokens/${encodeURIComponent(token)}`,
			)
		: ''
}

/**
 * Owner-context track-link minting for one object.
 *
 * @param {object} [client] - axios-like client (test injection).
 * @return {{mintTrackLink: Function}}
 * @spec openspec/changes/external-form-provisioning/specs/external-form-provisioning/spec.md#req-efp-006
 */
export function useTrackLinkAction(client = defaultAxios) {
	/**
	 * Mint a "track your case" token for an already-created object and
	 * resolve its public URL for the staff member to copy/relay.
	 *
	 * @param {string} register - the OR register slug.
	 * @param {string} schema - the OR schema slug.
	 * @param {string} objectId - the object's uuid.
	 * @param {object} [opts] - options.
	 * @param {string} [opts.label] - optional human label for the token.
	 * @param {number} [opts.ttlSeconds] - optional token lifetime.
	 * @return {Promise<{token: string, url: string}>}
	 * @spec openspec/changes/external-form-provisioning/specs/external-form-provisioning/spec.md#req-efp-006
	 */
	async function mintTrackLink(register, schema, objectId, opts = {}) {
		if (!register || !schema || !objectId) {
			throw new Error('mintTrackLink requires register, schema and objectId')
		}
		const url = generateUrl(
			`/apps/openregister/api/objects/${register}/${schema}/${objectId}/integrations/shares`,
		)
		const body = { type: 'public-token', ...opts }
		const { data } = await client.post(url, body)
		return { token: (data && data.token) || '', url: resolvePublicUrl(data) }
	}

	return { mintTrackLink }
}
