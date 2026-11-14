/**
 * SPDX-FileCopyrightText: 2026 ConductionNL / Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * OpenRegister's shared lists (SKOS concept schemes) for the schema
 * designer's choice field (data-field-types-and-choice-lists design D2).
 *
 * A choice field that uses a shared list stores the scheme's slug as
 * `conceptScheme`; OpenRegister validates values against the scheme, so a
 * concept added later is accepted without saving the schema again.
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** The bundled vocabulary register and its scheme schema. */
const SCHEMES = '/apps/openregister/api/objects/vocabulary/conceptScheme'

/** The paginated concept listing of one scheme. */
const CONCEPTS = '/apps/openregister/api/vocabulary/concepts'

/**
 * The rows of an OpenRegister list answer, whichever envelope it uses.
 *
 * @param {object} data The response body.
 * @return {Array<object>} The rows.
 */
function rowsOf(data) {
	if (Array.isArray(data)) {
		return data
	}
	if (data && Array.isArray(data.results)) {
		return data.results
	}
	return []
}

/**
 * The text of a SKOS label, which may be a string or a language map.
 *
 * @param {string|object} label The label.
 * @return {string} The text: Dutch, then English, then any.
 */
export function labelText(label) {
	if (typeof label === 'string') {
		return label
	}
	if (label && typeof label === 'object') {
		return label.nl || label.en || Object.values(label).find((value) => typeof value === 'string') || ''
	}
	return ''
}

/**
 * The concept schemes, as picker options: the slug a property stores, the
 * title a maker reads, and the uri the concept listing needs.
 *
 * @spec openspec/changes/data-field-types-and-choice-lists/specs/schema-designer-field-types/spec.md#requirement-a-choice-field-has-inline-or-shared-options-req-bqft-002
 * @return {Promise<Array<{value: string, label: string, uri: string}>>} The options, by title.
 */
export async function listConceptSchemes() {
	const { data } = await axios.get(generateUrl(SCHEMES), { params: { _limit: 500 } })
	return rowsOf(data)
		.map((scheme) => {
			const slug = (scheme['@self'] && scheme['@self'].slug) || scheme.slug || ''
			return {
				value: slug,
				label: labelText(scheme.title) || slug,
				uri: scheme.uri || '',
			}
		})
		.filter((option) => option.value !== '')
		.sort((a, b) => a.label.localeCompare(b.label))
}

/**
 * The options of one scheme, read-only, in the order OpenRegister serves them.
 *
 * @spec openspec/changes/data-field-types-and-choice-lists/specs/schema-designer-field-types/spec.md#requirement-a-choice-field-has-inline-or-shared-options-req-bqft-002
 * @param {string} schemeUri The scheme's uri.
 * @return {Promise<Array<{value: string, label: string}>>} The options.
 */
export async function listSchemeOptions(schemeUri) {
	const { data } = await axios.get(generateUrl(CONCEPTS), { params: { scheme: schemeUri, _limit: 100 } })
	return rowsOf(data).map((concept) => {
		const value = concept.notation || concept.uri || ''
		return { value, label: labelText(concept.prefLabel) || value }
	})
}
