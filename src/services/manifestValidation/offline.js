// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * offline — app-side validation of the manifest's `offline` block until the
 * library manifest schema carries it (pages-installable-offline-and-mobile
 * T04, T07). Errors are `<pointer>: <code>` strings, like the sibling
 * validators, so the page designer marks the offending entry.
 *
 * @spec openspec/changes/pages-installable-offline-and-mobile/specs/installable-offline-apps/spec.md#requirement-a-maker-declares-what-goes-offline-req-bqom-003
 */

import { OFFLINE_LIMIT_MAX, OFFLINE_TTL_MAX } from '../offlineManifest.js'

const BLOCK_KEYS = ['takeAlong', 'forms']
const ENTRY_KEYS = ['schema', 'filter', 'limit', 'ttlHours']

/**
 * Whether a value is a whole number in [1, max].
 *
 * @param {unknown} value - the value.
 * @param {number} max - the cap.
 * @return {boolean}
 */
function inRange(value, max) {
	return Number.isInteger(value) && value >= 1 && value <= max
}

/**
 * The errors of one take-along entry.
 *
 * @param {unknown} entry - the entry.
 * @param {string} pointer - its JSON-Pointer.
 * @return {string[]}
 */
function entryErrors(entry, pointer) {
	if (!entry || typeof entry !== 'object' || Array.isArray(entry)) {
		return [`${pointer}: buildiq.offline.error.entry-not-object`]
	}
	const errors = Object.keys(entry)
		.filter((key) => !ENTRY_KEYS.includes(key))
		.map((key) => `${pointer}/${key}: buildiq.offline.error.unknown-key`)
	if (typeof entry.schema !== 'string' || entry.schema.trim() === '') {
		errors.push(`${pointer}/schema: buildiq.offline.error.schema-required`)
	}
	if (entry.filter !== undefined && (entry.filter === null || typeof entry.filter !== 'object' || Array.isArray(entry.filter))) {
		errors.push(`${pointer}/filter: buildiq.offline.error.filter-not-object`)
	}
	if (!inRange(entry.limit, OFFLINE_LIMIT_MAX)) {
		errors.push(`${pointer}/limit: buildiq.offline.error.limit-range`)
	}
	if (!inRange(entry.ttlHours, OFFLINE_TTL_MAX)) {
		errors.push(`${pointer}/ttlHours: buildiq.offline.error.ttl-range`)
	}
	return errors
}

/**
 * The errors of a manifest's `offline` block; [] when it has none.
 *
 * `forms` must name form pages of this manifest.
 *
 * @param {object} manifest - the manifest.
 * @return {string[]}
 *
 * @spec openspec/changes/pages-installable-offline-and-mobile/specs/installable-offline-apps/spec.md#requirement-a-maker-declares-what-goes-offline-req-bqom-003
 */
export function validateOffline(manifest) {
	const block = manifest && manifest.offline
	if (block === undefined) {
		return []
	}
	if (!block || typeof block !== 'object' || Array.isArray(block)) {
		return ['/offline: buildiq.offline.error.not-object']
	}
	const errors = Object.keys(block)
		.filter((key) => !BLOCK_KEYS.includes(key))
		.map((key) => `/offline/${key}: buildiq.offline.error.unknown-key`)

	if (block.takeAlong !== undefined) {
		if (!Array.isArray(block.takeAlong)) {
			errors.push('/offline/takeAlong: buildiq.offline.error.not-array')
		} else {
			block.takeAlong.forEach((entry, index) => {
				errors.push(...entryErrors(entry, `/offline/takeAlong/${index}`))
			})
		}
	}

	if (block.forms !== undefined) {
		if (!Array.isArray(block.forms)) {
			errors.push('/offline/forms: buildiq.offline.error.not-array')
		} else {
			const formPages = new Set(
				(Array.isArray(manifest.pages) ? manifest.pages : [])
					.filter((page) => page && page.type === 'form')
					.map((page) => page.id),
			)
			block.forms.forEach((id, index) => {
				if (!formPages.has(id)) {
					errors.push(`/offline/forms/${index}: buildiq.offline.error.not-a-form-page`)
				}
			})
		}
	}
	return errors
}
