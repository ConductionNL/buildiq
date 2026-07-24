// SPDX-License-Identifier: EUPL-1.2
/**
 * useDocudeskTemplates — the ONE shared Docudesk template-list fetch, reused
 * by BOTH `DocumentTemplateAttachmentDialog`'s builder-UI template picker
 * (docudesk-document-templates REQ-DDT-002) and `AutomationEditDialog`'s
 * `generateDocument` action template picker (automation-document-action) —
 * a single implementation, not a second `GET /apps/docudesk/api/templates`
 * fetch/render (spec "Template picker reuses the existing Docudesk-
 * template-list component" / "Template list is shared, not duplicated").
 *
 * @spec openspec/changes/automation-document-action/specs/automation-document-action/spec.md
 * @spec openspec/changes/docudesk-document-templates/specs/docudesk-document-templates/spec.md#req-ddt-002
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const TEMPLATES_URL = '/apps/docudesk/api/templates'

/**
 * Fetch Docudesk's template list. Never throws — resolves to `[]` on any
 * failure (network error, Docudesk absent, malformed response); callers
 * degrade to free-text (mirrors every other live picker in this app).
 *
 * @param {object} [opts] - options.
 * @param {Function} [opts.client] - axios-like client injection for tests.
 * @return {Promise<Array<object>>} - the raw template records.
 * @spec openspec/changes/automation-document-action/specs/automation-document-action/spec.md
 */
export async function fetchDocudeskTemplates(opts = {}) {
	const client = (opts && opts.client) || axios
	try {
		const { data } = await client.get(generateUrl(TEMPLATES_URL))
		const list = (data && (data.results || data.templates || data)) || []
		return Array.isArray(list) ? list : []
	} catch {
		return []
	}
}

/**
 * Map a raw Docudesk template record to the shared `{label, uuid, name}`
 * picker-option shape both consumers render.
 *
 * @param {object} tpl - the raw template record.
 * @return {{label: string, uuid: string, name: string}}
 */
export function templateToOption(tpl) {
	return {
		label: tpl.name || tpl.title || tpl.id,
		uuid: tpl.id || tpl.uuid,
		name: tpl.name || tpl.title || '',
	}
}
