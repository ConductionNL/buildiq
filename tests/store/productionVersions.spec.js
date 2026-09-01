/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Unit coverage for the production-version index behind the app cards'
 * status badge and version chip (REQ-OBR-007b).
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const get = vi.fn()

vi.mock('@nextcloud/axios', () => ({ default: { get: (...args) => get(...args) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => `/index.php${p}` }))

const {
	productionVersions,
	ensureProductionVersionsLoaded,
	resetProductionVersions,
} = await import('../../src/store/productionVersions.js')

const DETAIL = {
	uuid: 'v-1',
	slug: 'production',
	name: '1.0.0',
	semver: '1.0.0',
	status: 'published',
}

describe('productionVersions (REQ-OBR-007b)', () => {
	beforeEach(() => {
		resetProductionVersions()
		get.mockReset()
	})

	it("indexes the resolved detail by the Application's productionVersion UUID", async () => {
		get.mockResolvedValue({
			data: [
				{
					slug: 'hello-world',
					productionVersion: 'v-1',
					productionVersionDetail: DETAIL,
				},
				// An app with no production version must simply be absent, not null.
				{
					slug: 'opencatalogi',
					productionVersion: null,
					productionVersionDetail: null,
				},
			],
		})

		await ensureProductionVersionsLoaded()

		expect(productionVersions['v-1']).toEqual(DETAIL)
		expect(Object.keys(productionVersions)).toEqual(['v-1'])
	})

	it('issues ONE request no matter how many cards ask', async () => {
		get.mockResolvedValue({ data: [] })

		await Promise.all([
			ensureProductionVersionsLoaded(),
			ensureProductionVersionsLoaded(),
			ensureProductionVersionsLoaded(),
		])
		await ensureProductionVersionsLoaded()

		expect(
			get,
			'a grid of N cards must not issue N requests',
		).toHaveBeenCalledTimes(1)
	})

	it('reads the RBAC-filtered Buildiq endpoint, not the bulk OR version list', async () => {
		get.mockResolvedValue({ data: [] })
		await ensureProductionVersionsLoaded()

		const url = get.mock.calls[0][0]
		expect(url).toContain('/apps/buildiq/api/applications')
		// The OR objects endpoint would return every version row WITH its whole
		// manifest blob — 262 rows on the e2e instance — for five scalar fields.
		expect(url).not.toContain('/apps/openregister/')
	})

	it('accepts the enveloped {results:[...]} shape as well as a bare array', async () => {
		get.mockResolvedValue({
			data: {
				results: [
					{ productionVersion: 'v-1', productionVersionDetail: DETAIL },
				],
			},
		})
		await ensureProductionVersionsLoaded()
		expect(productionVersions['v-1']).toEqual(DETAIL)
	})

	it('leaves the map empty and does not throw when the lookup fails', async () => {
		get.mockRejectedValue(new Error('boom'))
		const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})

		await expect(ensureProductionVersionsLoaded()).resolves.toBeDefined()

		expect(Object.keys(productionVersions)).toHaveLength(0)
		// The failure must be visible: a silent catch here recreates exactly the
		// defect this module fixes — a card quietly reading "Draft" forever.
		expect(warn).toHaveBeenCalled()
		warn.mockRestore()
	})
})
