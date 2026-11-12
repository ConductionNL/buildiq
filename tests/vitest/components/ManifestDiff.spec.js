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

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

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

import axios from '@nextcloud/axios'
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
		// Let the mounted fetch SETTLE before seeding data. The comment here used
		// to say this "skips the async fetch" — it did not, it raced it. The
		// mocked axios resolves `{from: null, to: null}`, and whichever of the
		// two lands second wins. Under vitest 1 setData happened to win; under
		// vitest 4 the fetch does, and it nulls `fromBlob` straight back out, so
		// `diffParts` computes over two empty strings and returns [].
		await flushPromises()
		await wrapper.setData({
			fromBlob: sampleFrom,
			toBlob: sampleTo,
			loading: false,
		})

		const parts = wrapper.vm.diffParts
		expect(parts.length).toBeGreaterThan(0)
		// At least one hunk must be `added` (the new page) and one must be
		// `removed` (the version line changes).
		const added = parts.filter((p) => p.added)
		const removed = parts.filter((p) => p.removed)
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
		await flushPromises()
		await wrapper.setData({
			fromBlob: identical,
			toBlob: { ...identical },
			loading: false,
		})

		const parts = wrapper.vm.diffParts
		// At least one (unchanged) part should exist.
		expect(parts.length).toBeGreaterThan(0)
		expect(parts.every((p) => !p.added && !p.removed)).toBe(true)
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
		await flushPromises()
		await wrapper.setData({
			fromBlob: { manifest: { version: '1.0.0', pages: [] } },
			toBlob: { manifest: largeManifest },
			loading: false,
		})

		const parts = wrapper.vm.diffParts
		expect(parts.length).toBeGreaterThan(0)
		// One of the added hunks must mention the last page (page-49) — proves
		// the JSON.stringify+diffLines pipeline is feeding the full string in.
		const addedText = parts
			.filter((p) => p.added)
			.map((p) => p.value)
			.join('\n')
		expect(addedText).toContain('page-49')
	})

	it('renders the empty-state copy when both blobs are null', () => {
		const wrapper = mount(ManifestDiff, {
			propsData: { slug: 'hello-world', from: 'draft', to: '' },
		})
		// No fromBlob/toBlob seeded — hasAnyContent is false. The component
		// renders the empty-state paragraph.
		expect(wrapper.find('.manifest-diff__empty').exists()).toBe(true)
		expect(wrapper.find('.manifest-diff__empty').text()).toContain(
			'Nothing to diff',
		)
		expect(wrapper.text()).not.toContain('publish the app first')
	})
	describe('fetch branches', () => {
		it.each([
			[{ fromManifest: { a: 1 }, slug: 's', to: 't' }],
			[{ slug: '', to: 't' }],
			[{ slug: 's', to: '' }],
		])('does not fetch for %j', async (props) => {
			axios.get.mockClear()
			const wrapper = mount(ManifestDiff, { props })
			await flushPromises()
			await wrapper.vm.fetch()
			expect(axios.get).not.toHaveBeenCalled()
		})

		it('fetches both sides of the diff', async () => {
			axios.get.mockClear()
			axios.get.mockResolvedValue({ data: { from: { v: 1 }, to: { v: 2 } } })
			const wrapper = mount(ManifestDiff, { props: { slug: 'shop', from: 'dev', to: 'prod' } })
			await flushPromises()
			expect(axios.get).toHaveBeenCalledWith(
				'/apps/buildiq/api/applications/shop/versions/diff',
				{ params: { from: 'dev', to: 'prod' } },
			)
			expect(wrapper.vm.fromBlob).toEqual({ v: 1 })
			expect(wrapper.vm.toBlob).toEqual({ v: 2 })
			expect(wrapper.vm.loading).toBe(false)
			expect(wrapper.vm.error).toBe('')
		})

		it.each([[{ from: { v: 1 } }], [{}], [null]])('keeps a missing side as null (%j)', async (data) => {
			axios.get.mockResolvedValue({ data })
			const wrapper = mount(ManifestDiff, { props: { slug: 'shop', to: 'prod' } })
			await flushPromises()
			expect(wrapper.vm.toBlob).toBeNull()
			expect(wrapper.vm.fromBlob).toEqual(data && data.from ? data.from : null)
		})

		it.each([
			[new Error('down'), 'Diff failed: down'],
			['raw', 'Diff failed: raw'],
		])('reports a failed fetch %j', async (err, message) => {
			axios.get.mockRejectedValue(err)
			const wrapper = mount(ManifestDiff, { props: { slug: 'shop', to: 'prod' } })
			await flushPromises()
			expect(wrapper.vm.fromBlob).toBeNull()
			expect(wrapper.vm.toBlob).toBeNull()
			expect(wrapper.vm.error).toBe(message)
			expect(wrapper.vm.loading).toBe(false)
			axios.get.mockImplementation(() =>
				Promise.resolve({ data: { from: null, to: null } }),
			)
		})
	})
})
