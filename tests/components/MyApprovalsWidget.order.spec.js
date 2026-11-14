/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec pinning MyApprovalsWidget.vue's merge of the two inbox scopes:
 * both response envelopes, one row per task (by uuid, else id), and the
 * deadline order (earliest first, ties and undated rows in arrival order,
 * a row with any deadline before the undated ones), plus the failure state.
 */
import { shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/initial-state', () => ({ loadState: vi.fn() }))

import axios from '@nextcloud/axios'
import MyApprovalsWidget from '../../src/components/runtime/MyApprovalsWidget.vue'

const flush = () => new Promise((r) => setTimeout(r, 0))

/**
 * Answer the two scope reads with the given bodies.
 *
 * @param {object|Array} pooled Body of the pooled read.
 * @param {object|Array} assigned Body of the assigned read.
 */
function answer(pooled, assigned) {
	axios.get.mockImplementation(async (url, { params }) => ({
		data: params.scope === 'pooled' ? pooled : assigned,
	}))
}

async function loaded() {
	const wrapper = shallowMount(MyApprovalsWidget)
	await flush()
	await flush()
	return wrapper
}

describe('MyApprovalsWidget load order', () => {
	beforeEach(() => {
		axios.get.mockReset()
	})

	it('merges both envelopes, keeps one row per task and orders by deadline', async () => {
		answer(
			{
				results: [
					{ uuid: 'late', dueAt: '2026-12-01T10:00:00Z' },
					{ uuid: 'none-1' },
					{ uuid: 'early', dueAt: '2026-11-20T10:00:00Z' },
					{ id: 7, dueAt: '2026-11-25T10:00:00Z' },
				],
			},
			[
				{ uuid: 'early', dueAt: '2026-11-20T10:00:00Z' },
				{ uuid: 'tie', dueAt: '2026-11-20T10:00:00Z' },
				{ id: 7 },
				{ uuid: 'none-2' },
				{ uuid: 'bad', dueAt: 'not a date' },
			],
		)
		const wrapper = await loaded()
		expect(wrapper.vm.steps.map((r) => r.uuid ?? r.id)).toEqual([
			'early',
			'tie',
			7,
			'late',
			'bad',
			'none-1',
			'none-2',
		])
		expect(axios.get).toHaveBeenCalledTimes(2)
		expect(axios.get.mock.calls.map((c) => c[1].params)).toEqual([
			{ scope: 'pooled', isTerminal: 'false', limit: 50 },
			{ scope: 'assigned', isTerminal: 'false', limit: 50 },
		])
		expect(wrapper.vm.loading).toBe(false)
		expect(wrapper.vm.error).toBe(false)
	})

	it('treats a body without results as no rows', async () => {
		answer({ total: 0 }, null)
		const wrapper = await loaded()
		expect(wrapper.vm.steps).toEqual([])
	})

	it('flags the failure and clears the rows when a read fails', async () => {
		axios.get.mockRejectedValue(new Error('down'))
		const wrapper = await loaded()
		expect(wrapper.vm.error).toBe(true)
		expect(wrapper.vm.steps).toEqual([])
		expect(wrapper.vm.loading).toBe(false)
	})
})
