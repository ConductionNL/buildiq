/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * `src/views/VersionHistory.vue`: the status label of a row, the URL a version
 * opens at in the live shell (production at the canonical URL, any other one
 * through `?_version=`), and the reload after a promotion.
 */

import { enableAutoUnmount, shallowMount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const { axiosMock } = vi.hoisted(() => ({ axiosMock: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (path, params = {}) => '/index.php' + path.replace(/\{(\w+)\}/g, (_, k) => params[k]),
}))

import VersionHistory from '../../../src/views/VersionHistory.vue'
import { markPromoted } from '../../../src/composables/usePromoteDialog.js'

// The promotion signal is shared state: a page left mounted would reload too.
enableAutoUnmount(afterEach)

beforeEach(() => {
	axiosMock.get.mockReset()
	axiosMock.get.mockResolvedValue({ data: { results: [] } })
})

describe('VersionHistory links and labels', () => {
	it('labels published, archived and every other status as draft', () => {
		const wrapper = shallowMount(VersionHistory)

		expect(wrapper.vm.statusLabel({ status: 'published' })).toBe('Published')
		expect(wrapper.vm.statusLabel({ status: 'archived' })).toBe('Archived')
		expect(wrapper.vm.statusLabel({ status: 'draft' })).toBe('Draft')
		expect(wrapper.vm.statusLabel({})).toBe('Draft')
		expect(wrapper.vm.statusLabel(null)).toBe('Draft')
	})

	it('opens production at the canonical URL and any other version through ?_version=', () => {
		const wrapper = shallowMount(VersionHistory, { props: { appSlug: 'pet-store', currentVersionUuid: 'v-prod' } })

		expect(wrapper.vm.versionUrl({ id: 'v-prod', slug: '1-0-0' })).toBe('/index.php/apps/buildiq/builder/pet-store')
		expect(wrapper.vm.versionUrl({ '@self': { id: 'v-next' }, slug: 'next & beta' }))
			.toBe('/index.php/apps/buildiq/builder/pet-store?_version=next%20%26%20beta')
	})

	it('treats no version as production when the app has no current version', () => {
		const wrapper = shallowMount(VersionHistory, { props: { appSlug: 'pet-store' } })

		expect(wrapper.vm.isProduction({ id: 'v1' })).toBe(false)
		expect(wrapper.vm.versionUrl({ id: 'v1', slug: 'v1' })).toBe('/index.php/apps/buildiq/builder/pet-store?_version=v1')
	})

	it('has no URL without an app slug', () => {
		const wrapper = shallowMount(VersionHistory)

		expect(wrapper.vm.versionUrl({ id: 'v1', slug: 'v1' })).toBeNull()
	})

	it('reloads the list after a promotion', async () => {
		const wrapper = shallowMount(VersionHistory, { props: { appSlug: 'pet-store' } })
		await wrapper.vm.$nextTick()
		const before = axiosMock.get.mock.calls.length

		markPromoted()
		await wrapper.vm.$nextTick()

		expect(axiosMock.get.mock.calls.length).toBe(before + 1)
		expect(axiosMock.get.mock.calls.at(-1)[0]).toContain('pet-store')
		wrapper.unmount()
	})
})
