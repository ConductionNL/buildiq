// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * pageSettings — the values users saved on a settings page of a virtual app
 * (capability-requirements-backfill REQ-BQCV-011).
 *
 * The server keeps them per app and page; any role on the app reads them,
 * owners and editors save them. CnSettingsPage PUTs a save to the same
 * address it reads from.
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-maker-adds-a-settings-page-req-bqcv-011
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * The page settings address of one settings page.
 *
 * @param {string} slug The running app's slug.
 * @param {string} pageId The settings page id.
 * @return {string} The URL.
 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-maker-adds-a-settings-page-req-bqcv-011
 */
export function pageSettingsUrl(slug, pageId) {
	return generateUrl('/apps/buildiq/api/applications/{slug}/page-settings/{pageId}', { slug, pageId })
}

/**
 * The values saved on a settings page and whether the user may save.
 *
 * A failed read answers no values and no save, so a user never overwrites
 * values they could not see.
 *
 * @param {string} slug The running app's slug.
 * @param {string} pageId The settings page id.
 * @return {Promise<{values: object, canSave: boolean}>} The saved values.
 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-maker-adds-a-settings-page-req-bqcv-011
 */
export async function fetchPageSettings(slug, pageId) {
	try {
		const { data } = await axios.get(pageSettingsUrl(slug, pageId))
		const values = data && data.values && typeof data.values === 'object' && !Array.isArray(data.values)
			? data.values
			: {}
		return { values, canSave: Boolean(data && data.canSave) }
	} catch {
		return { values: {}, canSave: false }
	}
}
