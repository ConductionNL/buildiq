// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * smartPaste — buildiq's "Paste to fill" handler for nextcloud-vue's
 * CnFormPage (`config.smartPaste.handler`), over hermiq's governed form-fill
 * feature (hermiq `form-fill`, REQ-FFILL-001/002). Hermiq is the only side
 * that talks to a model; this module sends the pasted text and the allowed
 * field descriptors (never a value) and hands back the proposals.
 *
 * - `available()`: hermiq says whether the feature is on; any failure (hermiq
 *   absent, not signed in) is "not available", so the form shows no button.
 * - `fill(text, fields)`: `{ values, notice }`; a refusal or an unavailable
 *   answer proposes nothing. CnFormPage fits every value to its field, fills
 *   only what it may, and never submits on its own.
 *
 * @spec openspec/changes/ai-smart-paste-into-forms/specs/form-smart-paste/spec.md#requirement-the-authored-behaviour-is-fixed-for-the-renderer-req-bqsp-003
 */
import axios from '@nextcloud/axios'
import { getLanguage } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/** The registry name buildiq's forms name their smart-paste handler by. */
export const SMART_PASTE_HANDLER = 'buildiq-smart-paste'

/** Most pasted characters hermiq takes. */
export const SMART_PASTE_MAX_TEXT = 10000

/**
 * Whether hermiq's form fill is switched on for this user.
 *
 * @return {Promise<boolean>}
 * @spec openspec/changes/ai-smart-paste-into-forms/specs/form-smart-paste/spec.md#requirement-the-authored-behaviour-is-fixed-for-the-renderer-req-bqsp-003
 */
export async function smartPasteAvailable() {
	try {
		const { data } = await axios.get(generateUrl('/apps/hermiq/api/assistant/form-fill/available'))
		return !!data && data.available === true
	} catch {
		return false
	}
}

/**
 * The field descriptors hermiq takes: key, label, type and the allowed options.
 *
 * @param {Array<{key: string, label?: string, type?: string, options?: ?Array<string>}>} fields The allowed fields.
 * @return {Array<{key: string, label: string, type: string, options: Array<string>}>}
 * @spec openspec/changes/ai-smart-paste-into-forms/specs/form-smart-paste/spec.md#requirement-the-authored-behaviour-is-fixed-for-the-renderer-req-bqsp-003
 */
export function describeFields(fields) {
	return (Array.isArray(fields) ? fields : [])
		.filter((field) => field && typeof field.key === 'string' && field.key !== '')
		.slice(0, 50)
		.map((field) => ({
			key: field.key,
			label: String(field.label || field.key).slice(0, 200),
			type: String(field.type || 'string').toLowerCase(),
			options: Array.isArray(field.options) ? field.options.slice(0, 100).map(String) : [],
		}))
}

/**
 * Ask hermiq for proposals for the allowed fields from the pasted text.
 *
 * @param {string} text The pasted text.
 * @param {Array<object>} fields The allowed fields as CnFormPage describes them.
 * @return {Promise<{values: object, notice: string}>}
 * @spec openspec/changes/ai-smart-paste-into-forms/specs/form-smart-paste/spec.md#requirement-the-authored-behaviour-is-fixed-for-the-renderer-req-bqsp-003
 */
export async function smartPasteFill(text, fields) {
	const descriptors = describeFields(fields)
	const pasted = String(text || '').slice(0, SMART_PASTE_MAX_TEXT)
	if (descriptors.length === 0 || pasted.trim() === '') {
		return { values: {}, notice: '' }
	}
	const { data } = await axios.post(generateUrl('/apps/hermiq/api/assistant/form-fill'), {
		text: pasted,
		fields: descriptors,
		language: getLanguage() || 'nl',
	})
	if (!data || data.available !== true || typeof data.values !== 'object' || data.values === null) {
		return { values: {}, notice: '' }
	}
	const allowed = new Set(descriptors.map((field) => field.key))
	const values = {}
	Object.entries(data.values).forEach(([key, value]) => {
		if (allowed.has(key)) {
			values[key] = value
		}
	})
	return { values, notice: typeof data.notice === 'string' ? data.notice : '' }
}

/** The handler as CnFormPage resolves it: `{ available, fill }`. */
export const smartPasteHandler = Object.freeze({ available: smartPasteAvailable, fill: smartPasteFill })
