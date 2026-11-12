/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest characterisation of `ApplicationDiffTab`: version-row UUIDs, the
 * production UUID, and the default pair it compares (the selected or
 * promoting version against production).
 */
import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const { axiosMock } = vi.hoisted(() => ({ axiosMock: { get: vi.fn() } }))

vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (path, params = {}) =>
		path.replace(/\{(\w+)\}/g, (_, k) => params[k] ?? `{${k}}`),
}))

import ApplicationDiffTab from '../../../src/components/tabs/ApplicationDiffTab.vue'

const VERSIONS = [
	{ id: 'dev', slug: 'development', name: 'Development', promotesTo: 'acc' },
	{ '@self': { id: 'acc' }, slug: 'acceptance', name: 'Acceptance', semver: '1.1.0', promotesTo: 'prod' },
	{ uuid: 'prod', slug: 'production', name: 'Production' },
	{ id: 'old', slug: 'old', status: 'archived' },
]

/**
 * Mount the tab for an app, with the versions endpoint answering `versions`.
 *
 * @param {object} opts `{ productionVersion, versions, query }`.
 * @return {Promise<import('@vue/test-utils').Wrapper>}
 */
async function mountTab({ productionVersion = 'prod', versions = VERSIONS, query = {} } = {}) {
	axiosMock.get.mockResolvedValue({ data: versions })
	const wrapper = shallowMount(ApplicationDiffTab, {
		props: { object: { uuid: 'app', slug: 'shop', productionVersion } },
		global: { mocks: { $route: { query } }, stubs: { ManifestDiff: true, NcSelect: true } },
	})
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	axiosMock.get.mockReset()
})

describe('ApplicationDiffTab', () => {
	it('loads the non-archived versions as options, labelled with their semver', async () => {
		const wrapper = await mountTab()
		expect(axiosMock.get).toHaveBeenCalledWith('/apps/buildiq/api/applications/shop/versions')
		expect(wrapper.vm.options).toEqual([
			{ id: 'dev', slug: 'development', label: 'Development' },
			{ id: 'acc', slug: 'acceptance', label: 'Acceptance (1.1.0)' },
			{ id: 'prod', slug: 'production', label: 'Production' },
		])
	})

	it.each([
		[{ id: 'a', uuid: 'b', '@self': { id: 'c', uuid: 'd' } }, 'a'],
		[{ uuid: 'b', '@self': { id: 'c', uuid: 'd' } }, 'b'],
		[{ '@self': { id: 'c', uuid: 'd' } }, 'c'],
		[{ '@self': { uuid: 'd' } }, 'd'],
		[{ slug: 'x' }, ''],
	])('uses the row uuid of %j', async (row, id) => {
		const wrapper = await mountTab({ versions: [{ slug: 'x', ...row }] })
		expect(wrapper.vm.options[0].id).toBe(id)
	})

	it.each([
		['prod', 'prod'],
		[{ uuid: 'u', id: 'i' }, 'u'],
		[{ id: 'i' }, 'i'],
		[{}, ''],
		[null, ''],
	])('reads the production version %j as %j', async (pv, uuid) => {
		const wrapper = await mountTab({ productionVersion: pv })
		expect(wrapper.vm.productionUuid).toBe(uuid)
	})

	it('compares the version promoting to production against production', async () => {
		const wrapper = await mountTab()
		expect(wrapper.vm.toOption.id).toBe('prod')
		expect(wrapper.vm.fromOption.id).toBe('acc')
	})

	it('compares the selected version when the route names one', async () => {
		const wrapper = await mountTab({ query: { _version: 'development' } })
		expect(wrapper.vm.fromOption.id).toBe('dev')
		expect(wrapper.vm.toOption.id).toBe('prod')
	})

	it('ignores a selected version that is production itself', async () => {
		const wrapper = await mountTab({ query: { _version: 'production' } })
		expect(wrapper.vm.fromOption.id).toBe('acc')
	})

	it('falls back to the last option as production and the first other one as from', async () => {
		const wrapper = await mountTab({
			productionVersion: 'missing',
			versions: [
				{ id: 'a', slug: 'a' },
				{ id: 'b', slug: 'b' },
				{ id: 'c', slug: 'c' },
			],
		})
		expect(wrapper.vm.toOption.id).toBe('c')
		expect(wrapper.vm.fromOption.id).toBe('a')
	})

	it('picks nothing with fewer than two versions', async () => {
		const wrapper = await mountTab({ versions: [{ id: 'a', slug: 'a' }] })
		expect(wrapper.vm.fromOption).toBeNull()
		expect(wrapper.vm.toOption).toBeNull()
	})

	it.each([[{ results: [{ id: 'a' }, { id: 'b' }] }], [{}], [null]])(
		'reads the versions response %j',
		async (data) => {
			const wrapper = await mountTab({ versions: data })
			expect(wrapper.vm.versions).toEqual(
				data && data.results ? data.results : [],
			)
		},
	)

	it('has no versions when the request fails', async () => {
		axiosMock.get.mockRejectedValue(new Error('down'))
		const wrapper = shallowMount(ApplicationDiffTab, {
			props: { object: { uuid: 'app', slug: 'shop' } },
			global: { mocks: { $route: { query: {} } }, stubs: { ManifestDiff: true, NcSelect: true } },
		})
		await flushPromises()
		expect(wrapper.vm.versions).toEqual([])
		expect(wrapper.vm.loading).toBe(false)
	})
})
