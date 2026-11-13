/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * ManifestDiff refetches when any of `from`, `to` or `slug` changes, with the
 * new values as the request parameters.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(() => Promise.resolve({ data: { from: null, to: null } })),
	},
}))

vi.mock('@nextcloud/router', () => ({
	generateUrl: (path) => path,
}))

import axios from '@nextcloud/axios'
import ManifestDiff from '../../../src/components/ManifestDiff.vue'

describe('ManifestDiff refetch on prop change', () => {
	let wrapper

	beforeEach(async () => {
		axios.get.mockClear()
		wrapper = mount(ManifestDiff, {
			props: { slug: 'my-app', from: 'draft', to: 'v1-uuid-0000' },
		})
		await flushPromises()
		expect(axios.get).toHaveBeenCalledTimes(1)
		axios.get.mockClear()
	})

	it('refetches with the new from', async () => {
		await wrapper.setProps({ from: 'v0-uuid-0000' })
		await flushPromises()

		expect(axios.get).toHaveBeenCalledTimes(1)
		expect(axios.get).toHaveBeenCalledWith(
			'/apps/buildiq/api/applications/my-app/versions/diff',
			{ params: { from: 'v0-uuid-0000', to: 'v1-uuid-0000' } },
		)
	})

	it('refetches with the new to', async () => {
		await wrapper.setProps({ to: 'v2-uuid-0000' })
		await flushPromises()

		expect(axios.get).toHaveBeenCalledWith(
			'/apps/buildiq/api/applications/my-app/versions/diff',
			{ params: { from: 'draft', to: 'v2-uuid-0000' } },
		)
	})

	it('refetches against the new slug', async () => {
		await wrapper.setProps({ slug: 'other-app' })
		await flushPromises()

		expect(axios.get).toHaveBeenCalledWith(
			'/apps/buildiq/api/applications/other-app/versions/diff',
			{ params: { from: 'draft', to: 'v1-uuid-0000' } },
		)
	})

	it('does not fetch when the new to is empty', async () => {
		await wrapper.setProps({ to: '' })
		await flushPromises()

		expect(axios.get).not.toHaveBeenCalled()
	})
})
