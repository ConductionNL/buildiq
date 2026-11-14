// SPDX-License-Identifier: EUPL-1.2
/**
 * blockCatalogue — reading the component blocks the caller can see and
 * narrowing them by category, shared by the template gallery's Blocks tab
 * and the page designer's block library.
 *
 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** The OpenRegister object collection of component blocks. */
export const OR_BLOCKS = '/apps/openregister/api/objects/buildiq/component-block'

/**
 * Every `ComponentBlock` visible to the caller (org-scoped by OpenRegister's
 * standard object listing).
 *
 * @return {Promise<Array<object>>} the blocks: an envelope's `results`, a bare array, or none.
 */
export async function fetchComponentBlocks() {
	const { data } = await axios.get(generateUrl(OR_BLOCKS))
	return Array.isArray(data && data.results)
		? data.results
		: Array.isArray(data)
			? data
			: []
}

/**
 * The category filter options of a block list, in first-seen order.
 *
 * @param {Array<object>} blocks - the blocks.
 * @return {Array<{id: string, label: string}>} one option per category.
 */
export function categoryOptionsOf(blocks) {
	const seen = new Set()
	return blocks
		.map((b) => b && b.category)
		.filter((c) => c && !seen.has(c) && seen.add(c))
		.map((c) => ({ id: c, label: c }))
}

/**
 * @param {Array<object>} blocks - the blocks.
 * @param {object|string|null} filter - the picked category option, a bare id, or none.
 * @return {Array<object>} the blocks of that category, or all of them.
 */
export function blocksInCategory(blocks, filter) {
	const selected = filter && (filter.id ?? filter)
	if (!selected) {
		return blocks
	}
	return blocks.filter((b) => b && b.category === selected)
}
