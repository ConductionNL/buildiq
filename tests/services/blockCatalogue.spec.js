/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for the component-block catalogue: the fetch and its accepted
 * response shapes, the category options and the category filter.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => `/index.php${p}`,
}))
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))

import axios from '@nextcloud/axios'
import {
	blocksInCategory,
	categoryOptionsOf,
	fetchComponentBlocks,
	OR_BLOCKS,
} from '../../src/services/blockCatalogue.js'

const blocks = [
	{ id: 'a', category: 'forms' },
	{ id: 'b', category: 'lists' },
	{ id: 'c', category: 'forms' },
	{ id: 'd' },
	null,
	{ id: 'e', category: '' },
]

beforeEach(() => {
	axios.get.mockReset()
})

describe('fetchComponentBlocks', () => {
	it('reads the results of the component-block collection', async () => {
		axios.get.mockResolvedValue({ data: { results: [{ id: 'a' }] } })

		await expect(fetchComponentBlocks()).resolves.toEqual([{ id: 'a' }])
		expect(axios.get).toHaveBeenCalledWith(`/index.php${OR_BLOCKS}`)
		expect(OR_BLOCKS).toBe('/apps/openregister/api/objects/buildiq/component-block')
	})

	it('accepts a bare list', async () => {
		axios.get.mockResolvedValue({ data: [{ id: 'b' }] })

		await expect(fetchComponentBlocks()).resolves.toEqual([{ id: 'b' }])
	})

	it.each([[null], [{}], [{ results: 'x' }], ['text']])('answers an empty list for %j', async (data) => {
		axios.get.mockResolvedValue({ data })

		await expect(fetchComponentBlocks()).resolves.toEqual([])
	})

	it('lets a failed request reject', async () => {
		axios.get.mockRejectedValue(new Error('offline'))

		await expect(fetchComponentBlocks()).rejects.toThrow('offline')
	})
})

describe('categoryOptionsOf', () => {
	it('lists each category once, in first-seen order, skipping blanks and missing ones', () => {
		expect(categoryOptionsOf(blocks)).toEqual([
			{ id: 'forms', label: 'forms' },
			{ id: 'lists', label: 'lists' },
		])
	})

	it('answers no options for no blocks', () => {
		expect(categoryOptionsOf([])).toEqual([])
	})
})

describe('blocksInCategory', () => {
	it('keeps only the blocks of the selected category, given as an option or an id', () => {
		const forms = [blocks[0], blocks[2]]

		expect(blocksInCategory(blocks, { id: 'forms', label: 'forms' })).toEqual(forms)
		expect(blocksInCategory(blocks, 'forms')).toEqual(forms)
	})

	it.each([[null], [''], [{ id: '' }], [undefined]])('answers every block for the empty filter %j', (filter) => {
		expect(blocksInCategory(blocks, filter)).toBe(blocks)
	})

	it('answers nothing for a category no block has', () => {
		expect(blocksInCategory(blocks, 'charts')).toEqual([])
	})
})
