/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for services/appRegister.js: the app's register is read off
 * its ApplicationVersion, never rebuilt from the slug.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const getMock = vi.fn()
vi.mock('@nextcloud/axios', () => ({ default: { get: (...a) => getMock(...a) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))

const { fetchAppRegister, fetchProductionRegister, versionRegister } =
	await import('../../src/services/appRegister.js')

const VERSIONS = [
	{
		slug: 'development',
		register: 'openbuild-shop-development',
		'@self': { id: 'ver-dev' },
	},
	{
		slug: 'production',
		register: 'openbuild-shop-production',
		'@self': { id: 'ver-prod' },
	},
]

function route(table) {
	getMock.mockImplementation(async (url, options) => {
		const key = options && options.params ? `${url}?slug` : url
		if (!(key in table)) {
			throw Object.assign(new Error('404'), { response: { status: 404 } })
		}
		return { data: table[key] }
	})
}

describe('appRegister', () => {
	beforeEach(() => {
		getMock.mockReset()
	})

	it('reads the register off a version record', () => {
		expect(versionRegister(VERSIONS[0])).toBe('openbuild-shop-development')
		expect(versionRegister(null)).toBe('')
		expect(versionRegister({ register: 7 })).toBe('')
	})

	it('finds the production register by the version uuid', async () => {
		route({ '/apps/buildiq/api/applications/shop/versions': VERSIONS })
		await expect(fetchProductionRegister('shop', 'ver-prod')).resolves.toBe(
			'openbuild-shop-production',
		)
	})

	it('uses an embedded production version without a request', async () => {
		await expect(
			fetchProductionRegister('shop', { register: 'openbuild-shop-live' }),
		).resolves.toBe('openbuild-shop-live')
		expect(getMock).not.toHaveBeenCalled()
	})

	it('answers empty when the production version is unknown', async () => {
		route({ '/apps/buildiq/api/applications/shop/versions': VERSIONS })
		await expect(fetchProductionRegister('shop', 'ver-gone')).resolves.toBe('')
		await expect(fetchProductionRegister('shop', null)).resolves.toBe('')
	})

	it('resolves the named version when ?_version= is set', async () => {
		route({
			'/apps/buildiq/api/applications/shop/versions/development': VERSIONS[0],
		})
		await expect(fetchAppRegister('shop', 'development')).resolves.toBe(
			'openbuild-shop-development',
		)
	})

	it('resolves the production version when no version is named', async () => {
		route({
			'/apps/openregister/api/objects/buildiq/built-app?slug': {
				results: [{ slug: 'shop', productionVersion: 'ver-prod' }],
			},
			'/apps/buildiq/api/applications/shop/versions': VERSIONS,
		})
		await expect(fetchAppRegister('shop')).resolves.toBe(
			'openbuild-shop-production',
		)
	})

	it('never answers the bare openbuild-{slug} name', async () => {
		route({})
		await expect(fetchAppRegister('shop')).resolves.toBe('')
		await expect(fetchAppRegister('shop', 'development')).resolves.toBe('')
	})
})
