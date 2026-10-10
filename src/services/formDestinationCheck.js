// SPDX-License-Identifier: EUPL-1.2
/**
 * formDestinationCheck — asks buildiq which form pages of a manifest their
 * destination refuses (decision 179). buildiq hands the pages to
 * OpenRegister's form destination validator; no rule lives here.
 *
 * The page designer asks after every save: the pages are a draft, so they
 * save with their findings and each finding is marked on its field
 * (decision 189). Publishing the app refuses while any finding remains.
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Every destination finding on the manifest's form pages.
 *
 * @param {object} manifest - the manifest as it was saved.
 * @param {object} [client] - axios-compatible client (tests inject one).
 * @return {Promise<{accepted: boolean, findings: Array<object>}>} The answer.
 * @spec openspec/changes/forms-are-valid-against-their-destination/specs/form-destination-authoring/spec.md#requirement-a-draft-form-saves-with-its-findings
 */
export async function checkFormDestinations(manifest, client = axios) {
	const { data } = await client.post(
		generateUrl('/apps/buildiq/api/forms/destination-check'),
		{ manifest },
	)
	const findings = data && Array.isArray(data.findings) ? data.findings : []
	return { accepted: findings.length === 0, findings }
}

/**
 * The findings that belong to one page, by id or else by position.
 *
 * @param {Array<object>} findings - findings `{page, pageIndex, field?, property, code, message}`.
 * @param {string} pageId - the page's id.
 * @param {number} pageIndex - the page's position in `pages[]`.
 * @return {Array<object>} The page's findings.
 * @spec openspec/changes/forms-are-valid-against-their-destination/specs/form-destination-authoring/spec.md#requirement-every-buildiq-form-must-be-valid-against-its-destination-before-it-is-published
 */
export function findingsForPage(findings, pageId, pageIndex) {
	return (findings || []).filter((f) =>
		f && (pageId ? f.page === pageId : f.pageIndex === pageIndex),
	)
}

/**
 * Group findings by the form field they sit on. A finding on no field (a
 * required property nothing fills, a destination that refuses the audience)
 * goes under the empty key, so the editor lists it above the fields.
 *
 * A finding names its field in `field`; a fixed-value finding names only its
 * `property`, which is the field or preset it is about.
 *
 * @param {Array<object>} findings - the findings.
 * @return {Object<string, Array<object>>} Findings per field key.
 * @spec openspec/changes/forms-are-valid-against-their-destination/specs/form-destination-authoring/spec.md#requirement-every-buildiq-form-must-be-valid-against-its-destination-before-it-is-published
 */
export function findingsByField(findings) {
	const byField = {}
	for (const finding of findings || []) {
		if (!finding) {
			continue
		}
		const key =
			finding.field
			|| (finding.code === 'required-unmapped' ? '' : finding.property)
			|| ''
		if (!byField[key]) {
			byField[key] = []
		}
		byField[key].push(finding)
	}
	return byField
}
