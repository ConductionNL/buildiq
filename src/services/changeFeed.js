// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * changeFeed — the recent changes to one schema's records, for a logs page
 * bound to that schema (capability-requirements-backfill REQ-BQCV-010).
 *
 * The feed is OpenRegister's caller-scoped audit trail
 * (`/audit-trails/readable`): it lists only the entries of records the user
 * may read, newest first, without session or address details. That list
 * narrows by the numeric register and schema ids, so a manifest's slugs are
 * resolved first.
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-maker-adds-a-log-page-req-bqcv-010
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** The most changes one page lists. */
export const CHANGE_FEED_LIMIT = 100

/**
 * The numeric id of a register or schema named by id, uuid or slug.
 *
 * @param {string} kind `registers` or `schemas`.
 * @param {string|number} ref The id, uuid or slug.
 * @return {Promise<?number>} The id, or null when it cannot be resolved.
 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-maker-adds-a-log-page-req-bqcv-010
 */
export async function resolveId(kind, ref) {
	if (/^\d+$/.test(String(ref))) {
		return Number(ref)
	}
	try {
		const { data } = await axios.get(generateUrl('/apps/openregister/api/{kind}/{ref}', { kind, ref: String(ref) }))
		const id = data && Number(data.id)
		return Number.isInteger(id) && id > 0 ? id : null
	} catch {
		return null
	}
}

/**
 * The address of the change feed of one schema, or null when the register
 * or schema cannot be resolved.
 *
 * @param {string|number} register The register id, uuid or slug.
 * @param {string|number} schema The schema id, uuid or slug.
 * @return {Promise<?string>} The URL.
 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-maker-adds-a-log-page-req-bqcv-010
 */
export async function changeFeedUrl(register, schema) {
	const [registerId, schemaId] = await Promise.all([
		resolveId('registers', register),
		resolveId('schemas', schema),
	])
	if (registerId === null || schemaId === null) {
		return null
	}
	const params = new URLSearchParams({
		register: String(registerId),
		schema: String(schemaId),
		limit: String(CHANGE_FEED_LIMIT),
	})
	return generateUrl('/apps/openregister/api/audit-trails/readable') + '?' + params.toString()
}
