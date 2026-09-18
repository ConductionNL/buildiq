import { mount } from '@vue/test-utils'
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for ScreenOverrideList.vue.
 *
 * Spec: screen-override-layers (REQ-OBSO-003).
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/services/pageLayouts.js', () => ({
	fetchPageLayouts: vi.fn(),
	recutOverride: vi.fn(),
}))

import ScreenOverrideList from '../../src/components/page-editor/fields/ScreenOverrideList.vue'
import {
	fetchPageLayouts,
	recutOverride,
} from '../../src/services/pageLayouts.js'

const flush = () => new Promise((r) => setTimeout(r, 0))

const BASE = {
	id: 'pl-schema',
	name: 'Standaard',
	drifted: false,
	orphanedPaths: [],
}

const DRIFTED = {
	id: 'pl-behandelaars',
	name: 'Behandelaarsscherm',
	audience: { kind: 'group', ref: 'behandelaars' },
	layoutDelta: { tabs: { documenten: { $op: 'remove' } } },
	drifted: true,
	orphanedPaths: ['tabs.documenten'],
}

/**
 * Mount the list over a set of layouts.
 *
 * @param {Array<object>} layouts - what the endpoint answers.
 * @return {Promise<object>} The mounted wrapper.
 */
async function mountList(layouts) {
	fetchPageLayouts.mockResolvedValue(layouts)
	const wrapper = mount(ScreenOverrideList, {
		props: { register: 'dossiq', schema: 'Zaak' },
	})
	await flush()
	await wrapper.vm.$nextTick()
	return wrapper
}

describe('ScreenOverrideList', () => {
	beforeEach(() => {
		fetchPageLayouts.mockReset()
		recutOverride.mockReset()
	})

	it('says an instance without a published screen renders the manifest unchanged', async () => {
		const wrapper = await mountList([])

		expect(wrapper.text()).toContain('Cases render the app manifest unchanged.')
	})

	it('names a withheld override rather than only calling it drifted', async () => {
		// An administrator asked why one colleague sees fewer tabs needs to know
		// the override is neither applied nor lost, and which part stopped
		// applying.
		const wrapper = await mountList([BASE, DRIFTED])

		expect(wrapper.text()).toContain('Withheld, the screen underneath changed')
		expect(wrapper.text()).toContain('tabs.documenten')
	})

	it('offers a re-cut only on the override that drifted', async () => {
		const wrapper = await mountList([BASE, DRIFTED])

		const buttons = wrapper.findAll('button')
		expect(buttons).toHaveLength(1)
	})

	it('says what the re-cut dropped instead of losing it quietly', async () => {
		recutOverride.mockResolvedValue({
			layout: { id: 'pl-behandelaars' },
			dropped: ['tabs.documenten'],
		})

		const wrapper = await mountList([BASE, DRIFTED])
		fetchPageLayouts.mockResolvedValue([
			BASE,
			{ ...DRIFTED, drifted: false, orphanedPaths: [] },
		])

		await wrapper.find('button').trigger('click')
		await flush()
		await wrapper.vm.$nextTick()

		expect(recutOverride).toHaveBeenCalledWith('pl-behandelaars')
		expect(wrapper.text()).toContain('Re-cut. Dropped:')
		expect(wrapper.text()).toContain('tabs.documenten')
	})

	it('shows the sentence a refused re-cut wrote', async () => {
		recutOverride.mockRejectedValue({
			status: 404,
			error: 'not_found',
			message: 'No layout with that id.',
		})

		const wrapper = await mountList([DRIFTED])
		await wrapper.find('button').trigger('click')
		await flush()
		await wrapper.vm.$nextTick()

		expect(wrapper.text()).toContain('No layout with that id.')
	})
})
