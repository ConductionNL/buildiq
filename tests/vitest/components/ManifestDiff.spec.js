/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Vitest unit tests for `src/components/ManifestDiff.vue` — the client-side
 * side-by-side diff renderer for two manifest blobs.
 *
 * Covers four scenarios called out in design.md §Decision 5 (client-side
 * diff):
 *   - identical manifests render no added/removed hunks
 *   - changed manifests produce expected jsdiff hunks (added + removed)
 *   - large-blob handling — a 2KB manifest still renders and diffParts is
 *     non-empty (smoke test against accidental string truncation)
 *   - empty/null manifest in either blob renders the empty-state copy
 *     ("Nothing to diff")
 *
 * axios is mocked via vi.mock so the component's fetch() never hits the
 * real network — we directly seed `fromBlob` and `toBlob` on the instance
 * before assertions, OR we resolve the mocked axios.get response.
 */

import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

// Mock axios at module scope (hoisted above imports). The component calls
// `axios.get(...)` returning a Promise<{ data: { from, to } }>.
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(() => Promise.resolve({ data: { from: null, to: null } })),
	},
}))

// `generateUrl` is a deterministic helper — return the input verbatim.
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path) => path,
}))

import ManifestDiff from '../../../src/components/ManifestDiff.vue'

describe('ManifestDiff — design.md Decision 5 (client-side jsdiff)', () => {
	const sampleFrom = {
		manifest: {
			version: '1.0.0',
			pages: [{ id: 'p1', route: '/', type: 'index' }],
		},
		version: '1.0.0',
		publishedAt: '2026-05-01T10:00:00Z',
	}
	const sampleTo = {
		manifest: {
			version: '1.1.0',
			pages: [
				{ id: 'p1', route: '/', type: 'index' },
				{ id: 'p2', route: '/about', type: 'detail' },
			],
		},
		version: '1.1.0',
		publishedAt: '2026-05-05T10:00:00Z',
	}

	it('produces added + removed hunks for a changed manifest', async () => {
		const wrapper = mount(ManifestDiff, {
			propsData: { slug: 'hello-world', from: 'snap-a', to: 'snap-b' },
		})
		// Directly set the data so we skip the async fetch — the computed
		// `diffParts` is the only thing under test.
		await wrapper.setData({ fromBlob: sampleFrom, toBlob: sampleTo, loading: false })

		const parts = wrapper.vm.diffParts
		expect(parts.length).toBeGreaterThan(0)
		// At least one hunk must be `added` (the new page) and one must be
		// `removed` (the version line changes).
		const added = parts.filter(p => p.added)
		const removed = parts.filter(p => p.removed)
		expect(added.length).toBeGreaterThan(0)
		expect(removed.length).toBeGreaterThan(0)
		// Belt-and-braces: the rendered <pre> contains the new route.
		expect(wrapper.find('.manifest-diff__pane').exists()).toBe(true)
		expect(wrapper.find('.manifest-diff__pane').text()).toContain('/about')
	})

	it('produces zero added/removed hunks for identical manifests', async () => {
		const identical = { ...sampleFrom }
		const wrapper = mount(ManifestDiff, {
			propsData: { slug: 'hello-world', from: 'snap-a', to: 'snap-b' },
		})
		await wrapper.setData({
			fromBlob: identical,
			toBlob: { ...identical },
			loading: false,
		})

		const parts = wrapper.vm.diffParts
		// At least one (unchanged) part should exist.
		expect(parts.length).toBeGreaterThan(0)
		expect(parts.every(p => !p.added && !p.removed)).toBe(true)
	})

	it('handles large blobs without truncating (smoke test on ~2KB manifest)', async () => {
		const largePages = []
		for (let i = 0; i < 50; i++) {
			largePages.push({ id: `page-${i}`, route: `/p${i}`, type: 'index' })
		}
		const largeManifest = {
			version: '2.0.0',
			pages: largePages,
		}
		const wrapper = mount(ManifestDiff, {
			propsData: { slug: 'hello-world', from: 'snap-a', to: 'snap-b' },
		})
		await wrapper.setData({
			fromBlob: { manifest: { version: '1.0.0', pages: [] } },
			toBlob: { manifest: largeManifest },
			loading: false,
		})

		const parts = wrapper.vm.diffParts
		expect(parts.length).toBeGreaterThan(0)
		// One of the added hunks must mention the last page (page-49) — proves
		// the JSON.stringify+diffLines pipeline is feeding the full string in.
		const addedText = parts.filter(p => p.added).map(p => p.value).join('\n')
		expect(addedText).toContain('page-49')
	})

	it('renders the empty-state copy when both blobs are null', () => {
		const wrapper = mount(ManifestDiff, {
			propsData: { slug: 'hello-world', from: 'draft', to: '' },
		})
		// No fromBlob/toBlob seeded — hasAnyContent is false. The component
		// renders the empty-state paragraph.
		expect(wrapper.find('.manifest-diff__empty').exists()).toBe(true)
		expect(wrapper.find('.manifest-diff__empty').text()).toContain('Nothing to diff')
	})
})
