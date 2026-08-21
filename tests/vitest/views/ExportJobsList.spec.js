/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Vitest unit tests for `src/views/ExportJobsList.vue` — locks the #104 fix:
 * the component MUST poll OR REST at the schema's real SLUG (`export-job`,
 * kebab-cased per openbuild_register.json's `exportJob.slug`), not the JSON
 * key (`exportJob`) a naive reader would guess from the sibling
 * `applicationVersion` schema's naming (whose key AND slug happen to match).
 */

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (path) => `/index.php${path}`,
}))

import ExportJobsList from '../../../src/views/ExportJobsList.vue'

/**
 * Wait one micro-tick + Vue render cycle so the async fetch resolves.
 *
 * @param wrapper The vue-test-utils mount wrapper.
 * @return Promise<void>
 */
async function flushFetch(wrapper) {
	await Promise.resolve()
	await wrapper.vm.$nextTick()
	await Promise.resolve()
	await wrapper.vm.$nextTick()
}

describe('ExportJobsList — #104 schema-slug fix', () => {
	beforeEach(() => {
		vi.stubGlobal(
			'fetch',
			vi.fn().mockResolvedValue({
				ok: true,
				json: async () => ({ results: [] }),
			}),
		)
	})

	it('polls OR REST at the export-job slug, not the exportJob JSON key', async () => {
		const wrapper = mount(ExportJobsList, {
			propsData: { applicationSlug: 'spectr' },
		})
		await flushFetch(wrapper)

		expect(global.fetch).toHaveBeenCalledTimes(1)
		const requestedUrl = global.fetch.mock.calls[0][0]

		expect(requestedUrl).toContain(
			'/apps/openregister/api/objects/openbuild/export-job',
		)
		expect(requestedUrl).not.toContain('exportJob')

		// VTU v1's `destroy()` is `unmount()` in v2.
		wrapper.unmount()
	})

	// This test used to assert `filter[applicationSlug]=my-app`. Commit
	// c1d22f4f5 ("the Exports tab was empty for every app — the filter used a
	// field the schema does not have", #95) correctly replaced that with a
	// plain `?applicationUuid=` param, for two independently sufficient
	// reasons documented in ExportJobsList.vue and measured against real
	// stored jobs: `export-job` has no `applicationSlug` property at all, and
	// the `filter[...]` bracket syntax is not what the endpoint reads.
	//
	// The spec was never updated, so it still pinned the exact broken
	// contract the fix removed — invisible because no app's JS unit suite had
	// ever run in CI. Production is right; the expectation is corrected.
	it('filters by the applicationUuid prop, as a plain query param', async () => {
		const wrapper = mount(ExportJobsList, {
			propsData: { applicationSlug: 'my-app', applicationUuid: 'app-uuid-1' },
		})
		await flushFetch(wrapper)

		const requestedUrl = global.fetch.mock.calls[0][0]
		expect(requestedUrl).toContain('applicationUuid=app-uuid-1')
		// The two shapes the #95 fix ruled out, pinned so neither comes back:
		// bracket-filter syntax the endpoint ignores, and a slug the
		// `export-job` schema does not declare.
		expect(requestedUrl).not.toContain('filter[')
		expect(requestedUrl).not.toContain('applicationSlug')

		wrapper.unmount()
	})

	it('renders fetched jobs in the table', async () => {
		global.fetch.mockResolvedValueOnce({
			ok: true,
			json: async () => ({
				results: [
					{
						uuid: 'job-1',
						applicationVersion: '1.0.0',
						target: 'zip',
						status: 'succeeded',
						downloadUrl: '/download/job-1',
					},
				],
			}),
		})

		const wrapper = mount(ExportJobsList, {
			propsData: { applicationSlug: 'spectr' },
		})
		await flushFetch(wrapper)

		expect(wrapper.text()).toContain('1.0.0')
		wrapper.unmount()
	})
})
