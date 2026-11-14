/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pins how the template gallery's Blocks tab and the page designer's block
 * library list component blocks: the response shapes read, a failed read,
 * the category options (first-seen order, blanks skipped) and the category
 * filter (by option or by bare id, none showing every block).
 */
import { shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))
vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn() },
}))

import axios from '@nextcloud/axios'
import BlockLibraryPanel from '../../src/components/page-editor/BlockLibraryPanel.vue'
import TemplateGallery from '../../src/views/TemplateGallery.vue'

const BLOCKS = [
	{ slug: 'a', category: 'form' },
	{ slug: 'b', category: '' },
	{ slug: 'c', category: 'layout' },
	{ slug: 'd', category: 'form' },
]

const CASES = [
	{ name: 'TemplateGallery', Component: TemplateGallery, options: 'blockCategoryOptions', filter: 'blockCategoryFilter', loading: 'blocksLoading' },
	{ name: 'BlockLibraryPanel', Component: BlockLibraryPanel, options: 'categoryOptions', filter: 'categoryFilter', loading: 'loading' },
]

/**
 * @param {object} Component the component.
 * @return {import('@vue/test-utils').VueWrapper}
 */
function mountIt(Component) {
	return shallowMount(Component, {
		global: {
			mocks: {
				$route: { query: {}, params: {} },
				$router: { replace: vi.fn().mockResolvedValue(undefined), push: vi.fn() },
			},
		},
	})
}

describe.each(CASES)('$name block list', ({ Component, options, filter, loading }) => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.get.mockResolvedValue({ data: [] })
	})

	it.each([
		['an envelope', { results: [{ slug: 'x' }] }, [{ slug: 'x' }]],
		['a bare array', [{ slug: 'y' }], [{ slug: 'y' }]],
		['an envelope without a list', { results: 'no' }, []],
		['no body', null, []],
	])('reads %s', async (_label, body, expected) => {
		const wrapper = mountIt(Component)
		axios.get.mockResolvedValueOnce({ data: body })

		await wrapper.vm.fetchBlocks()

		expect(axios.get).toHaveBeenLastCalledWith('/apps/openregister/api/objects/buildiq/component-block')
		expect(wrapper.vm.blocks).toEqual(expected)
		expect(wrapper.vm[loading]).toBe(false)
	})

	it('falls back to no blocks on a failed read', async () => {
		const wrapper = mountIt(Component)
		axios.get.mockRejectedValueOnce(new Error('boom'))

		await wrapper.vm.fetchBlocks()

		expect(wrapper.vm.blocks).toEqual([])
		expect(wrapper.vm[loading]).toBe(false)
	})

	it('offers the categories and filters on one', async () => {
		const wrapper = mountIt(Component)
		await wrapper.setData({ blocks: BLOCKS })

		expect(wrapper.vm[options]).toEqual([
			{ id: 'form', label: 'form' },
			{ id: 'layout', label: 'layout' },
		])
		expect(wrapper.vm.filteredBlocks).toEqual(BLOCKS)
		await wrapper.setData({ [filter]: { id: 'form', label: 'form' } })
		expect(wrapper.vm.filteredBlocks.map((b) => b.slug)).toEqual(['a', 'd'])
		await wrapper.setData({ [filter]: 'layout' })
		expect(wrapper.vm.filteredBlocks.map((b) => b.slug)).toEqual(['c'])
	})
})
