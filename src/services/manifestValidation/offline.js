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
 * Whether a value is a plain object: not null and not an array.
 *
 * @param {unknown} value - the value.
 * @return {boolean}
 */
function isPlainObject(value) {
	return Boolean(value) && typeof value === 'object' && !Array.isArray(value)
}

/**
 * One `unknown-key` error per key of the object that is not allowed.
 *
 * @param {object} object - the object.
 * @param {string[]} allowed - the allowed keys.
 * @param {string} pointer - the object's JSON-Pointer.
 * @return {string[]}
 */
function unknownKeyErrors(object, allowed, pointer) {
	return Object.keys(object)
		.filter((key) => !allowed.includes(key))
		.map((key) => `${pointer}/${key}: buildiq.offline.error.unknown-key`)
}

// The checks of one take-along entry, in report order: key, test, error code.
const ENTRY_RULES = [
	['schema', (entry) => typeof entry.schema === 'string' && entry.schema.trim() !== '', 'schema-required'],
	['filter', (entry) => entry.filter === undefined || isPlainObject(entry.filter), 'filter-not-object'],
	['limit', (entry) => inRange(entry.limit, OFFLINE_LIMIT_MAX), 'limit-range'],
	['ttlHours', (entry) => inRange(entry.ttlHours, OFFLINE_TTL_MAX), 'ttl-range'],
]

/**
 * The errors of one take-along entry.
 *
 * @param {unknown} entry - the entry.
 * @param {string} pointer - its JSON-Pointer.
 * @return {string[]}
 */
function entryErrors(entry, pointer) {
	if (!isPlainObject(entry)) {
		return [`${pointer}: buildiq.offline.error.entry-not-object`]
	}
	const failed = ENTRY_RULES
		.filter(([, test]) => !test(entry))
		.map(([key, , code]) => `${pointer}/${key}: buildiq.offline.error.${code}`)
	return [...unknownKeyErrors(entry, ENTRY_KEYS, pointer), ...failed]
}

/**
 * The errors of an optional list: none when absent, `not-array` when it is no
 * array, else the errors of each item.
 *
 * @param {unknown} list - the list.
 * @param {string} pointer - its JSON-Pointer.
 * @param {(item: unknown, pointer: string) => string[]} itemErrors - the errors of one item.
 * @return {string[]}
 */
function listErrors(list, pointer, itemErrors) {
	if (list === undefined) {
		return []
	}
	if (!Array.isArray(list)) {
		return [`${pointer}: buildiq.offline.error.not-array`]
	}
	return list.flatMap((item, index) => itemErrors(item, `${pointer}/${index}`))
}

/**
 * The ids of the manifest's form pages.
 *
 * @param {object} manifest - the manifest.
 * @return {Set<string>}
 */
function formPageIds(manifest) {
	return new Set(
		(Array.isArray(manifest.pages) ? manifest.pages : [])
			.filter((page) => page && page.type === 'form')
			.map((page) => page.id),
	)
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
	if (!isPlainObject(block)) {
		return ['/offline: buildiq.offline.error.not-object']
	}
	const formPages = formPageIds(manifest)
	return [
		...unknownKeyErrors(block, BLOCK_KEYS, '/offline'),
		...listErrors(block.takeAlong, '/offline/takeAlong', entryErrors),
		...listErrors(block.forms, '/offline/forms', (id, pointer) => (
			formPages.has(id) ? [] : [`${pointer}: buildiq.offline.error.not-a-form-page`]
		)),
	]
}
