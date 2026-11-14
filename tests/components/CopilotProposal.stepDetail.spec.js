/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests pinning CopilotProposal.vue's `stepDetail`: the first
 * truthy of title, label, pageId, slug and appSlug, '' otherwise.
 */
import { shallowMount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(() => Promise.resolve({ data: {} })) },
}))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))

import CopilotProposal from '../../src/components/copilot/CopilotProposal.vue'

describe('CopilotProposal stepDetail', () => {
	it.each([
		[{ tool: 'x' }, ''],
		[{ tool: 'x', arguments: null }, ''],
		[{ tool: 'x', arguments: { title: 'T', label: 'L', pageId: 'p', slug: 's', appSlug: 'a' } }, 'T'],
		[{ tool: 'x', arguments: { title: '', label: 'L', slug: 's' } }, 'L'],
		[{ tool: 'x', arguments: { pageId: 'p', slug: 's' } }, 'p'],
		[{ tool: 'x', arguments: { slug: 's', appSlug: 'a' } }, 's'],
		[{ tool: 'x', arguments: { appSlug: 'a' } }, 'a'],
		[{ tool: 'x', arguments: { title: 0, appSlug: '' } }, ''],
		[{ tool: 'x', arguments: { name: 'ignored' } }, ''],
	])('of %j is %j', (step, expected) => {
		const wrapper = shallowMount(CopilotProposal, {
			propsData: { plan: { summary: '', steps: [], manifests: {} }, canApprove: false },
		})
		expect(wrapper.vm.stepDetail(step)).toBe(expected)
	})
})
