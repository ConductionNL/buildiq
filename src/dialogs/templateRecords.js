/**
 * Shared pieces of the dialogs that write `ApplicationTemplate` records
 * through OpenRegister's object REST API: the collection url, the url of
 * one record and the category picker options.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

import { generateUrl } from '@nextcloud/router'
import { TEMPLATE_CATEGORIES } from '../services/templateCapture.js'

/** The OpenRegister object collection of application templates. */
export const OR_TEMPLATES = '/apps/openregister/api/objects/buildiq/application-template'

const CATEGORY_LABELS = {
	'government-services': 'Government services',
	'internal-operations': 'Internal operations',
	'citizen-engagement': 'Citizen engagement',
	'field-work': 'Field work',
}

/**
 * Category picker options over the REQ-OBTC-001 enum.
 *
 * @return {Array<{id: string, label: string}>} one option per category.
 * @spec openspec/changes/save-as-template/specs/save-as-template/spec.md
 */
export function templateCategoryOptions() {
	return TEMPLATE_CATEGORIES.map((value) => ({
		id: value,
		label: t('buildiq', CATEGORY_LABELS[value] || value),
	}))
}

/**
 * The url of one template record, addressed by its `@self.id`, `uuid` or `id`.
 *
 * @param {object} record - the template record.
 * @return {string} the generated url.
 * @spec openspec/changes/save-as-template/specs/save-as-template/spec.md
 */
export function templateRecordUrl(record) {
	const uuid =
		(record['@self'] && record['@self'].id)
		|| record.uuid
		|| record.id
	return generateUrl(`${OR_TEMPLATES}/${encodeURIComponent(uuid)}`)
}
